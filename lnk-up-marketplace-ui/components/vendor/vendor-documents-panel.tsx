"use client"

import Link from "next/link"
import { FileCheck, Clock, AlertCircle, XCircle, FileWarning } from "lucide-react"
import type { VendorDocumentRecord, VendorDocumentStatus } from "@/lib/api/vendor-documents"
import { documentsCompletionPercent } from "@/lib/api/vendor-documents"
import { DocumentUploadForm } from "@/components/vendor/document-upload-form"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Progress } from "@/components/ui/progress"
import { cn } from "@/lib/utils"

function statusIcon(status: VendorDocumentStatus) {
  if (status === "approved") return <FileCheck className="size-4 text-success" />
  if (status === "pending") return <Clock className="size-4 text-warning" />
  if (status === "rejected") return <XCircle className="size-4 text-destructive" />
  if (status === "expired") return <AlertCircle className="size-4 text-warning" />
  if (status === "missing") return <FileWarning className="size-4 text-muted-foreground" />
  return <AlertCircle className="size-4 text-muted-foreground" />
}

function statusLabel(status: VendorDocumentStatus): string {
  const labels: Record<VendorDocumentStatus, string> = {
    approved: "Approved",
    pending: "Pending review",
    rejected: "Rejected",
    expired: "Expired",
    missing: "Missing",
    unknown: "Unknown",
  }
  return labels[status]
}

function statusBadgeVariant(status: VendorDocumentStatus) {
  if (status === "approved") return "default"
  if (status === "pending") return "secondary"
  if (status === "rejected" || status === "expired") return "destructive"
  return "outline"
}

type VendorDocumentsPanelProps = {
  items: VendorDocumentRecord[]
  uploading: boolean
  uploadError?: string | null
  onUpload: (file: File, documentType: string, expirationDate?: string) => Promise<{ success: boolean }>
  compact?: boolean
}

export function VendorDocumentsPanel({
  items,
  uploading,
  uploadError,
  onUpload,
  compact = false,
}: VendorDocumentsPanelProps) {
  const score = documentsCompletionPercent(items)

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardContent className="flex flex-col gap-3 pt-6">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium">Document readiness</span>
            <span className="font-heading text-2xl font-bold">{score}%</span>
          </div>
          <Progress value={score} className="h-2" />
          <p className="text-sm text-muted-foreground">
            Documents may be required by some events.
          </p>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-3" data-testid="vendor-documents-list">
        {items.map((doc) => (
          <Card key={doc.id} className="rounded-2xl">
            <CardContent className="flex items-start gap-3 py-4">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                {statusIcon(doc.status)}
              </span>
              <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{doc.label}</span>
                  <Badge variant={statusBadgeVariant(doc.status)} className="text-xs">
                    {statusLabel(doc.status)}
                  </Badge>
                  {doc.required && (
                    <Badge variant="outline" className="text-xs">
                      Required
                    </Badge>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {doc.fileName ? doc.fileName : "No file uploaded"}
                  {doc.expiresAt ? ` · Expires ${doc.expiresAt}` : ""}
                </p>
                {doc.rejectionReason && (
                  <p className="text-xs text-destructive">{doc.rejectionReason}</p>
                )}
              </div>
              {doc.status === "missing" && !compact && (
                <Button size="sm" variant="outline" className="shrink-0" nativeButton={false} render={<Link href="/vendor/documents#document-upload-form" />}>
                  Upload
                </Button>
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      {!compact && (
        <div id="document-upload-form">
          <DocumentUploadForm uploading={uploading} error={uploadError} onUpload={onUpload} />
        </div>
      )}

      {compact && (
        <Button variant="outline" nativeButton={false} render={<Link href="/vendor/documents" />}>
          Manage uploads →
        </Button>
      )}
    </div>
  )
}
