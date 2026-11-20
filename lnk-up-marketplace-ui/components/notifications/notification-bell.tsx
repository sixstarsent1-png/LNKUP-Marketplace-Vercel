"use client"

import Link from "next/link"
import { Bell } from "lucide-react"
import { useNotificationCount } from "@/lib/hooks/use-notification-count"
import { useAuth } from "@/lib/hooks/use-auth"
import { isLiveApiEnabled, getVendorLoginUrl } from "@/lib/api/config"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

export function NotificationBell({ className }: { className?: string }) {
  const live = isLiveApiEnabled()
  const { isAuthenticated } = useAuth()
  const { count } = useNotificationCount(live && isAuthenticated)

  if (live && !isAuthenticated) {
    return null
  }

  const unread = count.unread

  return (
    <Button
      variant="outline"
      size="icon"
      className={cn("relative min-h-11 min-w-11", className)}
      aria-label={unread > 0 ? `${unread} unread notifications` : "Notifications"}
      nativeButton={false}
      render={
        live && !isAuthenticated ? (
          <Link href={getVendorLoginUrl({ redirect: "/notifications" })} />
        ) : (
          <Link href="/notifications" data-testid="notification-bell" />
        )
      }
    >
      <Bell />
      {unread > 0 && (
        <span
          className="absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-accent text-[10px] font-bold text-accent-foreground"
          data-testid="notification-badge"
        >
          {unread > 9 ? "9+" : unread}
        </span>
      )}
    </Button>
  )
}
