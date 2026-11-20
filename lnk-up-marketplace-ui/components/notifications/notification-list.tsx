"use client"

import type { LnkupNotification } from "@/lib/api/notifications"
import { NotificationItem } from "@/components/notifications/notification-item"
import { NotificationEmpty } from "@/components/notifications/notification-empty"

export function NotificationList({
  items,
  onMarkRead,
  emptyMessage,
}: {
  items: LnkupNotification[]
  onMarkRead?: (id: string) => void
  emptyMessage?: string
}) {
  if (items.length === 0) {
    if (emptyMessage) {
      return (
        <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground" data-testid="notifications-empty">
          {emptyMessage}
        </p>
      )
    }
    return <NotificationEmpty />
  }

  return (
    <div className="flex flex-col gap-2" data-testid="notification-list">
      {items.map((notification) => (
        <NotificationItem key={notification.id} notification={notification} onMarkRead={onMarkRead} />
      ))}
    </div>
  )
}
