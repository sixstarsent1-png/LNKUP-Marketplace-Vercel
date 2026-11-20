"use client"

import { Suspense, useEffect, useMemo } from "react"
import Link from "next/link"
import { Ticket } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  groupOperationsEvents,
  OPS_STATUS_ORDER,
  OPS_STATUS_LABELS,
  type VendorOperationsEventStatus,
} from "@/lib/api/vendor-operations"
import { useVendorOperations } from "@/lib/hooks/use-vendor-operations"
import { useVendorReviewEligibility } from "@/lib/hooks/use-vendor-review-eligibility"
import { submitVendorReviewFeedback } from "@/lib/vendor/review-feedback"
import { usePanelState } from "@/lib/use-panel-state"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { VendorOperationsEventCard } from "@/components/vendor/operations/vendor-operations-event-card"
import { ReviewEligibilityBanner } from "@/components/reputation/review-eligibility-banner"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

const SECTION_TEST_IDS: Record<VendorOperationsEventStatus, string> = {
  payment_required: "vendor-operations-payment-required",
  pass_ready: "vendor-operations-pass-ready",
  event_day: "vendor-operations-event-day",
  upcoming: "vendor-operations-upcoming",
  completed: "vendor-operations-completed",
  cancelled: "vendor-operations-cancelled",
}

function OperationsContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, error, unauthenticated, retry } = useVendorOperations()
  const { items: eligibility } = useVendorReviewEligibility()

  const panelState = useMemo(() => {
    if (qaState === "error") return "error" as const
    if (qaState === "loading") return "loading" as const
    if (live && loading) return "loading" as const
    if (live && error) return "error" as const
    if (items.length === 0) return "empty" as const
    return "content" as const
  }, [qaState, live, loading, error, items.length])

  const groups = useMemo(() => groupOperationsEvents(items), [items])

  useEffect(() => {
    if (panelState === "content") {
      trackLnkupEvent({ event: "vendor_operations_viewed", source: "vendor_operations_page" })
    }
  }, [panelState])

  const handleRetry = () => {
    if (qaState === "error") qaRetry()
    else retry()
  }

  return (
    <div className="flex flex-col gap-6" data-testid="vendor-operations">
      <PageHeader title="Event Operations" subtitle="Passes, load-in details, and day-of info." />

      {!live && (
        <Alert>
          <AlertDescription>
            Demo mode — operations use sample data. Enable live API for LNKup-backed passes.
          </AlertDescription>
        </Alert>
      )}

      {live && unauthenticated && (
        <Alert>
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>Sign in to view your event operations.</span>
            <Button size="sm" nativeButton={false} render={<Link href="/login" />}>
              Sign in
            </Button>
          </AlertDescription>
        </Alert>
      )}

      <PanelContent
        state={panelState}
        retry={handleRetry}
        empty={
          <EmptyState
            icon={Ticket}
            title="No active events"
            description="Once you're approved and paid, event passes appear here."
          />
        }
      >
        <div className="flex flex-col gap-8">
          {OPS_STATUS_ORDER.map((status) => {
            const sectionItems = groups[status]
            if (sectionItems.length === 0) return null
            return (
              <section key={status} data-testid={SECTION_TEST_IDS[status]}>
                <h2 className="mb-3 font-heading text-lg font-semibold">{OPS_STATUS_LABELS[status]}</h2>
                <div className="flex flex-col gap-3">
                  {sectionItems.map((event) => {
                    const eligible =
                      status === "completed"
                        ? eligibility.find(
                            (item) =>
                              item.applicationId === event.requestId &&
                              item.status !== "not_eligible",
                          )
                        : undefined
                    return (
                      <div key={event.requestId} className="flex flex-col gap-2">
                        {eligible && (
                          <ReviewEligibilityBanner
                            item={eligible}
                            onFeedback={submitVendorReviewFeedback}
                          />
                        )}
                        <VendorOperationsEventCard event={event} />
                      </div>
                    )
                  })}
                </div>
              </section>
            )
          })}
        </div>

        <div className="mt-8 rounded-2xl border border-dashed p-4 text-center text-sm text-muted-foreground">
          Need help on event day? Open your event pass for support and check-in details.
        </div>
      </PanelContent>
    </div>
  )
}

export default function VendorOperationsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <OperationsContent />
    </Suspense>
  )
}
