"use client"

import { Suspense, useCallback, useEffect, useMemo, useState } from "react"
import dynamic from "next/dynamic"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { Inbox, LogIn } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  type VendorRequest,
  type VendorRequestStatus,
  vendorRequestStatusLabel,
} from "@/lib/api/vendor-requests"
import { ExecutiveHero } from "@/components/vendor/executive-hero"
import { useVendorRequests } from "@/lib/hooks/use-vendor-requests"
import { useVendorReviewEligibility } from "@/lib/hooks/use-vendor-review-eligibility"
import { submitVendorReviewFeedback } from "@/lib/vendor/review-feedback"
import { usePanelState } from "@/lib/use-panel-state"
import { VendorRequestCard } from "@/components/vendor/vendor-request-card"
import { DraftResumeList } from "@/components/vendor/draft-resume-list"
import { ReviewEligibilityBanner } from "@/components/reputation/review-eligibility-banner"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { PaymentReturnBanner } from "@/components/financial/payment-return-banner"
import { usePaymentReturn } from "@/lib/hooks/use-payment-return"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import { Button } from "@/components/ui/button"
import type { PanelState } from "@/lib/use-panel-state"

const VendorPaymentDetailDrawer = dynamic(
  () =>
    import("@/components/financial/vendor-payment-detail-drawer").then((m) => ({
      default: m.VendorPaymentDetailDrawer,
    })),
  { ssr: false },
)

const tabs: { value: string; label: string; match: (status: VendorRequestStatus) => boolean }[] = [
  { value: "all", label: "All", match: () => true },
  {
    value: "under-review",
    label: "Under Review",
    match: (s) => s === "submitted" || s === "under_review",
  },
  {
    value: "approved",
    label: "Approved",
    match: (s) => s === "approved" || s === "payment_required",
  },
  {
    value: "paid",
    label: "Booked",
    match: (s) => s === "paid" || s === "completed",
  },
  { value: "waitlisted", label: "Waitlisted", match: (s) => s === "waitlisted" },
  {
    value: "rejected",
    label: "Not selected",
    match: (s) => s === "rejected" || s === "cancelled",
  },
]

