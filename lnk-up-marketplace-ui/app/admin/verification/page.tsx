"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { AdminHandoffCard } from "@/components/admin/admin-handoff-card"
import type { VerificationQueueItem } from "@/lib/admin/queues"

type Payload = {
  code?: string
  meta: { mocked: boolean; partial: boolean; updatedAt: string; unavailableReasons?: string[] }
  stats: { queueCount: number; approved24h: number }
  items?: VerificationQueueItem[]
  handoffUrl?: string
}

export default function AdminVerificationPage() {
  const [data, setData] = useState<Payload | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    ;(async () => {
      try {
        const res = await fetch("/api/admin/verification", { cache: "no-store" })
        if (!res.ok) throw new Error("Unavailable")
        const payload = (await res.json()) as Payload
        if (active) setData(payload)
      } catch {
        if (active) setError("Verification summary is unavailable.")
      }
    })()
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="flex flex-col gap-6" data-testid="admin-verification-page">
      <PageHeader title="Verification" subtitle="Review pending vendor documents without opening full wp-admin." />
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
                  <p className="text-xs text-muted-foreground">Pending documents</p>
                  <p className="text-2xl font-semibold">{data.stats.queueCount}</p>
                </div>
                <div className="rounded-xl border p-4">
                  <p className="text-xs text-muted-foreground">Approved (24h)</p>
                  <p className="text-2xl font-semibold">{data.stats.approved24h}</p>
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

      <Card className="rounded-2xl" data-testid="admin-verification-queue">
        <CardHeader>
          <CardTitle className="text-base">Document queue</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {data && (data.items?.length ?? 0) === 0 ? (
            <p className="text-sm text-muted-foreground">No pending documents.</p>
          ) : null}
          {data?.items?.map((item) => (
            <div
              key={item.documentId}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
              data-testid={`verification-row-${item.documentId}`}
            >
              <div>
                <p className="font-medium">{item.vendorName}</p>
                <p className="text-xs text-muted-foreground">{item.documentTypeLabel}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline">{item.status}</Badge>
                <Button
                  size="sm"
                  variant="outline"
                  nativeButton={false}
                  render={<Link href={`/admin/verification/${item.documentId}`} />}
                >
                  Review
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <AdminHandoffCard
        description="Organizer trust tabs and bulk verification tools stay in WordPress."
        href={data?.handoffUrl ? `${data.handoffUrl}&tab=documents` : undefined}
        cta="Open WordPress Verification"
      />
    </div>
  )
}
