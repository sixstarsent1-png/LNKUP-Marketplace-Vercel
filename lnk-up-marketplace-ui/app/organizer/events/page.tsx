"use client"

import { Suspense } from "react"
import { Plus, CalendarDays } from "lucide-react"
import { events } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { EventCard } from "@/components/marketplace/event-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

function OrganizerEventsContent() {
  const { state, retry } = usePanelState()
  const myEvents = events.filter((e) => e.organizer === "CultureWave Events" || e.organizer === "Southern Social Co.")
  const live = myEvents.filter((e) => e.status === "live")
  const closed = myEvents.filter((e) => e.status === "closed")

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Events"
          subtitle="Create and manage your live event listings."
          action={
            <Button>
              <Plus data-icon="inline-start" />
              New event
            </Button>
          }
        />

        <Tabs defaultValue="live">
          <TabsList>
            <TabsTrigger value="live">Live ({live.length})</TabsTrigger>
            <TabsTrigger value="draft">Drafts (0)</TabsTrigger>
            <TabsTrigger value="closed">Completed ({closed.length})</TabsTrigger>
          </TabsList>

          <TabsContent value="live" className="mt-4">
            <div className="grid gap-4 md:grid-cols-2">
              {live.map((e) => (
                <EventCard key={e.id} event={e} />
              ))}
            </div>
          </TabsContent>

          <TabsContent value="draft" className="mt-4">
            <EmptyState
              icon={CalendarDays}
              title="No drafts yet"
              description="Start a new event listing and save it as a draft to finish later."
              action={
                <Button>
                  <Plus data-icon="inline-start" />
                  Create event
                </Button>
              }
            />
          </TabsContent>

          <TabsContent value="closed" className="mt-4">
            <div className="grid gap-4 md:grid-cols-2">
              {closed.map((e) => (
                <EventCard key={e.id} event={e} />
              ))}
            </div>
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
