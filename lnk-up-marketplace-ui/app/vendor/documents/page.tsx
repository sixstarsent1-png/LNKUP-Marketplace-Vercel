"use client"

import { Suspense, useMemo } from "react"
import Link from "next/link"
import { LogIn, FileText } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useVendorDocuments } from "@/lib/hooks/use-vendor-documents"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { VendorDocumentsPanel } from "@/components/vendor/vendor-documents-panel"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import type { PanelState } from "@/lib/use-panel-state"

function DocumentsContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, uploading, error, uploadError, unauthenticated, retry, uploadDocument } =
    useVendorDocuments()

  const qaOverride = qaState !== "content"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (live && unauthenticated) return "content"
    if (items.length === 0) return "empty"
    return "content"
  }, [qaState, live, loading, error, unauthenticated, items.length])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Documents"
        subtitle="Upload once. Reuse across events."
      />

      {live && unauthenticated && !qaOverride && panelState !== "loading" && (
        <div className="flex items-center justify-between gap-3 rounded-2xl border bg-muted/40 p-4">
          <p className="text-sm text-muted-foreground">Sign in to view and upload your documents.</p>
          <Button size="sm" nativeButton={false} render={<Link href="/login?redirect=/vendor/documents" />}>
            <LogIn data-icon="inline-start" />
            Sign in
          </Button>
        </div>
      )}

      <PanelContent
        state={panelState}
        retry={handleRetry}
        empty={
          <EmptyState
            icon={FileText}
            title="No documents yet"
            description="Upload compliance documents to get approved faster."
            action={
              <Button variant="outline" size="sm" nativeButton={false} render={<Link href="#document-upload-form" />}>
                Upload document
              </Button>
            }
          />
        }
      >
        <VendorDocumentsPanel
          items={items}
          uploading={uploading}
          uploadError={uploadError}
          onUpload={uploadDocument}
        />
      </PanelContent>
    </div>
  )
}

export default function VendorDocumentsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <DocumentsContent />
    </Suspense>
  )
}
