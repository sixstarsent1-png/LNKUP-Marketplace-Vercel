"use client"

import Link from "next/link"
import type { VendorReviewEligibility } from "@/lib/api/vendor-reputation"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

export function ReviewEligibilityBanner({
  item,
  onFeedback,
}: {
  item: VendorReviewEligibility
  onFeedback?: (item: VendorReviewEligibility) => void
}) {
  switch (item.status) {
    case "awaiting_organizer_review":
      return (
        <Alert data-testid="review-eligibility-awaiting">
          <AlertTitle>Review pending from organizer</AlertTitle>
          <AlertDescription>
            {item.eventName} — your organizer review will appear here after the event is rated.
          </AlertDescription>
        </Alert>
      )
    case "review_received":
      return (
        <Alert data-testid="review-eligibility-received">
          <AlertTitle>Organizer reviewed you</AlertTitle>
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>{item.eventName} — see your review on the Reviews page.</span>
            <Button size="sm" variant="outline" nativeButton={false} render={<Link href="/vendor/reviews" />}>
              View reviews
            </Button>
          </AlertDescription>
        </Alert>
      )
    case "vendor_feedback_available":
      return (
        <Alert data-testid="review-eligibility-feedback">
          <AlertTitle>Share feedback about this event</AlertTitle>
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>{item.eventName}</span>
            {onFeedback && (
              <Button size="sm" variant="outline" onClick={() => onFeedback(item)}>
                Share feedback
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )
    default:
      return null
  }
}
