import { hapticFeedback } from "@/lib/native/haptics"
/**
 * Cross-platform feedback: toasts, confirm sheets and text prompts with a promise API.
 * `Alert.alert` buttons and `Alert.prompt` do nothing on web and `Alert.prompt` is iOS-only, so
 * screens call these instead; FeedbackHost (mounted once in the root layout) renders them.
 */

export type ToastTone = "success" | "error" | "info"

export type ToastRequest = {
  id: number
  message: string
  tone: ToastTone
}

export type ConfirmRequest = {
  kind: "confirm"
  id: number
  title: string
  message?: string
  confirmLabel: string
  cancelLabel: string
  destructive: boolean
  resolve: (value: boolean) => void
}

export type PromptRequest = {
  kind: "prompt"
  id: number
  title: string
  message?: string
  placeholder?: string
  initialValue?: string
  confirmLabel: string
  cancelLabel: string
  minLength: number
  multiline: boolean
  resolve: (value: string | null) => void
}

export type DialogRequest = ConfirmRequest | PromptRequest

type Listener = () => void

let nextId = 1
let toasts: ToastRequest[] = []
let dialogQueue: DialogRequest[] = []
const listeners = new Set<Listener>()

function emit(): void {
  listeners.forEach((listener) => listener())
}

export function subscribeFeedback(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function getToasts(): ToastRequest[] {
  return toasts
}

export function getActiveDialog(): DialogRequest | null {
  return dialogQueue[0] ?? null
}

/** Short non-blocking message; errors stay a little longer. */
export function showToast(message: string, tone: ToastTone = "info"): void {
  const toast = { id: nextId++, message, tone }
  toasts = [...toasts.slice(-2), toast]
  emit()
  if (tone !== "info") hapticFeedback(tone)
  setTimeout(() => dismissToast(toast.id), tone === "error" ? 6000 : 3500)
}

export function dismissToast(id: number): void {
  const before = toasts.length
  toasts = toasts.filter((toast) => toast.id !== id)
  if (toasts.length !== before) emit()
}

/** Resolves true when the user confirms, false when they cancel or dismiss. */
export function confirmAction(options: {
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  destructive?: boolean
}): Promise<boolean> {
  return new Promise((resolve) => {
    dialogQueue = [
      ...dialogQueue,
      {
        kind: "confirm",
        id: nextId++,
        title: options.title,
        message: options.message,
        confirmLabel: options.confirmLabel ?? "Confirm",
        cancelLabel: options.cancelLabel ?? "Cancel",
        destructive: Boolean(options.destructive),
        resolve,
      },
    ]
    emit()
  })
}

/** Resolves the trimmed text, or null when cancelled. */
export function promptText(options: {
  title: string
  message?: string
  placeholder?: string
  initialValue?: string
  confirmLabel?: string
  cancelLabel?: string
  minLength?: number
  multiline?: boolean
}): Promise<string | null> {
  return new Promise((resolve) => {
    dialogQueue = [
      ...dialogQueue,
      {
        kind: "prompt",
        id: nextId++,
        title: options.title,
        message: options.message,
        placeholder: options.placeholder,
        initialValue: options.initialValue,
        confirmLabel: options.confirmLabel ?? "Send",
        cancelLabel: options.cancelLabel ?? "Cancel",
        minLength: options.minLength ?? 1,
        multiline: options.multiline ?? true,
        resolve,
      },
    ]
    emit()
  })
}

/** Called by the host when the active dialog closes. */
export function settleDialog(id: number, value: boolean | string | null): void {
  const dialog = dialogQueue.find((item) => item.id === id)
  if (!dialog) return
  dialogQueue = dialogQueue.filter((item) => item.id !== id)
  if (dialog.kind === "confirm") dialog.resolve(Boolean(value))
  else dialog.resolve(typeof value === "string" ? value : null)
  emit()
}
