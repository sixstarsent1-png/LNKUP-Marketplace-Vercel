import type { TutorialRole } from "@/lib/tutorials/tutorial-contract"

const STORAGE_PREFIX = "lnkup_tutorial_progress_v1"

function storageKey(role: TutorialRole, userId?: string | number): string {
  const suffix = userId != null ? String(userId) : "guest"
  return `${STORAGE_PREFIX}:${role}:${suffix}`
}

export type TutorialProgressState = {
  dismissed: boolean
  viewedAt?: string
}

export function getTutorialProgress(
  role: TutorialRole,
  userId?: string | number,
): TutorialProgressState {
  if (typeof window === "undefined") return { dismissed: false }
  try {
    const raw = localStorage.getItem(storageKey(role, userId))
    if (!raw) return { dismissed: false }
    const parsed = JSON.parse(raw) as TutorialProgressState
    return { dismissed: Boolean(parsed.dismissed), viewedAt: parsed.viewedAt }
  } catch {
    return { dismissed: false }
  }
}

export function setTutorialDismissed(role: TutorialRole, userId?: string | number): void {
  if (typeof window === "undefined") return
  try {
    const key = storageKey(role, userId)
    const current = getTutorialProgress(role, userId)
    localStorage.setItem(
      key,
      JSON.stringify({ ...current, dismissed: true, viewedAt: current.viewedAt }),
    )
  } catch {
    // ignore storage errors
  }
}

export function markTutorialViewed(role: TutorialRole, userId?: string | number): void {
  if (typeof window === "undefined") return
  try {
    const key = storageKey(role, userId)
    const current = getTutorialProgress(role, userId)
    if (current.viewedAt) return
    localStorage.setItem(
      key,
      JSON.stringify({ ...current, viewedAt: new Date().toISOString() }),
    )
  } catch {
    // ignore storage errors
  }
}
