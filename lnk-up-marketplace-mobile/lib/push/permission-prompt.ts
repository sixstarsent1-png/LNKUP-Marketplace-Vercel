import * as SecureStore from "@/lib/storage/secure-store"

import type { PushPermissionState } from "@/lib/push/notifications"

const KEY = "lnkup_push_prompt_v1"
const RESHOW_AFTER_MS = 30 * 24 * 60 * 60 * 1000
const MAX_DISMISSALS = 2

export type PromptRecord = { dismissedAt: number; count: number }

/**
 * Show the in-app explainer only while the OS has not decided yet, never after an OS denial,
 * and at most twice (30 days apart) after "Not now".
 */
export function shouldShowPushPrompt(state: PushPermissionState, record: PromptRecord | null, now: number): boolean {
  if (state !== "undetermined") return false
  if (!record) return true
  if (record.count >= MAX_DISMISSALS) return false
  return now - record.dismissedAt >= RESHOW_AFTER_MS
}

export async function loadPromptRecord(): Promise<PromptRecord | null> {
  try {
    const raw = await SecureStore.getItemAsync(KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<PromptRecord>
    return typeof parsed.dismissedAt === "number" && typeof parsed.count === "number"
      ? { dismissedAt: parsed.dismissedAt, count: parsed.count }
      : null
  } catch {
    return null
  }
}

export async function recordPromptDismissed(previous: PromptRecord | null, now: number): Promise<void> {
  const next: PromptRecord = { dismissedAt: now, count: (previous?.count ?? 0) + 1 }
  await SecureStore.setItemAsync(KEY, JSON.stringify(next)).catch(() => undefined)
}
