"use client"

import Link from "next/link"
import { ChevronRight } from "lucide-react"
import type { LnkupNotification } from "@/lib/api/notifications"
import { notificationTypeLabel } from "@/lib/api/notifications"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { cn } from "@/lib/utils"

function severityVariant(severity?: LnkupNotification["severity"]) {
  switch (severity) {
    case "urgent":
      return "destructive"
    case "warning":
      return "secondary"
    case "success":
      return "default"
    default:
      return "outline"
  }
}

export function NotificationItem({
  notification,
  onMarkRead,
  onClick,
}: {
  notification: LnkupNotification
  onMarkRead?: (id: string) => void
  onClick?: (notification: LnkupNotification) => void
}) {
  const isUnread = !notification.readAt

  function handleClick() {
    trackLnkupEvent({
      event: "notification_clicked",
      source: "notification_item",
      requestId: notification.requestId,
      metadata: { type: notification.type, severity: notification.severity ?? "info" },
    })
    if (isUnread) onMarkRead?.(notification.id)
    onClick?.(notification)
  }

  return (
    <Card
      className={cn("rounded-2xl", isUnread && "border-accent/40 bg-accent/5")}
      data-testid={`notification-item-${notification.id}`}
    >
      <CardContent className="flex items-start gap-3 py-4">
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={severityVariant(notification.severity)} className="text-[10px]">
              {notificationTypeLabel(notification.type)}
            </Badge>
            {isUnread && (
              <span className="size-1.5 rounded-full bg-accent" aria-hidden />
            )}
          </div>
          <p className="text-sm font-semibold">{notification.title}</p>
          {notification.message && (
            <p className="text-xs text-muted-foreground">{notification.message}</p>
          )}
          {notification.createdAt && (
            <p className="text-xs text-muted-foreground">{notification.createdAt}</p>
          )}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-2">
          {notification.href && (
            <Button
              size="sm"
              variant="outline"
              nativeButton={false}
              render={
                notification.href.startsWith("/") ? (
                  <Link href={notification.href} onClick={handleClick} />
                ) : (
                  <a href={notification.href} target="_blank" rel="noopener noreferrer" onClick={handleClick} />
                )
              }
            >
              {notification.actionLabel ?? "View"}
              <ChevronRight data-icon="inline-end" className="size-3" />
            </Button>
          )}
          {isUnread && onMarkRead && (
            <Button
              size="sm"
              variant="ghost"
              className="text-xs"
              onClick={() => {
                trackLnkupEvent({
                  event: "notification_marked_read",
                  source: "notification_item",
                  metadata: { type: notification.type },
                })
                onMarkRead(notification.id)
              }}
            >
              Mark read
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
