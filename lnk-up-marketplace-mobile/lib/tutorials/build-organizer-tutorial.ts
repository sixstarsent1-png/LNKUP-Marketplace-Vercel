import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import type { RoleTutorial } from "@/lib/tutorials/tutorial-contract"

export function buildOrganizerTutorial(input: {
  summary?: Pick<OrganizerHomeSummary, "stats"> | null
  requests: OrganizerVendorRequest[]
}): RoleTutorial {
  const stats = input.summary?.stats
  const hasEvent = (stats?.events ?? 0) > 0
  const hasOpenOpportunity = (stats?.openOpportunities ?? 0) > 0
  const launchDone = hasEvent && hasOpenOpportunity

  const pendingReview = input.requests.filter(
    (item) => item.status === "under_review" || item.status === "submitted",
  ).length
  const reviewed =
    (stats?.approvedVendors ?? 0) > 0 ||
    input.requests.some((item) => item.status === "approved" || item.status === "paid")

  const paymentRequired = stats?.paymentRequired ?? 0
  const paymentsTracked = (stats?.paidVendors ?? 0) > 0 || paymentRequired > 0

  const firstEventId = input.requests.find((item) => item.eventId)?.eventId

  const steps = [
    {
      id: "launch_event" as const,
      label: "Launch an event",
      description: "Create an event and publish a vendor opportunity vendors can apply to.",
      href: launchDone
        ? firstEventId
          ? `/(organizer)/events/${firstEventId}`
          : "/(organizer)/events"
        : "/(organizer)/launch",
      ctaLabel: launchDone ? "View events" : "Launch first event",
      done: launchDone,
      icon: "calendar-outline" as const,
    },
    {
      id: "review_requests" as const,
      label: "Review vendors",
      description: "Approve strong fits and waitlist vendors when rows are full.",
      href:
        pendingReview > 0
          ? "/(organizer)/vendors?tab=requests"
          : firstEventId
            ? `/(organizer)/events/${firstEventId}`
            : "/(organizer)/vendors?tab=requests",
      ctaLabel: pendingReview > 0 ? "Review requests" : "Open vendor queue",
      done: reviewed,
      icon: "people-outline" as const,
    },
    {
      id: "track_payments" as const,
      label: "Collect booth fees",
      description: "Monitor booth fees and follow up on vendors awaiting payment.",
      href: "/(organizer)/money",
      ctaLabel: paymentRequired > 0 ? "View payment status" : "Open money center",
      done: paymentsTracked,
      icon: "cash-outline" as const,
    },
  ]

  const completedCount = steps.filter((step) => step.done).length

  return {
    role: "organizer",
    title: "Fill your first event",
    subtitle: "Launch, review vendors, and collect booth fees.",
    steps,
    completedCount,
    totalCount: steps.length,
    isComplete: completedCount === steps.length,
    pushHref: "/(organizer)/more",
  }
}
