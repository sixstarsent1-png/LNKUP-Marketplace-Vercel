"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { AdminHandoffCard } from "@/components/admin/admin-handoff-card"
import type { ConnectorQueueItem } from "@/lib/admin/queues"

type Payload = {
  code?: string
  meta: { mocked: boolean; partial: boolean; updatedAt: string; unavailableReasons?: string[] }
  stats: { pendingApprovals: number; activeConnectors: number }
  items?: ConnectorQueueItem[]
  handoffUrl?: string
}

export default function AdminConnectorsPage() {
  const [data, setData] = useState<Payload | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    ;(async () => {
      try {
        const res = await fetch("/api/admin/connectors", { cache: "no-store" })
        if (!res.ok) throw new Error("Connector summary unavailable")
        const payload = (await res.json()) as Payload
        if (active) setData(payload)
      } catch {
        if (active) setError("Connector summary is unavailable.")
      }
    })()
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="flex flex-col gap-6" data-testid="admin-connectors-page">
      <PageHeader title="Connector program" subtitle="Approve city connectors and track active network size." />
      <Card className="rounded-2xl">
        <CardHeader>
          <CardTitle className="text-base">Connector snapshot</CardTitle>
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
                  <p className="text-xs text-muted-foreground">Pending approvals</p>
                  <p className="text-2xl font-semibold">{data.stats.pendingApprovals}</p>
                </div>
                <div className="rounded-xl border p-4">
                  <p className="text-xs text-muted-foreground">Active connectors</p>
                  <p className="text-2xl font-semibold">{data.stats.activeConnectors}</p>
                </div>
              </div>
              {data.meta.partial && data.meta.unavailableReasons?.length ? (
                <p className="text-xs text-muted-foreground">{data.meta.unavailableReasons.join(" ")}</p>
              ) : null}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">Loading connector snapshot…</p>
          )}
        </CardContent>
      </Card>

      <Card className="rounded-2xl" data-testid="admin-connectors-queue">
        <CardHeader>
          <CardTitle className="text-base">Approval queue</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {!data ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
          {data && (data.items?.length ?? 0) === 0 ? (
            <p className="text-sm text-muted-foreground">No connectors in queue.</p>
          ) : null}
          {data?.items?.map((item) => (
            <div
              key={item.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
              data-testid={`connector-row-${item.id}`}
            >
              <div>
                <p className="font-medium">{item.displayName}</p>
                <p className="text-xs text-muted-foreground">
                  {[item.city, item.state].filter(Boolean).join(", ") || "Market TBA"} · {item.email}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline">{item.status}</Badge>
                <Button size="sm" variant="outline" nativeButton={false} render={<Link href={`/admin/connectors/${item.id}`} />}>
                  Review
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <AdminHandoffCard
        description="Import settings, rewards, and bulk connector tools stay in WordPress."
        href={data?.handoffUrl}
        cta="Open WordPress Connectors"
      />
    </div>
  )
}
