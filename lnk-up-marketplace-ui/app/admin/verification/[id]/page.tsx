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
import type { VerificationQueueItem } from "@/lib/admin/queues"

type DetailPayload = {
  code?: string
  item?: VerificationQueueItem
  handoffUrl?: string
  message?: string
}

export default function AdminVerificationDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const [data, setData] = useState<DetailPayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function load() {
    const res = await fetch(`/api/admin/verification/${params.id}`, { cache: "no-store" })
    const payload = (await res.json()) as DetailPayload
    if (!res.ok && !payload.item) {
      setError(payload.message ?? "Document unavailable")
      setData(payload)
      return
    }
    setData(payload)
  }

  useEffect(() => {
    void load().catch(() => setError("Document unavailable"))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id])

  async function runAction(action: "approve" | "reject") {
    setNotice(null)
    const res = await fetch(`/api/admin/verification/${params.id}/action`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action }),
    })
    const payload = (await res.json()) as { message?: string }
    if (!res.ok) {
      setNotice(payload.message ?? "Action failed")
      return
    }
    setNotice(`Document ${action} succeeded.`)
    router.push("/admin/verification")
  }

  const item = data?.item

  return (
    <div className="flex flex-col gap-6" data-testid="admin-verification-detail">
      <PageHeader
        title={item?.documentTypeLabel ?? "Document"}
        subtitle={item?.vendorName ?? "Vendor document review"}
      />
      <Button variant="outline" size="sm" className="w-fit" nativeButton={false} render={<Link href="/admin/verification" />}>
        Back to queue
      </Button>
      {error ? <p className="text-sm text-muted-foreground">{error}</p> : null}
      {notice ? <p className="text-sm font-medium">{notice}</p> : null}

      {item ? (
        <Card className="rounded-2xl">
          <CardHeader>
            <CardTitle className="text-base">Document detail</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <Badge variant="outline" className="w-fit">
              {item.status}
            </Badge>
            <p className="text-sm text-muted-foreground">Vendor: {item.vendorName}</p>
            <p className="text-sm text-muted-foreground">Type: {item.documentTypeLabel}</p>
            {item.uploadedAt ? (
              <p className="text-sm text-muted-foreground">
                Uploaded {new Date(item.uploadedAt).toLocaleString()}
              </p>
            ) : null}
            <div className="flex flex-wrap gap-2 pt-2">
              <ConfirmActionButton
                label="Approve"
                testId="verification-approve"
                onConfirm={() => runAction("approve")}
              />
              <ConfirmActionButton
                label="Reject"
                variant="secondary"
                testId="verification-reject"
                onConfirm={() => runAction("reject")}
              />
            </div>
          </CardContent>
        </Card>
      ) : null}

      <AdminHandoffCard
        description="File previews and organizer trust controls remain in WordPress Verification."
        href={data?.handoffUrl}
      />
    </div>
  )
}
