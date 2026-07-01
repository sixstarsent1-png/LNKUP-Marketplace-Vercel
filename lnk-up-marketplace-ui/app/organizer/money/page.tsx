"use client"

import { Suspense } from "react"
import {
  DollarSign,
  TrendingUp,
  Clock,
  Download,
  ArrowUpRight,
  Receipt,
  Wallet,
  RotateCcw,
  FileBarChart,
  CheckCircle2,
} from "lucide-react"
import { events } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import { StatusBadge } from "@/components/marketplace/badges"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

const payouts = [
  { id: "p1", event: "Charlotte Night Market", date: "Jul 20, 2026", amount: 4070, status: "paid" as const },
  { id: "p2", event: "Atlanta Food Fest", date: "Aug 4, 2026", amount: 8320, status: "due" as const },
  { id: "p3", event: "Greensboro Summer Block Party", date: "Jul 27, 2026", amount: 3750, status: "paid" as const },
  { id: "p4", event: "Houston Rodeo Weekend Market", date: "Sep 22, 2026", amount: 12245, status: "due" as const },
]

const refunds = [
  { id: "rf1", event: "Charlotte Night Market", vendor: "The Rolling Dessert Co.", amount: 185, date: "Jun 14, 2026" },
]

function OrganizerMoneyContent() {
  const { state, retry } = usePanelState()
  const myEvents = events.filter((e) => e.organizer === "CultureWave Events" || e.organizer === "Southern Social Co.")
  const total = myEvents.reduce((s, e) => s + e.revenue, 0)
  const pending = payouts.filter((p) => p.status === "due").reduce((s, p) => s + p.amount, 0)
  const available = payouts.filter((p) => p.status === "paid").reduce((s, p) => s + p.amount, 0)
  const payoutsActive = true

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Money"
          subtitle="Track vendor fees and payouts across your events."
          action={
            <Button variant="outline">
              <Download data-icon="inline-start" />
              Export
            </Button>
          }
        />

        <Tabs defaultValue="overview">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList variant="line" className="w-max">
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="wallet">Wallet</TabsTrigger>
              <TabsTrigger value="payouts">Payouts</TabsTrigger>
              <TabsTrigger value="revenue">Revenue</TabsTrigger>
              <TabsTrigger value="refunds">Refunds</TabsTrigger>
              <TabsTrigger value="reports">Reports</TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="overview" className="mt-4 flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
              <StatCard label="Total collected" value={`$${total.toLocaleString()}`} hint="Across your events" icon={DollarSign} accent />
              <StatCard label="Available balance" value={`$${available.toLocaleString()}`} hint="Ready to withdraw" icon={Wallet} />
              <StatCard label="Pending payout" value={`$${pending.toLocaleString()}`} hint="Clears after events" icon={Clock} />
            </div>

            <Card className="rounded-2xl bg-primary text-primary-foreground">
              <CardContent className="flex items-center gap-4 py-5">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent">
                  <CheckCircle2 className="size-5 text-accent-foreground" />
                </span>
                <div className="flex flex-1 flex-col">
                  <span className="font-heading font-semibold">
                    {payoutsActive ? "Payouts active" : "Finish payout setup"}
                  </span>
                  <span className="text-sm text-primary-foreground/70">
                    {payoutsActive
                      ? "Vendor fees land in your LNKup balance after each event. Payouts typically clear in 3 business days."
                      : "Connect your bank account to receive vendor fee payouts after events."}
                  </span>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="wallet" className="mt-4 flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3">
              <StatCard label="Available" value={`$${available.toLocaleString()}`} hint="Withdraw anytime" icon={Wallet} accent />
              <StatCard label="Pending" value={`$${pending.toLocaleString()}`} hint="After event close" icon={Clock} />
            </div>
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">LNKup balance</CardTitle>
              </CardHeader>
              <CardContent className="text-sm text-muted-foreground">
                Your balance holds vendor fees until events complete. No need to manage payment processors
                directly — LNKup handles collection and payout timing for you.
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="payouts" className="mt-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Payout history</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col">
                {payouts.map((p, i) => (
                  <div key={p.id}>
                    {i > 0 && <Separator />}
                    <div className="flex items-center gap-3 py-3">
                      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                        <ArrowUpRight className="size-5 text-muted-foreground" />
                      </span>
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm font-semibold">{p.event}</span>
                        <span className="text-xs text-muted-foreground">{p.date}</span>
                      </div>
                      <div className="flex flex-col items-end gap-1">
                        <span className="font-heading font-bold">${p.amount.toLocaleString()}</span>
                        <StatusBadge variant={p.status === "paid" ? "paid" : "due"}>
                          {p.status === "paid" ? "Paid" : "Due"}
                        </StatusBadge>
                      </div>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="revenue" className="mt-4 flex flex-col gap-4">
            <StatCard label="Avg. per event" value={`$${Math.round(total / myEvents.length).toLocaleString()}`} hint="Vendor fees" icon={TrendingUp} accent />
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Revenue by event</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col">
                {myEvents.map((e, i) => (
                  <div key={e.id}>
                    {i > 0 && <Separator />}
                    <div className="flex items-center justify-between py-3">
                      <div className="flex flex-col">
                        <span className="text-sm font-semibold">{e.name}</span>
                        <span className="text-xs text-muted-foreground">{e.paidVendors} paid vendors</span>
                      </div>
                      <span className="font-heading font-bold">${e.revenue.toLocaleString()}</span>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="refunds" className="mt-4">
            {refunds.length > 0 ? (
              <Card className="rounded-2xl">
                <CardHeader>
                  <CardTitle className="text-base">Refund history</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col">
                  {refunds.map((r, i) => (
                    <div key={r.id}>
                      {i > 0 && <Separator />}
                      <div className="flex items-center gap-3 py-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                          <RotateCcw className="size-5 text-muted-foreground" />
                        </span>
                        <div className="flex flex-1 flex-col">
                          <span className="text-sm font-semibold">{r.event}</span>
                          <span className="text-xs text-muted-foreground">
                            {r.vendor} · {r.date}
                          </span>
                        </div>
                        <span className="font-heading font-bold">-${r.amount}</span>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : (
              <EmptyState
                icon={RotateCcw}
                title="No refunds"
                description="Refunds appear here when a vendor booking is canceled and fees are returned."
              />
            )}
          </TabsContent>

          <TabsContent value="reports" className="mt-4">
            <EmptyState
              icon={FileBarChart}
              title="Reports coming soon"
              description="Download monthly revenue summaries and vendor fee breakdowns for your accountant."
              action={
                <Button variant="outline">
                  <Download data-icon="inline-start" />
                  Request early access
                </Button>
              }
            />
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

export default function OrganizerMoneyPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerMoneyContent />
    </Suspense>
  )
}
