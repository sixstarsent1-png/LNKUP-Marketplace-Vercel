import { getItemAsync, setItemAsync } from "@/lib/storage/secure-store"
import type { TutorialRole } from "@/lib/tutorials/tutorial-contract"

const STORAGE_PREFIX = "lnkup_tutorial_progress_v1"

function storageKey(role: TutorialRole, userId?: string | number): string {
  const suffix = userId != null ? String(userId) : "guest"
  return `${STORAGE_PREFIX}:${role}:${suffix}`
}

export type TutorialProgressState = {
  dismissed: boolean
  viewedAt?: string
  popupSeen?: boolean
}

export async function getTutorialProgress(
  role: TutorialRole,
  userId?: string | number,
): Promise<TutorialProgressState> {
  try {
    const raw = await getItemAsync(storageKey(role, userId))
    if (!raw) return { dismissed: false }
    const parsed = JSON.parse(raw) as TutorialProgressState
    return {
      dismissed: Boolean(parsed.dismissed),
      viewedAt: parsed.viewedAt,
      popupSeen: Boolean(parsed.popupSeen),
    }
  } catch {
    return { dismissed: false }
  }
}

export async function setTutorialDismissed(
  role: TutorialRole,
  userId?: string | number,
): Promise<void> {
  try {
    const current = await getTutorialProgress(role, userId)
    await setItemAsync(
      storageKey(role, userId),
      JSON.stringify({ ...current, dismissed: true }),
    )
  } catch {
    // ignore storage errors
  }
}

export async function markTutorialViewed(
  role: TutorialRole,
  userId?: string | number,
): Promise<void> {
  try {
    const current = await getTutorialProgress(role, userId)
    if (current.viewedAt) return
    await setItemAsync(
      storageKey(role, userId),
      JSON.stringify({ ...current, viewedAt: new Date().toISOString() }),
    )
  } catch {
    // ignore storage errors
  }
}

export async function markTutorialPopupSeen(
  role: TutorialRole,
  userId?: string | number,
): Promise<void> {
  try {
    const current = await getTutorialProgress(role, userId)
    if (current.popupSeen) return
    await setItemAsync(
      storageKey(role, userId),
      JSON.stringify({ ...current, popupSeen: true }),
    )
  } catch {
    // ignore storage errors
  }
}
