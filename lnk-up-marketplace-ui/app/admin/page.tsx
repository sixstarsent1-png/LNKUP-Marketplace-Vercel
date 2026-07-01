"use client"

import { Suspense } from "react"
import {
  Users,
  CalendarDays,
  DollarSign,
  Store,
  AlertTriangle,
  ShieldCheck,
  ArrowRight,
  FileText,
  Tag,
  RotateCcw,
  Scale,
  CheckCircle2,
} from "lucide-react"
import { events, adminMetrics } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"

const gmvByMonth = [
  { month: "Feb", value: 42 },
  { month: "Mar", value: 55 },
  { month: "Apr", value: 61 },
  { month: "May", value: 78 },
  { month: "Jun", value: 92 },
  { month: "Jul", value: 100 },
]

const disputesQueue = [
  { id: "disp-1", subject: "Vendor refund — Miami Culture Pop-Up", vendor: "City Drip Apparel", status: "open" },
  { id: "disp-2", subject: "Booth assignment dispute", vendor: "The Rolling Dessert Co.", status: "open" },
]

const refundsQueue = [
  { id: "ref-1", event: "Greensboro Summer Block Party", amount: 185, date: "Jun 12, 2026" },
  { id: "ref-2", event: "Miami Culture Pop-Up", amount: 210, date: "Jun 8, 2026" },
  { id: "ref-3", event: "Charlotte Night Market", amount: 145, date: "Jun 3, 2026" },
]

