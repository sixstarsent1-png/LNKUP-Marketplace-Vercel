import type { LnkupNotification } from "@/lib/notifications/types"
import * as SecureStore from "@/lib/storage/secure-store"

const STORAGE_PREFIX = "lnkup_notifications_read_v1"

function storageKey(userId: string | number): string {
  return `${STORAGE_PREFIX}_${userId}`
}

export async function getReadIds(userId?: string | number): Promise<Set<string>> {
  if (userId == null) return new Set()
  try {
    const raw = await SecureStore.getItemAsync(storageKey(userId))
    if (!raw) return new Set()
    const parsed = JSON.parse(raw) as Record<string, string>
    return new Set(Object.keys(parsed))
  } catch {
    return new Set()
  }
}

export async function markRead(userId: string | number, notificationId: string): Promise<void> {
  try {
    const key = storageKey(userId)
    const raw = await SecureStore.getItemAsync(key)
    const parsed = raw ? (JSON.parse(raw) as Record<string, string>) : {}
    parsed[notificationId] = new Date().toISOString()
    await SecureStore.setItemAsync(key, JSON.stringify(parsed))
  } catch {
    // ignore storage errors
  }
}

export async function markAllRead(
  userId: string | number,
  notificationIds: string[],
): Promise<void> {
  try {
    const key = storageKey(userId)
    const raw = await SecureStore.getItemAsync(key)
    const parsed = raw ? (JSON.parse(raw) as Record<string, string>) : {}
    const now = new Date().toISOString()
    for (const id of notificationIds) {
      parsed[id] = now
    }
    await SecureStore.setItemAsync(key, JSON.stringify(parsed))
  } catch {
    // ignore
  }
}

export async function mergeReadState(
  userId: string | number | undefined,
  notifications: LnkupNotification[],
): Promise<LnkupNotification[]> {
  if (userId == null) return notifications
  const readIds = await getReadIds(userId)
  return notifications.map((n) =>
    readIds.has(n.id) && !n.readAt ? { ...n, readAt: "read" } : n,
  )
}

export function getUnreadCount(notifications: LnkupNotification[]): number {
  return notifications.filter((n) => !n.readAt).length
}
