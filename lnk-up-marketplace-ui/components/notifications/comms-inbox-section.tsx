"use client"

import { inboxTabLabel, type InboxTab } from "@/lib/api/comms"
import { useCommsInbox } from "@/lib/hooks/use-comms-inbox"
import { NotificationList } from "@/components/notifications/notification-list"
import { PanelContent } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"

/** Category tabs (Applications, Payments, Events, Opportunities/Operations) over the server inbox. */
export function CommsInboxSection({
  inbox,
}: {
  inbox: ReturnType<typeof useCommsInbox>
}) {
  const state = inbox.loading ? "loading" : inbox.error ? "error" : inbox.items.length === 0 ? "empty" : "content"
  return (
    <div className="flex flex-col gap-4" data-testid="comms-inbox">
      <Tabs value={inbox.tab} onValueChange={(v) => inbox.setTab(v as InboxTab)}>
        <TabsList className="max-w-full overflow-x-auto">
          {inbox.tabs.map((tab) => (
            <TabsTrigger key={tab} value={tab} data-testid={`comms-tab-${tab}`}>
              {inboxTabLabel(tab)}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
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
