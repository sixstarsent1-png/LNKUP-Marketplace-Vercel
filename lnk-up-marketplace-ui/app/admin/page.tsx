"use client"

import { Suspense } from "react"
import { useEffect, useState } from "react"
import Link from "next/link"
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
import { DemoModeNotice } from "@/components/marketplace/demo-mode-notice"
import { AdminOpsAlertsPanel } from "@/components/admin/admin-ops-alerts-panel"
import { isLiveApiEnabled } from "@/lib/api/config"
import { buildAdminOpsAlerts, type AdminOpsAlert } from "@/lib/admin/ops-alerts"
import type { ExperimentRecommendation } from "@/lib/ops/experimentation-contract"

type AdminSummaryPayload = {
  meta: { partial: boolean; mocked: boolean; unavailableReasons?: string[]; updatedAt: string }
  kpis: {
    activeVendors: number
    activeOrganizers: number
    liveEvents: number
    paidBookings: number
    applications?: number
    verificationQueue?: number
  }
  queue: {
    externalEventsPendingReview: number
    verificationPending: number
    connectorsPendingApproval: number
  }
}

function AdminLiteOpsCards() {
  const [summary, setSummary] = useState<AdminSummaryPayload | null>(null)
  const [alerts, setAlerts] = useState<AdminOpsAlert[]>([])
  const [recommendations, setRecommendations] = useState<ExperimentRecommendation[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    ;(async () => {
      try {
        const [summaryRes, opsRes] = await Promise.all([
          fetch("/api/admin/summary", { cache: "no-store" }),
          fetch("/api/ops/analytics/summary?range=7d", { cache: "no-store" }).catch(() => null),
        ])
        if (!summaryRes.ok) throw new Error("Admin summary unavailable")
        const data = (await summaryRes.json()) as AdminSummaryPayload
        if (!active) return
        setSummary(data)

        let recs: ExperimentRecommendation[] = []
        if (opsRes?.ok) {
          const ops = (await opsRes.json()) as {
            recommendations?: ExperimentRecommendation[]
          }
          recs = ops.recommendations ?? []
          setRecommendations(recs)
        }

        setAlerts(
          buildAdminOpsAlerts(
            {
              externalEventsPendingReview: data.queue.externalEventsPendingReview,
              verificationPending: data.queue.verificationPending,
              connectorsPendingApproval: data.queue.connectorsPendingApproval,
              partial: data.meta.partial,
              unavailableReasons: data.meta.unavailableReasons,
            },
            recs,
          ),
        )
      } catch {
        if (active) setError("Admin Lite data unavailable. Open LNKup web admin for full ops.")
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => {
      active = false
    }
  }, [])

  return (
    <>
      <Card className="rounded-2xl border-dashed">
        <CardHeader>
          <CardTitle className="text-base">Admin Lite / Internal Ops</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {loading ? <p className="text-sm text-muted-foreground">Loading internal ops snapshot…</p> : null}
          {error ? <p className="text-sm text-muted-foreground">{error}</p> : null}
          {summary ? (
            <>
              <p className="text-sm text-muted-foreground">
                {summary.meta.mocked ? "Mock" : summary.meta.partial ? "Live (partial)" : "Live"} snapshot · updated{" "}
                {new Date(summary.meta.updatedAt).toLocaleString()}
              </p>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Active vendors" value={summary.kpis.activeVendors} />
                <StatCard label="Organizers" value={summary.kpis.activeOrganizers} />
                <StatCard label="Live events" value={summary.kpis.liveEvents} />
                <StatCard label="Paid bookings" value={summary.kpis.paidBookings} />
              </div>
              <div className="grid gap-3 sm:grid-cols-3">
                <Card className="rounded-xl">
                  <CardContent className="py-4">
                    <p className="text-xs text-muted-foreground">External events queue</p>
                    <p className="text-xl font-semibold">{summary.queue.externalEventsPendingReview}</p>
                  </CardContent>
                </Card>
                <Card className="rounded-xl">
                  <CardContent className="py-4">
                    <p className="text-xs text-muted-foreground">Verification queue</p>
                    <p className="text-xl font-semibold">{summary.queue.verificationPending}</p>
                  </CardContent>
                </Card>
                <Card className="rounded-xl">
                  <CardContent className="py-4">
                    <p className="text-xs text-muted-foreground">Connector approvals</p>
                    <p className="text-xl font-semibold">{summary.queue.connectorsPendingApproval}</p>
                  </CardContent>
                </Card>
              </div>
              {summary.meta.partial && summary.meta.unavailableReasons?.length ? (
                <p className="text-xs text-muted-foreground">{summary.meta.unavailableReasons.join(" ")}</p>
              ) : null}
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/admin/external-events" />}>
                  External events
                </Button>
                <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/admin/verification" />}>
                  Verification
                </Button>
                <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/admin/connectors" />}>
                  Connectors
                </Button>
              </div>
            </>
          ) : null}
        </CardContent>
      </Card>
      {!loading ? <AdminOpsAlertsPanel alerts={alerts} recommendations={recommendations} /> : null}
    </>
  )
}

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
  const live = isLiveApiEnabled()

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Platform overview" subtitle="Marketplace health across vendors, organizers, and events." />
        <AdminLiteOpsCards />

        {live ? (
          <DemoModeNotice testId="admin-overview-demo-notice">
            Charts and totals below are demo samples for layout only. Trust Admin Lite cards above or open LNKup web
            admin for production metrics.
          </DemoModeNotice>
        ) : (
          <DemoModeNotice testId="admin-overview-demo-notice">
            Demo mode — platform overview uses sample data. Enable live API for Admin Lite snapshots.
          </DemoModeNotice>
        )}

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Active vendors" value={adminMetrics.vendorCount} hint="Demo sample" icon={Store} accent />
          <StatCard label="Organizers" value={adminMetrics.organizerCount} hint="Demo sample" icon={Users} />
          <StatCard label="Live events" value={adminMetrics.liveEvents} hint="Demo sample" icon={CalendarDays} />
          <StatCard label="Applications" value={adminMetrics.totalApplications} hint="Demo sample" icon={FileText} />
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
