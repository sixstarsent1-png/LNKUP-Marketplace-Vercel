"use client"

import { Suspense, use } from "react"
import Image from "next/image"
import Link from "next/link"
import { notFound } from "next/navigation"
import {
  Calendar,
  MapPin,
  Users,
  DollarSign,
  ArrowLeft,
  Share2,
  Store,
  Car,
  Clock,
  BarChart3,
  ClipboardList,
  Package,
  Settings2,
} from "lucide-react"
import { events, incomingRequests } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { StatCard } from "@/components/marketplace/stat-card"
import { StatusBadge, VerificationBadge } from "@/components/marketplace/badges"
import { IncomingRequestCard } from "@/components/marketplace/incoming-request-card"
import { ProgressRing } from "@/components/marketplace/progress-ring"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Progress } from "@/components/ui/progress"

function EventDetailContent({ eventId }: { eventId: string }) {
  const { state, retry } = usePanelState()
  const event = events.find((e) => e.id === eventId)
  if (!event) notFound()

  const fillRate = Math.round((event.approvedVendors / event.totalSpots) * 100)
  const conversionRate = Math.round((event.paidVendors / event.vendorRequests) * 100)

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <Button variant="ghost" size="sm" className="w-fit -ml-2" nativeButton={false} render={<Link href="/organizer/events" />}>
          <ArrowLeft data-icon="inline-start" />
          Back to events
        </Button>

        <Card className="gap-0 overflow-hidden rounded-2xl p-0">
          <div className="relative h-40 w-full md:h-52">
            <Image src={event.image || "/placeholder.svg"} alt={event.name} fill className="object-cover" />
            <div className="absolute top-3 left-3 flex gap-2">
              <StatusBadge variant={event.status}>{event.status === "live" ? "Live" : "Completed"}</StatusBadge>
              {event.organizerVerified && <VerificationBadge label="Verified organizer" />}
            </div>
          </div>
          <div className="flex flex-col gap-3 p-4 md:p-6">
            <div className="flex items-start justify-between gap-3">
              <div className="flex flex-col gap-1">
                <h1 className="text-balance font-heading text-xl font-bold md:text-2xl">{event.name}</h1>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                  <span className="inline-flex items-center gap-1">
                    <Calendar className="size-4" />
                    {event.date}
                  </span>
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="size-4" />
                    {event.venue}, {event.city}
                  </span>
                  <span className="inline-flex items-center gap-1">
                    <Users className="size-4" />
                    {event.expectedAttendance.toLocaleString()} expected
                  </span>
                </div>
              </div>
              <Button variant="outline" size="icon" aria-label="Share event">
                <Share2 />
              </Button>
            </div>
          </div>
        </Card>

        <Tabs defaultValue="overview">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList className="w-max">
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="requests">Requests</TabsTrigger>
              <TabsTrigger value="opportunities">Opportunities</TabsTrigger>
              <TabsTrigger value="packet">Packet</TabsTrigger>
              <TabsTrigger value="operations">Operations</TabsTrigger>
              <TabsTrigger value="intelligence">Intelligence</TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="overview" className="mt-4 flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <StatCard label="Vendor revenue" value={`$${event.revenue.toLocaleString()}`} icon={DollarSign} accent />
              <StatCard label="Requests" value={event.vendorRequests} icon={ClipboardList} />
              <StatCard label="Approved" value={event.approvedVendors} icon={Store} />
              <StatCard label="Paid" value={event.paidVendors} icon={Users} />
            </div>

            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Event readiness</CardTitle>
              </CardHeader>
              <CardContent className="flex items-center gap-5">
                <ProgressRing value={event.readiness} size={80} />
                <div className="flex flex-1 flex-col gap-3">
                  <div className="flex flex-col gap-1">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Booth fill rate</span>
                      <span className="font-semibold">
                        {event.approvedVendors}/{event.totalSpots}
                      </span>
                    </div>
                    <Progress value={fillRate} />
                  </div>
                  <p className="text-sm text-muted-foreground">
                    {event.spotsRemaining > 0
                      ? `${event.spotsRemaining} spots still open — promote your listing to fill them.`
                      : "All booths are filled. Great work!"}
                  </p>
                </div>
              </CardContent>
            </Card>

            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Categories needed</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {event.categoriesNeeded.map((c) => (
                  <Badge key={c} variant="secondary">
                    {c}
                  </Badge>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="requests" className="mt-4 flex flex-col gap-3">
            {incomingRequests.map((r) => (
              <IncomingRequestCard key={r.id} request={r} />
            ))}
          </TabsContent>

          <TabsContent value="opportunities" className="mt-4 flex flex-col gap-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Open vendor opportunities</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {event.categoriesNeeded.map((cat) => (
                  <div key={cat} className="flex items-center justify-between gap-3 rounded-xl border p-3">
                    <div className="flex flex-col">
                      <span className="text-sm font-semibold">{cat}</span>
                      <span className="text-xs text-muted-foreground">
                        ${event.vendorFee} fee · {event.spotsRemaining} spots left
                      </span>
                    </div>
                    <Badge variant="secondary">Accepting</Badge>
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="packet" className="mt-4 flex flex-col gap-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Venue packet</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3 text-sm">
                <PacketRow icon={Clock} label="Load-in window" value="2:00 PM – 3:30 PM" />
                <Separator />
                <PacketRow icon={Car} label="Vendor parking" value="Lot C — enter via 5th St gate" />
                <Separator />
                <PacketRow icon={MapPin} label="Booth layout" value="Rows A–D, power on north side" />
                <Separator />
                <PacketRow icon={Package} label="Vendor rules" value="Quiet generators, 10x10 max, no early breakdown" />
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="operations" className="mt-4 flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3">
              <StatCard label="Check-in ready" value={`${event.paidVendors}/${event.approvedVendors}`} hint="Paid vendors" icon={Users} />
              <StatCard label="Readiness" value={`${event.readiness}%`} hint="Ops score" icon={Settings2} accent />
            </div>
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Day-of checklist</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2 text-sm text-muted-foreground">
                <p>· Vendor QR check-in opens 90 minutes before load-in</p>
                <p>· Ops lead on-site: (704) 555-0142</p>
                <p>· Weather contingency: indoor overflow in Building B</p>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="intelligence" className="mt-4 flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
              <StatCard label="Apply → approve" value="55%" hint="Last 30 days" icon={BarChart3} />
              <StatCard label="Approve → paid" value={`${conversionRate}%`} hint="This event" icon={DollarSign} accent />
              <StatCard label="Avg. vendor rating" value="4.8" hint="Booked vendors" icon={Users} />
            </div>
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Category demand</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {event.categoriesNeeded.slice(0, 3).map((cat, i) => (
                  <div key={cat} className="flex flex-col gap-1">
                    <div className="flex justify-between text-sm">
                      <span>{cat}</span>
                      <span className="text-muted-foreground">{12 - i * 3} requests</span>
                    </div>
                    <Progress value={85 - i * 15} />
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

function PacketRow({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Clock
  label: string
  value: string
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent">
        <Icon className="size-4 text-accent-foreground" />
      </span>
      <div className="flex flex-col">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="font-medium">{value}</span>
      </div>
    </div>
  )
}

export default function EventDetailPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params)

  return (
    <Suspense fallback={<SkeletonList count={1} />}>
      <EventDetailContent eventId={eventId} />
    </Suspense>
  )
}
