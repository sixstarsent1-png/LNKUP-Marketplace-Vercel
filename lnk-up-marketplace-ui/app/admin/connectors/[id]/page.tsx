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
import type { ConnectorQueueItem } from "@/lib/admin/queues"

type DetailPayload = {
  code?: string
  item?: ConnectorQueueItem
  handoffUrl?: string
  message?: string
}

export default function AdminConnectorDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const [data, setData] = useState<DetailPayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function load() {
    setError(null)
    const res = await fetch(`/api/admin/connectors/${params.id}`, { cache: "no-store" })
    const payload = (await res.json()) as DetailPayload
    if (!res.ok && !payload.item) {
      setError(payload.message ?? "Connector unavailable")
      setData(payload)
      return
    }
    setData(payload)
  }

  useEffect(() => {
    void load().catch(() => setError("Connector unavailable"))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id])

  async function runAction(action: "approve" | "pause" | "reject") {
    setNotice(null)
    const res = await fetch(`/api/admin/connectors/${params.id}/action`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action }),
    })
    const payload = (await res.json()) as { message?: string; code?: string }
    if (!res.ok) {
      setNotice(payload.message ?? "Action failed")
      return
    }
    setNotice(`Connector ${action} succeeded.`)
    await load()
    if (action === "reject") router.push("/admin/connectors")
  }

  const item = data?.item

  return (
    <div className="flex flex-col gap-6" data-testid="admin-connector-detail">
      <PageHeader
        title={item?.displayName ?? "Connector"}
        subtitle="Safer approve / pause / reject actions. Rewards stay in WordPress."
      />
      <Button variant="outline" size="sm" className="w-fit" nativeButton={false} render={<Link href="/admin/connectors" />}>
        Back to queue
      </Button>

      {error ? <p className="text-sm text-muted-foreground">{error}</p> : null}
      {notice ? <p className="text-sm font-medium">{notice}</p> : null}

      {item ? (
        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle className="text-base">Connector detail</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2">
              <Badge variant="outline">{item.status}</Badge>
              {item.marketSlug ? <Badge variant="secondary">{item.marketSlug}</Badge> : null}
            </div>
            <p className="text-sm text-muted-foreground">{item.email}</p>
            <p className="text-sm text-muted-foreground">
              {[item.city, item.state].filter(Boolean).join(", ") || "Location TBA"}
            </p>
            <div className="flex flex-wrap gap-2 pt-2">
              <ConfirmActionButton
                label="Approve"
                testId="connector-approve"
                onConfirm={() => runAction("approve")}
              />
              <ConfirmActionButton
                label="Pause"
                variant="outline"
                testId="connector-pause"
                onConfirm={() => runAction("pause")}
              />
              <ConfirmActionButton
                label="Reject"
                variant="secondary"
                testId="connector-reject"
                onConfirm={() => runAction("reject")}
              />
            </div>
          </CardContent>
        </Card>
      ) : null}

      <AdminHandoffCard
        description="Reward issuance and program settings remain in WordPress Connectors."
        href={data?.handoffUrl}
      />
    </div>
  )
}
