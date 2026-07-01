"use client"

import { Suspense } from "react"
import Link from "next/link"
import {
  Plus,
  Inbox,
  DollarSign,
  Users,
  CalendarPlus,
  FileWarning,
  CheckCircle2,
} from "lucide-react"
import { events, incomingRequests, organizerKpis } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { EventCard } from "@/components/marketplace/event-card"
import { IncomingRequestCard } from "@/components/marketplace/incoming-request-card"
import { LaunchChecklist } from "@/components/marketplace/launch-checklist"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"

const launchItems = [
  { label: "Set vendor fees and booth count", done: true },
  { label: "Publish opportunity categories", done: true },
  { label: "Upload venue packet (load-in map)", done: false },
  { label: "Connect payout account", done: false },
  { label: "Review pending vendor requests", done: false },
]

function OrganizerHomeContent() {
  const { state, retry } = usePanelState()
  const myEvents = events.filter((e) => e.organizer === "CultureWave Events")
  const pending = incomingRequests.filter((r) => r.status === "under-review")

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Organizer Command Center"
          subtitle="Launch events, fill vendor rows, and collect fees — all in one place."
          action={
            <Button nativeButton={false} render={<Link href="/organizer/events" />}>
              <Plus data-icon="inline-start" />
              New event
            </Button>
          }
        />

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
          <StatCard label="Active events" value={organizerKpis.activeEvents} hint="Accepting vendors" icon={CalendarPlus} accent />
          <StatCard label="Vendor requests" value={organizerKpis.vendorRequests} hint="Across your events" icon={Inbox} />
          <StatCard label="Approved" value={organizerKpis.approved} hint="Ready to pay" icon={CheckCircle2} />
          <StatCard label="Paid" value={organizerKpis.paid} hint="Booked vendors" icon={Users} />
          <StatCard label="Vendor revenue" value={`$${organizerKpis.vendorRevenue.toLocaleString()}`} hint="Collected fees" icon={DollarSign} />
          <StatCard label="Readiness score" value={`${organizerKpis.readinessScore}%`} hint="Avg. event readiness" icon={CalendarPlus} />
        </div>

        <LaunchChecklist title="Next event launch checklist" items={launchItems} />

        <section>
          <SectionHeader title="Needs attention" />
          <div className="flex flex-col gap-3">
            {pending.length > 0 && (
              <Alert>
                <FileWarning />
                <AlertTitle>{pending.length} vendor requests awaiting review</AlertTitle>
                <AlertDescription className="flex items-center justify-between gap-3">
                  <span>Respond within 48 hours to keep your fill rate high.</span>
                  <Button size="sm" nativeButton={false} render={<Link href="/organizer/vendors" />}>
                    Review
                  </Button>
                </AlertDescription>
              </Alert>
            )}
            <Alert>
              <FileWarning />
              <AlertTitle>Upload venue packet for Charlotte Night Market</AlertTitle>
              <AlertDescription className="flex items-center justify-between gap-3">
                <span>Paid vendors need load-in maps before event day.</span>
                <Button size="sm" variant="outline" nativeButton={false} render={<Link href="/organizer/events/charlotte-night-market" />}>
                  Add packet
                </Button>
              </AlertDescription>
            </Alert>
          </div>
        </section>

        <section>
          <SectionHeader title="Recent requests" href="/organizer/vendors" />
          {pending.length > 0 ? (
            <div className="flex flex-col gap-3">
              {pending.slice(0, 2).map((r) => (
                <IncomingRequestCard key={r.id} request={r} />
              ))}
            </div>
          ) : (
            <Card>
              <CardContent className="py-6 text-center text-sm text-muted-foreground">
                You&apos;re all caught up — no requests waiting.
              </CardContent>
            </Card>
          )}
        </section>

        <Card className="rounded-2xl bg-primary text-primary-foreground">
          <CardContent className="flex items-center gap-4 py-5">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent">
              <DollarSign className="size-5 text-accent-foreground" />
            </span>
            <div className="flex flex-1 flex-col">
              <span className="font-heading font-semibold">
                ${organizerKpis.vendorRevenue.toLocaleString()} in vendor fees collected
              </span>
              <span className="text-sm text-primary-foreground/70">
                Payouts clear 3 business days after each event ends. No Stripe jargon — just your earnings.
              </span>
            </div>
            <Button variant="secondary" size="sm" nativeButton={false} render={<Link href="/organizer/money" />}>
              View money
            </Button>
          </CardContent>
        </Card>

        <section>
          <SectionHeader title="Your events" href="/organizer/events" />
          <div className="grid gap-4 md:grid-cols-2">
            {myEvents.map((e) => (
              <EventCard key={e.id} event={e} />
            ))}
          </div>
        </section>
      </div>
    </PanelContent>
  )
}

export default function OrganizerHome() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerHomeContent />
    </Suspense>
  )
}
