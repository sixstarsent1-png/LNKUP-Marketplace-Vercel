"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { AdminHandoffCard } from "@/components/admin/admin-handoff-card"
import { ConfirmActionButton } from "@/components/admin/confirm-action-button"
import type { ExternalEventQueueItem } from "@/lib/admin/queues"

type DetailPayload = {
  code?: string
  item?: ExternalEventQueueItem
  handoffUrl?: string
  message?: string
}

export default function AdminExternalEventDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const [data, setData] = useState<DetailPayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function load() {
    const res = await fetch(`/api/admin/external-events/${params.id}`, { cache: "no-store" })
    const payload = (await res.json()) as DetailPayload
    if (!res.ok && !payload.item) {
      setError(payload.message ?? "Event unavailable")
      setData(payload)
      return
    }
    setData(payload)
  }

  useEffect(() => {
    void load().catch(() => setError("Event unavailable"))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id])

  async function runAction(action: "publish" | "reject") {
    setNotice(null)
    const res = await fetch(`/api/admin/external-events/${params.id}/action`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action }),
    })
    const payload = (await res.json()) as { message?: string }
    if (!res.ok) {
      setNotice(payload.message ?? "Action failed")
      return
    }
    setNotice(`Event ${action} succeeded.`)
    router.push("/admin/external-events")
  }

  const item = data?.item

  return (
    <div className="flex flex-col gap-6" data-testid="admin-external-event-detail">
      <PageHeader
        title={item?.title ?? "External event"}
        subtitle="Publish or reject pending imported events. Imports stay in WordPress."
      />
      <Button
        variant="outline"
        size="sm"
        className="w-fit"
        nativeButton={false}
        render={<Link href="/admin/external-events" />}
      >
        Back to queue
      </Button>
      {error ? <p className="text-sm text-muted-foreground">{error}</p> : null}
      {notice ? <p className="text-sm font-medium">{notice}</p> : null}

      {item ? (
        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle className="text-base">Review detail</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2">
              <Badge variant="outline">{item.workflowStatus}</Badge>
              {item.sourcePlatform ? <Badge variant="secondary">{item.sourcePlatform}</Badge> : null}
            </div>
            <p className="text-sm text-muted-foreground">
              {[item.city, item.state].filter(Boolean).join(", ") || "Location TBA"}
            </p>
            <p className="text-sm text-muted-foreground">
              Fit {item.vendorFitScore ?? 0} · Interest {item.vendorInterestCount ?? 0}
            </p>
            <div className="flex flex-wrap gap-2 pt-2">
              <ConfirmActionButton
                label="Publish"
                testId="external-event-publish"
                onConfirm={() => runAction("publish")}
              />
              <ConfirmActionButton
                label="Reject"
                variant="secondary"
                testId="external-event-reject"
                onConfirm={() => runAction("reject")}
              />
            </div>
          </CardContent>
        </Card>
      ) : null}

      <AdminHandoffCard
        description="Provider sync, CSV import, merge, and organizer notify remain in WordPress."
        href={data?.handoffUrl}
      />
    </div>
  )
}
