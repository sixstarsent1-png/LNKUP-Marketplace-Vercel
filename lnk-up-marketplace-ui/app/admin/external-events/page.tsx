"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { AdminHandoffCard } from "@/components/admin/admin-handoff-card"
import type { ExternalEventQueueItem } from "@/lib/admin/queues"

type Payload = {
  code?: string
  meta: { mocked: boolean; partial: boolean; updatedAt: string; unavailableReasons?: string[] }
  stats: { pendingReview: number; imported24h: number }
  items?: ExternalEventQueueItem[]
  handoffUrl?: string
}

export default function AdminExternalEventsPage() {
  const [data, setData] = useState<Payload | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    ;(async () => {
      try {
        const res = await fetch("/api/admin/external-events", { cache: "no-store" })
        if (!res.ok) throw new Error("Unavailable")
        const payload = (await res.json()) as Payload
        if (active) setData(payload)
      } catch {
        if (active) setError("External events summary is unavailable.")
      }
    })()
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="flex flex-col gap-6" data-testid="admin-external-events-page">
      <PageHeader
        title="External events"
        subtitle="Review imported events before publishing them into discovery."
      />
      <Card className="rounded-2xl">
        <CardHeader>
          <CardTitle className="text-base">Queue snapshot</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {error ? <p className="text-sm text-muted-foreground">{error}</p> : null}
          {data ? (
            <>
              <p className="text-sm text-muted-foreground">
                {data.meta.mocked ? "Mock" : data.meta.partial ? "Live (partial)" : "Live"} · updated{" "}
                {new Date(data.meta.updatedAt).toLocaleString()}
                {data.code ? ` · ${data.code}` : ""}
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border p-4">
                  <p className="text-xs text-muted-foreground">Pending review</p>
                  <p className="text-2xl font-semibold">{data.stats.pendingReview}</p>
                </div>
                <div className="rounded-xl border p-4">
                  <p className="text-xs text-muted-foreground">Imported (24h)</p>
                  <p className="text-2xl font-semibold">{data.stats.imported24h}</p>
                  {!data.meta.mocked && data.stats.imported24h === 0 ? (
                    <p className="mt-1 text-[11px] text-muted-foreground">Not provided by WP dashboard KPIs.</p>
                  ) : null}
                </div>
              </div>
              {data.meta.partial && data.meta.unavailableReasons?.length ? (
                <p className="text-xs text-muted-foreground">{data.meta.unavailableReasons.join(" ")}</p>
              ) : null}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">Loading…</p>
          )}
        </CardContent>
      </Card>

      <Card className="rounded-2xl" data-testid="admin-external-events-queue">
        <CardHeader>
          <CardTitle className="text-base">Review queue</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {data && (data.items?.length ?? 0) === 0 ? (
            <p className="text-sm text-muted-foreground">No events awaiting review.</p>
          ) : null}
          {data?.items?.map((item) => (
            <div
              key={item.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
              data-testid={`external-event-row-${item.id}`}
            >
              <div>
                <p className="font-medium">{item.title}</p>
                <p className="text-xs text-muted-foreground">
                  {[item.city, item.state].filter(Boolean).join(", ") || "Location TBA"}
                  {item.sourcePlatform ? ` · ${item.sourcePlatform}` : ""}
                  {item.vendorFitScore != null ? ` · fit ${item.vendorFitScore}` : ""}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline">{item.workflowStatus}</Badge>
                <Button
                  size="sm"
                  variant="outline"
                  nativeButton={false}
                  render={<Link href={`/admin/external-events/${item.id}`} />}
                >
                  Review
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <AdminHandoffCard
        description="CSV import, provider sync, merge, and bulk notify stay in WordPress."
        href={data?.handoffUrl}
        cta="Open WordPress External Events"
      />
    </div>
  )
}
