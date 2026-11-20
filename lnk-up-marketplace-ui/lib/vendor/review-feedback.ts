import { isLiveApiEnabled } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { VendorReviewEligibility } from "@/lib/api/vendor-reputation"

export async function submitVendorReviewFeedback(item: VendorReviewEligibility): Promise<void> {
  trackLnkupEvent({
    event: "vendor_feedback_started",
    source: "review_eligibility",
    requestId: item.applicationId,
  })

  const response = await fetch("/api/vendor/reviews/feedback", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ applicationId: item.applicationId }),
  })

  const data = (await response.json()) as {
    status?: string
    wordpressUrl?: string
    message?: string
  }

  if (isLiveApiEnabled() && data.status === "handoff_required" && data.wordpressUrl) {
    window.open(data.wordpressUrl, "_blank", "noopener,noreferrer")
  }

  trackLnkupEvent({
    event: "vendor_feedback_submitted",
    source: "review_eligibility",
    requestId: item.applicationId,
    metadata: { handoff: data.status === "handoff_required" },
  })
}
