"use client"

import { Suspense, use, useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { notFound } from "next/navigation"
import { ArrowLeft, LogIn, Ticket } from "lucide-react"
import { isLiveApiEnabled, getVendorLoginUrl } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import { buildVendorEventReadiness } from "@/lib/vendor/event-readiness"
import { resolveLivePassApplicationId, slugifyPassKey } from "@/lib/vendor/resolve-pass-id"
import { useVendorPacket } from "@/lib/hooks/use-vendor-packet"
import { useVendorRequests } from "@/lib/hooks/use-vendor-requests"
import { useVendorSupport } from "@/lib/hooks/use-vendor-support"
import { useVendorReviewEligibility } from "@/lib/hooks/use-vendor-review-eligibility"
import { submitVendorReviewFeedback } from "@/lib/vendor/review-feedback"
import { usePanelState } from "@/lib/use-panel-state"
import { VendorPacketPreview } from "@/components/vendor/vendor-packet-preview"
import { VendorPaymentDetailDrawer } from "@/components/financial/vendor-payment-detail-drawer"
import { VendorEventReadinessChecklist } from "@/components/vendor/operations/vendor-event-readiness-checklist"
import { VendorSupportPanel } from "@/components/vendor/operations/vendor-support-panel"
import { ReviewEligibilityBanner } from "@/components/reputation/review-eligibility-banner"
import { PaymentReturnBanner } from "@/components/financial/payment-return-banner"
import { usePaymentReturn } from "@/lib/hooks/use-payment-return"
import { EmptyState, PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"

function resolveApplicationId(param: string): string | null {
  if (/^\d+$/.test(param) || param.startsWith("req-")) {
    return param
  }
  const mock = mockVendorRequests.find((item) => item.eventId === param)
  if (mock) {
    return mock.id
  }
  const slug = slugifyPassKey(param)
  const byName = mockVendorRequests.find((item) => slugifyPassKey(item.eventName) === slug)
  return byName?.id ?? null
}

function mockRequestFromApplicationId(applicationId: string): VendorRequest | null {
  const item = mockVendorRequests.find((r) => r.id === applicationId)
  if (!item) return null
  return {
    id: item.id,
    eventId: item.eventId,
    eventName: item.eventName,
    eventImage: item.eventImage,
    eventDate: item.date,
    city: item.city,
    organizerName: item.organizer,
    category: item.category,
    status: mockStatusToNormalized(item.status),
    submittedAt: item.submittedAt,
    vendorFee: item.vendorFee,
    amountDue: item.vendorFee,
    currency: "USD",
    paymentId: item.paymentId,
    paymentDueAt: item.paymentDueAt,
  }
}

function EventPassContent({
  applicationId,
  forceLocked,
}: {
  applicationId: string
  forceLocked: boolean
}) {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { packet, loading, error, unauthenticated, retry } = useVendorPacket(
    applicationId,
    { forceLocked },
  )
  const {
    items: supportItems,
    loading: supportLoading,
    submitting: supportSubmitting,
    submitSupport,
  } = useVendorSupport(applicationId)
  const { items: eligibility } = useVendorReviewEligibility()
  const [paymentOpen, setPaymentOpen] = useState(false)

  const paymentRequest = useMemo((): VendorRequest | null => {
    if (packet) {
      return {
        id: applicationId,
        eventName: packet.eventName,
        eventDate: packet.eventDate,
        city: packet.city,
        state: packet.state,
        status: packet.status === "locked" ? "payment_required" : "paid",
        currency: "USD",
      }
    }
    return mockRequestFromApplicationId(applicationId)
  }, [applicationId, packet])

  const reviewEligibility = eligibility.find(
    (item) => item.applicationId === applicationId && item.status !== "not_eligible",
  )
  const isCompletedRequest =
    paymentRequest?.status === "completed" || packet?.status === "unlocked"
  const refetchPass = useCallback(() => {
    retry()
  }, [retry])
  const paymentReturn = usePaymentReturn({
    requests: paymentRequest ? [paymentRequest] : [],
    onRefetch: refetchPass,
    source: "vendor_pass",
  })

  const readiness = useMemo(() => {
    const request =
      paymentRequest ??
      ({
        id: applicationId,
        eventName: packet?.eventName ?? "Event",
        status: "unknown",
      } as VendorRequest)
    return buildVendorEventReadiness(request, packet)
  }, [applicationId, packet, paymentRequest])

  const panelState = useMemo(() => {
    if (qaState === "error") return "error" as const
    if (qaState === "loading") return "loading" as const
    if (live && loading) return "loading" as const
    if (live && unauthenticated) return "content" as const
    if (live && error) return "error" as const
    if (!packet) return "empty" as const
    return "content" as const
  }, [qaState, live, loading, error, packet, unauthenticated])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  useEffect(() => {
    if (!packet || unauthenticated) return
    trackLnkupEvent({
      event: "vendor_pass_viewed",
      source: "vendor_pass_page",
      requestId: applicationId,
    })
    trackLnkupEvent({
      event: packet.status === "locked" ? "vendor_locked_pass_viewed" : "vendor_unlocked_pass_viewed",
      source: "vendor_pass_page",
      requestId: applicationId,
    })
  }, [applicationId, packet, unauthenticated])

  return (
    <PanelContent state={panelState} retry={handleRetry}>
      <div className="flex flex-col gap-4">
        <Button
          variant="ghost"
          size="sm"
          className="w-fit -ml-2"
          nativeButton={false}
          render={<Link href="/vendor/requests" />}
        >
          <ArrowLeft data-icon="inline-start" />
          Back to requests
        </Button>

        <h1 className="font-heading text-2xl font-bold tracking-tight">Event pass</h1>

        {paymentRequest && (
          <PaymentReturnBanner
            showSuccess={paymentReturn.showSuccess}
            showProcessing={paymentReturn.showProcessing}
            showCancelled={paymentReturn.showCancelled}
            timedOut={paymentReturn.timedOut}
            lastCheckedAt={paymentReturn.lastCheckedAt}
            nextRetryAt={paymentReturn.nextRetryAt}
            request={paymentRequest}
            onDismiss={() => {
              paymentReturn.clearReturnParams()
              retry()
            }}
            onRetryReconcile={() => void paymentReturn.retryReconcile()}
            onRetryPayment={() => setPaymentOpen(true)}
          />
        )}

        {live && unauthenticated && (
          <div
            className="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-6 text-center"
            data-testid="vendor-pass-sign-in"
          >
            <LogIn className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">Sign in to view your event pass.</p>
            <Button
              nativeButton={false}
              render={
                <Link
                  href={getVendorLoginUrl({
                    intent: "vendor",
                    redirect: `/vendor/pass/${applicationId}`,
                  })}
                />
              }
            >
              Sign in
            </Button>
          </div>
        )}

        {packet && (
          <>
            <VendorPacketPreview
              packet={packet}
              onContinuePayment={() => setPaymentOpen(true)}
              footer={
                packet.status === "unlocked" ? (
                  <div className="flex flex-col gap-6 border-t pt-6">
                    <div>
                      <h3 className="mb-3 font-heading text-lg font-semibold">Event-day checklist</h3>
                      <VendorEventReadinessChecklist items={readiness.items} />
                    </div>
                    <VendorSupportPanel
                      requestId={applicationId}
                      items={supportItems}
                      loading={supportLoading}
                      submitting={supportSubmitting}
                      onSubmit={submitSupport}
                      wordpressUrl={packet.wordpressUrl}
                    />
                    {isCompletedRequest && reviewEligibility && (
                      <ReviewEligibilityBanner
                        item={reviewEligibility}
                        onFeedback={submitVendorReviewFeedback}
                      />
                    )}
                  </div>
                ) : undefined
              }
            />
          </>
        )}

        {paymentRequest && (
          <VendorPaymentDetailDrawer
            request={paymentRequest}
            open={paymentOpen}
            onOpenChange={setPaymentOpen}
            source="vendor_pass"
          />
        )}
      </div>
    </PanelContent>
  )
}

function LiveEventPassGate({
  rawId,
  forceLocked,
}: {
  rawId: string
  forceLocked: boolean
}) {
  const { items, loading, error, unauthenticated, retry } = useVendorRequests()
  const applicationId = useMemo(
    () => resolveLivePassApplicationId(rawId, items),
    [rawId, items],
  )

  if (loading) {
    return <SkeletonList count={1} />
  }

  if (unauthenticated) {
    return <EventPassContent applicationId={applicationId ?? rawId} forceLocked={forceLocked} />
  }

  if (error) {
    return (
      <PanelContent state="error" retry={retry}>
        {null}
      </PanelContent>
    )
  }

  if (!applicationId) {
    return (
      <div className="flex flex-col gap-4">
        <Button
          variant="ghost"
          size="sm"
          className="w-fit -ml-2"
          nativeButton={false}
          render={<Link href="/vendor/requests" />}
        >
          <ArrowLeft data-icon="inline-start" />
          Back to requests
        </Button>
        <EmptyState
          icon={Ticket}
          title="Event pass not found"
          description="This link does not match one of your applications. Open requests and try again from there."
          action={
            <Button nativeButton={false} render={<Link href="/vendor/requests" />}>
              View requests
            </Button>
          }
        />
      </div>
    )
  }

  return <EventPassContent applicationId={applicationId} forceLocked={forceLocked} />
}

function EventPassPageInner({
  params,
  searchParams,
}: {
  params: Promise<{ applicationId: string }>
  searchParams: Promise<{ locked?: string; payment?: string }>
}) {
  const { applicationId: rawId } = use(params)
  const { locked } = use(searchParams)
  const forceLocked = locked === "1"

  if (isLiveApiEnabled()) {
    return <LiveEventPassGate rawId={rawId} forceLocked={forceLocked} />
  }

  const applicationId = resolveApplicationId(rawId)
  if (!applicationId) notFound()

  return <EventPassContent applicationId={applicationId} forceLocked={forceLocked} />
}

export default function EventPassPage({
  params,
  searchParams,
}: {
  params: Promise<{ applicationId: string }>
  searchParams: Promise<{ locked?: string; payment?: string }>
}) {
  return (
    <Suspense fallback={<SkeletonList count={1} />}>
      <EventPassPageInner params={params} searchParams={searchParams} />
    </Suspense>
  )
}
