"use client"

import { Suspense, useMemo } from "react"
import Link from "next/link"
import { Plus, CalendarDays, ExternalLink } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { getOrganizerDashboardUrl } from "@/lib/api/config"
import { useOrganizerEvents } from "@/lib/hooks/use-organizer-events"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { OrganizerEventCard } from "@/components/organizer/organizer-event-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

function OrganizerEventsContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, error, retry } = useOrganizerEvents()
  const wpDashboard = getOrganizerDashboardUrl()

  const qaOverride = qaState !== "content"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (items.length === 0) return "empty"
    return "content"
  }, [qaState, live, loading, error, items.length])

  const liveEvents = items.filter((e) => e.status === "live" || !e.status)
  const closedEvents = items.filter((e) => e.status === "closed")
  const draftEvents = items.filter((e) => e.status === "draft")

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  return (
    <PanelContent
      state={panelState}
      retry={handleRetry}
      empty={
        <EmptyState
          icon={CalendarDays}
          title="No events yet"
          description="Your events will appear here once you launch your first vendor opportunity."
          action={
            <div className="flex flex-wrap justify-center gap-2">
              <Button nativeButton={false} render={<Link href="/organizer/launch" />}>
                Launch event
              </Button>
              {wpDashboard && (
                <Button
                  variant="outline"
                  nativeButton={false}
                  render={<Link href={wpDashboard} target="_blank" rel="noopener noreferrer" />}
                >
                  Open LNKup dashboard
                </Button>
              )}
            </div>
          }
        />
      }
    >
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Events"
          subtitle="Manage your live event listings and vendor opportunities."
          action={
            <Button nativeButton={false} render={<Link href="/organizer/launch" />}>
              <Plus data-icon="inline-start" />
              Launch event
            </Button>
          }
        />

        <Tabs defaultValue="live">
          <TabsList>
            <TabsTrigger value="live">Live ({liveEvents.length})</TabsTrigger>
            <TabsTrigger value="draft">Drafts ({draftEvents.length})</TabsTrigger>
            <TabsTrigger value="closed">Completed ({closedEvents.length})</TabsTrigger>
          </TabsList>

          <TabsContent value="live" className="mt-4">
            {!qaOverride && liveEvents.length > 0 ? (
              <div className="grid gap-4 md:grid-cols-2">
                {liveEvents.map((e) => (
                  <OrganizerEventCard key={e.id} event={e} />
                ))}
              </div>
            ) : (
              <EmptyState
                icon={CalendarDays}
                title="No live events"
                description="Your events will appear here once you launch your first vendor opportunity."
              />
            )}
          </TabsContent>

          <TabsContent value="draft" className="mt-4">
            {draftEvents.length > 0 ? (
              <div className="grid gap-4 md:grid-cols-2">
                {draftEvents.map((e) => (
                  <OrganizerEventCard key={e.id} event={e} />
                ))}
              </div>
            ) : (
              <EmptyState
                icon={CalendarDays}
                title="No drafts yet"
                description="Create events on LNKup web and save them as drafts to finish later."
              />
            )}
          </TabsContent>

          <TabsContent value="closed" className="mt-4">
            {closedEvents.length > 0 ? (
              <div className="grid gap-4 md:grid-cols-2">
                {closedEvents.map((e) => (
                  <OrganizerEventCard key={e.id} event={e} />
                ))}
              </div>
            ) : (
              <EmptyState
                icon={CalendarDays}
                title="No completed events"
                description="Completed events will appear here after they close."
              />
            )}
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

export default function OrganizerEventsPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerEventsContent />
    </Suspense>
  )
}
