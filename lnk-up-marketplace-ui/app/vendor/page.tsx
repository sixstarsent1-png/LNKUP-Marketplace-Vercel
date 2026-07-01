"use client"

import { Suspense } from "react"
import Link from "next/link"
import {
  Search,
  Inbox,
  Wallet,
  FileWarning,
  Calendar,
  MapPin,
  ArrowRight,
  Star,
  TrendingUp,
  Ticket,
  Send,
  CheckCircle2,
  DollarSign,
} from "lucide-react"
import { events, vendorRequests, currentVendor, vendorKpis } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { ProgressRing } from "@/components/marketplace/progress-ring"
import { StatCard } from "@/components/marketplace/stat-card"
import { StatusBadge } from "@/components/marketplace/badges"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Progress } from "@/components/ui/progress"

const quickActions = [
  { label: "Find events", href: "/vendor/find", icon: Search },
  { label: "Requests", href: "/vendor/requests", icon: Inbox },
  { label: "Wallet", href: "/vendor/wallet", icon: Wallet },
]

function VendorHomeContent() {
  const { state, retry } = usePanelState()
  const recommended = events.filter((e) => e.status === "live").slice(0, 2)
  const booked = vendorRequests.filter((r) => r.status === "paid")
  const paymentDue = vendorRequests.find((r) => r.status === "payment-due")
  const paidPass = booked[0]

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <div className="flex flex-col gap-1">
          <PageHeader
            title={`Welcome back, ${currentVendor.name}`}
            subtitle="Your next opportunity is waiting."
          />
        </div>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard label="Applications" value={vendorKpis.applicationsSubmitted} icon={Send} />
          <StatCard label="Approved" value={vendorKpis.approvedBookings} icon={CheckCircle2} />
          <StatCard label="Payments due" value={vendorKpis.paymentsDue} icon={DollarSign} accent={vendorKpis.paymentsDue > 0} />
          <StatCard label="Completed events" value={vendorKpis.completedEvents} icon={Calendar} />
          <StatCard label="Avg. rating" value={vendorKpis.averageRating} icon={Star} />
        </div>

        <Card className="rounded-2xl">
          <CardContent className="flex items-center gap-4 p-4">
            <ProgressRing value={vendorKpis.profileStrength} size={72} />
            <div className="flex flex-1 flex-col gap-2">
              <span className="font-heading font-semibold">Profile strength</span>
              <p className="text-sm text-muted-foreground">
                Add your health permit and insurance docs to get approved faster.
              </p>
              <Progress value={vendorKpis.profileStrength} className="h-2" />
              <Button size="sm" variant="outline" className="w-fit" nativeButton={false} render={<Link href="/vendor/profile" />}>
                Complete profile
              </Button>
            </div>
          </CardContent>
        </Card>

        <div className="grid grid-cols-3 gap-3">
          {quickActions.map((a) => (
            <Button
              key={a.href}
              variant="outline"
              className="h-auto flex-col gap-2 rounded-2xl py-4"
              nativeButton={false}
              render={<Link href={a.href} />}
            >
              <a.icon className="size-5" />
              <span className="text-xs font-medium">{a.label}</span>
            </Button>
          ))}
        </div>

        <section>
          <SectionHeader title="Needs attention" />
          <div className="flex flex-col gap-3">
            {paymentDue && (
              <Alert>
                <FileWarning />
                <AlertTitle>Payment due for {paymentDue.eventName}</AlertTitle>
                <AlertDescription className="flex items-center justify-between gap-3">
                  <span>Secure your spot — ${paymentDue.vendorFee} due before the deadline.</span>
                  <Button size="sm" nativeButton={false} render={<Link href="/vendor/wallet" />}>
                    Pay now
                  </Button>
                </AlertDescription>
              </Alert>
            )}
            <Alert>
              <FileWarning />
              <AlertTitle>Add your health permit</AlertTitle>
              <AlertDescription className="flex items-center justify-between gap-3">
                <span>Verified vendors get approved 2x faster.</span>
                <Button size="sm" variant="outline" nativeButton={false} render={<Link href="/vendor/profile" />}>
                  Upload
                </Button>
              </AlertDescription>
            </Alert>
            {paidPass && (
              <Alert>
                <Ticket />
                <AlertTitle>Event pass unlocked — {paidPass.eventName}</AlertTitle>
                <AlertDescription className="flex items-center justify-between gap-3">
                  <span>Your vendor pass is ready with load-in details and QR check-in.</span>
                  <Button size="sm" nativeButton={false} render={<Link href={`/vendor/pass/${paidPass.eventId}`} />}>
                    View pass
                  </Button>
                </AlertDescription>
              </Alert>
            )}
          </div>
        </section>

        <section>
          <SectionHeader title="Recommended for you" href="/vendor/find" />
          <div className="grid gap-4 md:grid-cols-2">
            {recommended.map((e) => (
              <OpportunityCard key={e.id} event={e} />
            ))}
          </div>
        </section>

        <section>
          <SectionHeader title="Upcoming booked events" href="/vendor/requests" />
          {booked.length > 0 ? (
            <div className="flex flex-col gap-3">
              {booked.map((r) => (
                <Card key={r.id} className="flex-row items-center gap-3 rounded-2xl p-4">
                  <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-accent">
                    <Star className="size-5 text-accent-foreground" />
                  </div>
                  <CardContent className="flex flex-1 flex-col gap-1 p-0">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-semibold">{r.eventName}</span>
                      <StatusBadge variant="paid">Booked</StatusBadge>
                    </div>
                    <div className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                      <span className="inline-flex items-center gap-1">
                        <Calendar className="size-3" />
                        {r.date}
                      </span>
                      <span className="inline-flex items-center gap-1">
                        <MapPin className="size-3" />
                        {r.city}
                      </span>
                    </div>
                  </CardContent>
                  <Button variant="ghost" size="icon" nativeButton={false} render={<Link href={`/vendor/pass/${r.eventId}`} aria-label="View pass" />}>
                    <ArrowRight />
                  </Button>
                </Card>
              ))}
            </div>
          ) : null}
        </section>

        <section>
          <SectionHeader title="Trending opportunities" href="/vendor/find" />
          <div className="flex flex-col gap-2">
            {events.filter((e) => e.trending).map((e) => (
              <Link
                key={e.id}
                href="/vendor/find"
                className="flex items-center gap-3 rounded-2xl border bg-card p-3 transition-colors hover:bg-muted"
              >
                <TrendingUp className="size-5 text-accent-foreground" />
                <div className="flex flex-1 flex-col">
                  <span className="text-sm font-semibold">{e.name}</span>
                  <span className="text-xs text-muted-foreground">
                    {e.spotsRemaining} spots left · ${e.vendorFee} fee
                  </span>
                </div>
                <ArrowRight className="size-4 text-muted-foreground" />
              </Link>
            ))}
          </div>
        </section>
      </div>
    </PanelContent>
  )
}

export default function VendorHome() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorHomeContent />
    </Suspense>
  )
}