function VendorRequestsContent() {
  const live = isLiveApiEnabled()
  const searchParams = useSearchParams()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, error, unauthenticated, retry, isLive } = useVendorRequests()
  const refetchRequests = useCallback(() => retry(), [retry])
  const paymentReturn = usePaymentReturn({
    requests: items,
    onRefetch: refetchRequests,
    source: "vendor_requests",
  })
  const { items: eligibility } = useVendorReviewEligibility()
  const [paymentRequest, setPaymentRequest] = useState<VendorRequest | null>(null)
  const [paymentOpen, setPaymentOpen] = useState(false)

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

  useEffect(() => {
    const payId = searchParams.get("pay") ?? searchParams.get("applicationId")
    if (!payId || searchParams.get("payment")) return
    const match = items.find((item) => item.id === payId)
    if (match) {
      setPaymentRequest(match)
      setPaymentOpen(true)
    }
  }, [searchParams, items])

  function eligibilityForRequest(requestId: string) {
    return eligibility.find(
      (item) =>
        item.applicationId === requestId &&
        item.status !== "not_eligible",
    )
  }

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  function handleContinuePayment(request: VendorRequest) {
    setPaymentRequest(request)
    setPaymentOpen(true)
  }

  const requestMetrics = useMemo(() => {
    const underReview = items.filter(
      (r) => r.status === "submitted" || r.status === "under_review",
    ).length
    const needsPayment = items.filter(
      (r) => r.status === "approved" || r.status === "payment_required",
    ).length
    const booked = items.filter((r) => r.status === "paid" || r.status === "completed").length
    const metrics: { label: string; value: string }[] = [
      { label: "Total", value: String(items.length) },
    ]
    if (underReview > 0) metrics.push({ label: "Under review", value: String(underReview) })
    if (needsPayment > 0) metrics.push({ label: "Needs payment", value: String(needsPayment) })
    if (booked > 0) metrics.push({ label: "Booked", value: String(booked) })
    return metrics
  }, [items])

  return (
    <div className="flex flex-col gap-5">
      <ExecutiveHero
        title="Vendor requests"
        subtitle="Track every application from submission to booked."
        metrics={requestMetrics}
        primaryCta={{ label: "Find events", href: "/vendor/find" }}
      />

      <PaymentReturnBanner
        showSuccess={paymentReturn.showSuccess}
        showProcessing={paymentReturn.showProcessing}
        showCancelled={paymentReturn.showCancelled}
        timedOut={paymentReturn.timedOut}
        lastCheckedAt={paymentReturn.lastCheckedAt}
        nextRetryAt={paymentReturn.nextRetryAt}
        request={paymentReturn.matchedRequest}
        onDismiss={() => {
          paymentReturn.clearReturnParams()
          void refetchRequests()
        }}
        onRetryReconcile={() => void paymentReturn.retryReconcile()}
        onRetryPayment={() => {
          const request = paymentReturn.matchedRequest
          if (request) handleContinuePayment(request)
        }}
      />

      {live && unauthenticated && !qaOverride && panelState !== "loading" && (
        <div
          className="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-6 text-center"
          data-testid="vendor-requests-sign-in"
        >
          <LogIn className="size-8 text-muted-foreground" />
          <div className="flex flex-col gap-1">
            <p className="font-medium">Sign in to view your requests</p>
            <p className="text-sm text-muted-foreground">
              Your vendor requests will appear here once you sign in and apply to opportunities.
            </p>
          </div>
          <Button className="min-h-11" nativeButton={false} render={<Link href="/login" />}>
            Sign in
          </Button>
        </div>
      )}

      {live && !unauthenticated && !qaOverride && <DraftResumeList />}

      {(qaOverride || !(live && unauthenticated)) && (
        <Tabs defaultValue="all">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList variant="line" className="w-max">
              {tabs.map((t) => (
                <TabsTrigger key={t.value} value={t.value}>
                  {t.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </div>

          {tabs.map((t) => {
            const tabItems = items.filter((r) => t.match(r.status))
            const tabPanelState: PanelState =
              panelState === "content" && tabItems.length === 0 ? "empty" : panelState

            return (
              <TabsContent key={t.value} value={t.value} className="mt-4">
                <PanelContent
                  state={tabPanelState}
                  retry={handleRetry}
                  empty={
                    <EmptyState
                      icon={Inbox}
                      title="Nothing here yet"
                      description="Your vendor requests will appear here once you apply to opportunities."
                      action={
                        <Button
                          variant="outline"
                          size="sm"
                          className="min-h-11"
                          nativeButton={false}
                          render={<Link href="/vendor/find" />}
                        >
                          Find events
                        </Button>
                      }
                    />
                  }
                >
                  <div className="flex flex-col gap-3" data-testid="vendor-requests-list">
                    {tabItems.map((r: VendorRequest) => {
                      const eligible =
                        r.status === "paid" || r.status === "completed"
                          ? eligibilityForRequest(r.id)
                          : undefined
                      return (
                        <div key={r.id} className="flex flex-col gap-2">
                          {eligible && (
                            <ReviewEligibilityBanner
                              item={eligible}
                              onFeedback={submitVendorReviewFeedback}
                            />
                          )}
                          <VendorRequestCard
                            request={r}
                            onContinuePayment={handleContinuePayment}
                            onWithdrawn={isLive ? () => void refetchRequests() : undefined}
                          />
                        </div>
                      )
                    })}
                  </div>
                </PanelContent>
              </TabsContent>
            )
          })}
        </Tabs>
      )}

      {!isLive && panelState === "content" && (
        <p className="text-xs text-muted-foreground">
          Showing demo data. Status labels: {vendorRequestStatusLabel("under_review")},{" "}
          {vendorRequestStatusLabel("payment_required")}, {vendorRequestStatusLabel("paid")}.
        </p>
      )}

      <VendorPaymentDetailDrawer
        request={paymentRequest}
        open={paymentOpen}
        onOpenChange={setPaymentOpen}
        source="vendor_requests"
      />
    </div>
  )
}

export default function VendorRequestsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <VendorRequestsContent />
    </Suspense>
  )
}
