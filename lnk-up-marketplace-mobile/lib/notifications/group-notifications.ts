import type { LnkupNotification } from "@/lib/notifications/types"

/** A notification row that may stand for several repeated notifications. */
export type NotificationRow = LnkupNotification & {
  /** Every notification id in the row (just `id` when not grouped). */
  memberIds: string[]
  /** Unread notifications in the row. */
  unreadCount: number
}

/**
 * Collapse repeats of the same reminder (same type and title, e.g. "Payment past due") into one
 * row so history stays readable. Distinct records with different titles are never merged, and
 * the newest member stays the one that opens.
 */
export function groupRepeatedNotifications(items: LnkupNotification[]): NotificationRow[] {
  const rows: NotificationRow[] = []
  const byKey = new Map<string, NotificationRow>()
  for (const item of items) {
    const key = `${item.type}::${item.title.trim().toLowerCase()}::${item.applicationId ?? item.requestId ?? ""}`
    const existing = byKey.get(key)
    if (existing) {
      existing.memberIds.push(item.id)
      if (!item.readAt) existing.unreadCount += 1
      continue
    }
    const row: NotificationRow = { ...item, memberIds: [item.id], unreadCount: item.readAt ? 0 : 1 }
    byKey.set(key, row)
    rows.push(row)
  }
  return rows
}
