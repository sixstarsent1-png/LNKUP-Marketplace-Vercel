"use client"

import { Suspense, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { LogIn, Settings } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useNotifications, type NotificationFilter } from "@/lib/hooks/use-notifications"
import { useAuth } from "@/lib/hooks/use-auth"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { NotificationList } from "@/components/notifications/notification-list"
import { WordPressSettingsHandoff } from "@/components/notifications/wordpress-settings-handoff"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

function NotificationsContent() {
  const live = isLiveApiEnabled()
  const { user, isAuthenticated } = useAuth()
  const handoffRole =
    user?.canAccessOrganizer && !user?.canAccessVendor ? "organizer" : "vendor"
  const [filter, setFilter] = useState<NotificationFilter>("all")
  const { items, loading, error, unauthenticated, retry, markRead, markAllRead, filterItems } =
    useNotifications()

  const filtered = useMemo(() => filterItems(filter), [filterItems, filter])

  useEffect(() => {
    trackLnkupEvent({ event: "notifications_viewed", source: "notifications_page" })
  }, [])

  const panelState = loading ? "loading" : error ? "error" : filtered.length === 0 ? "empty" : "content"

  if (live && unauthenticated) {
    return (
      <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed p-8 text-center">
        <LogIn className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">Sign in to view notifications.</p>
        <Button nativeButton={false} render={<Link href="/login?redirect=/notifications" />}>
          Sign in
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-5" data-testid="notifications-center">
      <PageHeader
        title="Notifications"
        subtitle="Application updates, payments, and event reminders."
        action={
          <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/notifications/preferences" />}>
            <Settings data-icon="inline-start" className="size-4" />
            Preferences
          </Button>
        }
      />

      <Tabs value={filter} onValueChange={(v) => setFilter(v as NotificationFilter)}>
        <TabsList>
          <TabsTrigger value="all">All</TabsTrigger>
          <TabsTrigger value="unread" data-testid="notifications-filter-unread">
            Unread
          </TabsTrigger>
          <TabsTrigger value="action_required" data-testid="notifications-filter-action">
            Action required
          </TabsTrigger>
        </TabsList>

        {(["all", "unread", "action_required"] as NotificationFilter[]).map((tab) => (
          <TabsContent key={tab} value={tab} className="mt-4">
            <PanelContent state={panelState} retry={retry}>
              {filtered.length > 0 && tab === filter && (
                <div className="mb-3 flex justify-end">
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      trackLnkupEvent({ event: "notification_mark_all_read", source: "notifications_page" })
                      markAllRead()
                    }}
                    data-testid="notifications-mark-all-read"
                  >
                    Mark all read
                  </Button>
                </div>
              )}
              <NotificationList
                items={tab === filter ? filtered : filterItems(tab)}
                onMarkRead={markRead}
                emptyMessage={tab === "unread" ? "No unread notifications." : tab === "action_required" ? "No action required right now." : undefined}
              />
            </PanelContent>
          </TabsContent>
        ))}
      </Tabs>

      {live && isAuthenticated && (
        <WordPressSettingsHandoff role={handoffRole} />
      )}
    </div>
  )
}

export default function NotificationsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <NotificationsContent />
    </Suspense>
  )
}
