"use client"

import Link from "next/link"
import { inboxTabLabel, type InboxTab } from "@/lib/api/comms"
import { useCommsInbox } from "@/lib/hooks/use-comms-inbox"
import { NotificationList } from "@/components/notifications/notification-list"
import { PanelContent } from "@/components/marketplace/states"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"

/** Category tabs (with unread counts) over the server inbox, plus mark-all for the current tab. */
export function CommsInboxSection({
  inbox,
}: {
  inbox: ReturnType<typeof useCommsInbox>
}) {
  const state = inbox.loading ? "loading" : inbox.error ? "error" : inbox.items.length === 0 ? "empty" : "content"
  return (
    <div className="flex flex-col gap-4" data-testid="comms-inbox">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Tabs value={inbox.tab} onValueChange={(v) => inbox.setTab(v as InboxTab)}>
          <TabsList className="max-w-full overflow-x-auto">
            {inbox.tabs.map((tab) => (
              <TabsTrigger key={tab} value={tab} data-testid={`comms-tab-${tab}`}>
                {inboxTabLabel(tab)}
                {inbox.counts[tab] ? (
                  <Badge variant="secondary" className="ml-1.5" aria-label={`${inbox.counts[tab]} unread`}>
                    {inbox.counts[tab]}
                  </Badge>
                ) : null}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <Button
          variant="ghost"
          size="sm"
          onClick={inbox.markAll}
          disabled={inbox.markingAll || inbox.unreadInTab === 0}
          data-testid="comms-mark-all"
        >
          Mark all as read
        </Button>
      </div>
      {inbox.tab === "invitations" && inbox.role === "vendor" ? (
        <Link
          href="/vendor/invitations"
          className="rounded-xl border border-border p-4 text-sm font-semibold hover:bg-muted"
          data-testid="comms-open-invitations"
        >
          Open invitations →<span className="block font-normal text-muted-foreground">Accept or decline invitations from organizers</span>
        </Link>
      ) : null}
      <PanelContent state={state} retry={inbox.retry}>
        <NotificationList items={inbox.items} onMarkRead={inbox.markRead} />
      </PanelContent>
      {inbox.hasMore && !inbox.loading ? (
        <Button variant="outline" size="sm" className="self-center" onClick={inbox.loadMore}>
          Load more
        </Button>
      ) : null}
    </div>
  )
}
