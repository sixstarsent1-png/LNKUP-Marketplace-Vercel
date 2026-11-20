import type { VendorReputationSummary } from "@/lib/api/vendor-reputation"
import type { VendorReviewEligibility } from "@/lib/api/vendor-reputation"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import type { VendorOnboardingState } from "@/lib/api/vendor-onboarding"
import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import { filterBooked, filterPaymentRequired } from "@/lib/hooks/use-vendor-requests"
import { isEventDay, isEventWithinHours } from "@/lib/vendor/event-readiness"

export type VendorNextAction = {
  label: string
  href: string
  description?: string
  priority:
    | "payment"
    | "pass"
    | "event_day"
    | "documents"
    | "reputation"
    | "review"
    | "onboarding"
    | "profile"
    | "discover"
}

export function resolveVendorNextAction(input: {
  requests: VendorRequest[]
  onboarding?: VendorOnboardingState | null
  readiness?: VendorReadiness | null
  reputation?: VendorReputationSummary | null
  reviewEligibility?: VendorReviewEligibility[]
}): VendorNextAction {
  const payment = filterPaymentRequired(input.requests)[0]
  if (payment) {
    return {
      label: "Continue payment",
      href: `/vendor/requests?pay=${payment.id}`,
      description: `${payment.eventName} — complete your booth fee.`,
      priority: "payment",
    }
  }

  const eventDay = input.requests.find(
    (r) =>
      (r.status === "paid" || r.status === "completed") &&
      (isEventDay(r.eventDate) || isEventWithinHours(r.eventDate, 72)),
  )
  if (eventDay) {
    return {
      label: "Review event-day details",
      href: `/vendor/pass/${eventDay.id}`,
      description: `${eventDay.eventName} — check load-in and check-in.`,
      priority: "event_day",
    }
  }

  const booked = filterBooked(input.requests)[0]
  if (booked) {
    return {
      label: "View event pass",
      href: `/vendor/pass/${booked.id}`,
      description: booked.eventName,
      priority: "pass",
    }
  }

  const docsMissing = input.readiness?.missingItems.some(
    (m) =>
      m.severity === "required" &&
      (m.label.toLowerCase().includes("document") ||
        m.label.toLowerCase().includes("insurance") ||
        m.label.toLowerCase().includes("permit")),
  )
  if (docsMissing) {
    return {
      label: "Upload documents",
      href: "/vendor/documents",
      description: "Required documents are missing for upcoming events.",
      priority: "documents",
    }
  }

  const reviewReceived = input.reviewEligibility?.some((e) => e.status === "review_received")
  if (reviewReceived) {
    return {
      label: "View your reviews",
      href: "/vendor/reviews",
      description: "An organizer left a review — see how you're doing.",
      priority: "reputation",
    }
  }

  const underReview = input.requests.filter(
    (r) => r.status === "submitted" || r.status === "under_review",
  ).length
  if (underReview > 0) {
    return {
      label: "View requests",
      href: "/vendor/requests",
      description: `${underReview} request${underReview === 1 ? "" : "s"} under review.`,
      priority: "review",
    }
  }

  if (input.onboarding && input.onboarding.currentStep !== "complete") {
    return {
      label: "Continue setup",
      href: "/vendor/onboarding",
      description: "Finish your vendor profile to get reviewed faster.",
      priority: "onboarding",
    }
  }

  const completion = input.readiness?.profileCompletion ?? input.onboarding?.profileCompletion ?? 0
  if (completion < 70) {
    return {
      label: "Complete profile",
      href: "/vendor/profile",
      description: "Get reviewed faster with a complete profile.",
      priority: "profile",
    }
  }

  const isNewVendor =
    !input.reputation ||
    input.reputation.tier === "new" ||
    input.reputation.reviewCount === 0
  if (isNewVendor) {
    return {
      label: "Find your first event",
      href: "/vendor/find",
      description: "Book events to start building your reputation.",
      priority: "discover",
    }
  }

  if ((input.reputation?.reviewCount ?? 0) > 0) {
    return {
      label: "View reputation",
      href: "/vendor/reviews",
      description: "See your reviews, badges, and completed events.",
      priority: "reputation",
    }
  }

  return {
    label: "Find events",
    href: "/vendor/find",
    description: "Your next booth opportunity is waiting.",
    priority: "discover",
  }
}