function AdminOverviewContent() {
  const { state, retry } = usePanelState()
  const platformFee = Math.round(adminMetrics.gmv * 0.1)
  const maxBar = Math.max(...gmvByMonth.map((d) => d.value))

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Platform overview" subtitle="Marketplace health across vendors, organizers, and events." />

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Active vendors" value={adminMetrics.vendorCount} hint="In mock directory" icon={Store} accent />
          <StatCard label="Organizers" value={adminMetrics.organizerCount} hint="On platform" icon={Users} />
          <StatCard label="Live events" value={adminMetrics.liveEvents} hint="Accepting vendors" icon={CalendarDays} />
          <StatCard label="Applications" value={adminMetrics.totalApplications} hint="All statuses" icon={FileText} />
        </div>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Verified vendors" value={adminMetrics.verifiedVendors} hint={`of ${adminMetrics.vendorCount} total`} icon={CheckCircle2} accent />
          <StatCard label="Accepted bookings" value={adminMetrics.acceptedApplications} hint="Approved + payment due + paid" icon={FileText} />
          <StatCard label="Paid bookings" value={adminMetrics.paidBookings} hint="Completed vendor fees" icon={DollarSign} />
          <StatCard label="Open disputes" value={adminMetrics.openDisputes} hint="Needs resolution" icon={Scale} />
        </div>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Gross marketplace volume" value={`$${adminMetrics.gmv.toLocaleString()}`} hint="Vendor fees collected" icon={DollarSign} accent />
          <StatCard label="Platform revenue" value={`$${platformFee.toLocaleString()}`} hint="10% take rate" icon={DollarSign} />
          <StatCard label="Refunds (30d)" value={adminMetrics.refundsThisMonth} hint={`$${adminMetrics.refundsAmount} returned`} icon={RotateCcw} />
          <StatCard label="Verification queue" value={adminMetrics.verificationQueue} hint="Needs review" icon={ShieldCheck} />
        </div>

        <section>
          <SectionHeader title="Top categories" />
          <div className="flex flex-wrap gap-2">
            {adminMetrics.topCategories.map((cat) => (
              <Badge key={cat} variant="secondary" className="gap-1 px-3 py-1">
                <Tag className="size-3" />
                {cat}
              </Badge>
            ))}
          </div>
        </section>

        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle className="text-base">Marketplace volume</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            <div className="flex h-40 items-end justify-between gap-2">
              {gmvByMonth.map((d) => (
                <div
                  key={d.month}
                  className="flex-1 rounded-t-md bg-accent transition-all"
                  style={{ height: `${(d.value / maxBar) * 100}%` }}
                  aria-label={`${d.month}: ${d.value}`}
                />
              ))}
            </div>
            <div className="flex justify-between gap-2">
              {gmvByMonth.map((d) => (
                <span key={d.month} className="flex-1 text-center text-xs text-muted-foreground">
                  {d.month}
                </span>
              ))}
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="rounded-2xl">
            <CardHeader>
              <CardTitle className="flex items-center justify-between text-base">
                Disputes
                <Badge variant="secondary">{disputesQueue.length}</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
              {disputesQueue.map((d, i) => (
                <div key={d.id}>
                  {i > 0 && <Separator className="my-1" />}
                  <div className="flex items-center gap-3 py-2">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-destructive/10">
                      <Scale className="size-4 text-destructive" />
                    </span>
                    <div className="flex flex-1 flex-col">
                      <span className="text-sm font-medium">{d.subject}</span>
                      <span className="text-xs text-muted-foreground">{d.vendor}</span>
                    </div>
                    <Button size="sm" variant="outline">
                      Review
                    </Button>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>

          <Card className="rounded-2xl">
            <CardHeader>
              <CardTitle className="flex items-center justify-between text-base">
                Recent refunds
                <Badge variant="secondary">{refundsQueue.length}</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
              {refundsQueue.map((r, i) => (
                <div key={r.id}>
                  {i > 0 && <Separator className="my-1" />}
                  <div className="flex items-center gap-3 py-2">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning/15">
                      <RotateCcw className="size-4 text-warning" />
                    </span>
                    <div className="flex flex-1 flex-col">
                      <span className="text-sm font-medium">{r.event}</span>
                      <span className="text-xs text-muted-foreground">{r.date}</span>
                    </div>
                    <span className="font-heading text-sm font-bold">-${r.amount}</span>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="rounded-2xl">
            <CardHeader>
              <CardTitle className="flex items-center justify-between text-base">
                Moderation queue
                <Badge variant="secondary">3</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
              {[
                { label: "Organizer verification", subject: "MarketHouse Collective", type: "verify" },
                { label: "Vendor document review", subject: "The Rolling Dessert Co.", type: "doc" },
                { label: "Reported listing", subject: "Miami Culture Pop-Up", type: "flag" },
              ].map((m, i) => (
                <div key={m.subject}>
                  {i > 0 && <Separator className="my-1" />}
                  <div className="flex items-center gap-3 py-2">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning/15">
                      {m.type === "verify" ? (
                        <ShieldCheck className="size-4 text-warning" />
                      ) : (
                        <AlertTriangle className="size-4 text-warning" />
                      )}
                    </span>
                    <div className="flex flex-1 flex-col">
                      <span className="text-sm font-medium">{m.label}</span>
                      <span className="text-xs text-muted-foreground">{m.subject}</span>
                    </div>
                    <Button size="sm" variant="outline">
                      Review
                    </Button>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>

          <Card className="rounded-2xl">
            <CardHeader>
              <CardTitle className="text-base">Top events by revenue</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
              {[...events]
                .sort((a, b) => b.revenue - a.revenue)
                .slice(0, 4)
                .map((e, i) => (
                  <div key={e.id}>
                    {i > 0 && <Separator className="my-1" />}
                    <div className="flex items-center gap-3 py-2">
                      <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold">
                        {i + 1}
                      </span>
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm font-medium">{e.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {e.city}, {e.state} · {e.paidVendors} vendors
                        </span>
                      </div>
                      <span className="font-heading text-sm font-bold">${e.revenue.toLocaleString()}</span>
                    </div>
                  </div>
                ))}
            </CardContent>
          </Card>
        </div>

        <Card className="rounded-2xl bg-primary text-primary-foreground">
          <CardContent className="flex items-center gap-4 py-5">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent">
              <CalendarDays className="size-5 text-accent-foreground" />
            </span>
            <div className="flex flex-1 flex-col">
              <span className="font-heading font-semibold">{adminMetrics.liveEvents} live events right now</span>
              <span className="text-sm text-primary-foreground/70">
                Top cities: {adminMetrics.topCities.slice(0, 3).join(", ")}.
              </span>
            </div>
            <ArrowRight className="size-5 shrink-0 text-primary-foreground/70" />
          </CardContent>
        </Card>
      </div>
    </PanelContent>
  )
}

export default function AdminOverview() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <AdminOverviewContent />
    </Suspense>
  )
}
