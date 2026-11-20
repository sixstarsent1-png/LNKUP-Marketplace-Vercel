const STORAGE_PREFIX = "lnkup_notifications_read_v1"

function storageKey(userId: string | number): string {
  return `${STORAGE_PREFIX}:${userId}`
}

export function getReadIds(userId?: string | number): Set<string> {
  if (typeof window === "undefined" || userId == null) return new Set()
  try {
    const raw = localStorage.getItem(storageKey(userId))
    if (!raw) return new Set()
    const parsed = JSON.parse(raw) as Record<string, string>
    return new Set(Object.keys(parsed))
  } catch {
    return new Set()
  }
}

export function markRead(userId: string | number, notificationId: string): void {
  if (typeof window === "undefined") return
  try {
    const key = storageKey(userId)
    const raw = localStorage.getItem(key)
    const parsed = raw ? (JSON.parse(raw) as Record<string, string>) : {}
    parsed[notificationId] = new Date().toISOString()
    localStorage.setItem(key, JSON.stringify(parsed))
  } catch {
    // ignore storage errors
  }
}

export function markAllRead(userId: string | number, notificationIds: string[]): void {
  if (typeof window === "undefined") return
  try {
    const key = storageKey(userId)
    const raw = localStorage.getItem(key)
    const parsed = raw ? (JSON.parse(raw) as Record<string, string>) : {}
    const now = new Date().toISOString()
    for (const id of notificationIds) {
      parsed[id] = now
    }
    localStorage.setItem(key, JSON.stringify(parsed))
  } catch {
    // ignore
  }
}

export function getUnreadCount(
  userId: string | number | undefined,
  notifications: Array<{ id: string; readAt?: string }>,
): number {
  if (!userId) return notifications.filter((n) => !n.readAt).length
  const readIds = getReadIds(userId)
  return notifications.filter((n) => !n.readAt && !readIds.has(n.id)).length
}

export function mergeReadState<T extends { id: string; readAt?: string }>(
  userId: string | number | undefined,
  notifications: T[],
): T[] {
  if (!userId) return notifications
  const readIds = getReadIds(userId)
  return notifications.map((n) =>
    readIds.has(n.id) && !n.readAt ? { ...n, readAt: readIds.has(n.id) ? "read" : undefined } : n,
  )
}
