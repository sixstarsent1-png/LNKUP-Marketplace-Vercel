import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import type { RoleTutorial } from "@/lib/tutorials/tutorial-contract"
import { resolveOrganizerPrimaryCta } from "@/lib/organizer/primary-cta"

export function buildOrganizerTutorial(input: {
  summary?: OrganizerHomeSummary | null
  requests: OrganizerVendorRequest[]
  paymentRequiredCount?: number
}): RoleTutorial {
  const stats = input.summary?.stats
  const hasEvent = (stats?.events ?? 0) > 0
  const hasOpenOpportunity = (stats?.openOpportunities ?? 0) > 0
  const launchDone = hasEvent && hasOpenOpportunity

  const pendingReview = input.requests.filter((item) => item.status === "under_review").length
  const reviewed =
    (stats?.approvedVendors ?? 0) > 0 ||
    input.requests.some((item) => item.status === "approved" || item.status === "paid")

  const paymentRequired = input.paymentRequiredCount ?? stats?.paymentRequired ?? 0
  const paymentsTracked = (stats?.paidVendors ?? 0) > 0 || paymentRequired > 0

  const primaryCta = resolveOrganizerPrimaryCta(input.summary ?? null)
  const firstEvent = input.summary?.upcomingEvents[0]

  const steps = [
    {
      id: "launch_event" as const,
      label: "Launch your first event",
      description: "Create an event and publish a vendor opportunity vendors can apply to.",
      href: launchDone
        ? firstEvent
          ? `/organizer/events/${firstEvent.id}`
          : "/organizer/events"
        : "/organizer/launch",
      ctaLabel: launchDone ? "View event workspace" : primaryCta.label,
      done: launchDone,
    },
    {
      id: "review_requests" as const,
      label: "Review vendor requests",
      description: "Approve strong fits and waitlist vendors when rows are full.",
      href: pendingReview > 0 ? "/organizer/vendors" : firstEvent ? `/organizer/events/${firstEvent.id}` : "/organizer/vendors",
      ctaLabel: pendingReview > 0 ? "Review requests" : "Open vendor queue",
      done: reviewed,
    },
    {
      id: "track_payments" as const,
      label: "Track payments and operations",
      description: "Monitor booth fees and follow up on vendors awaiting payment.",
      href: paymentRequired > 0 ? "/organizer/money" : firstEvent ? `/organizer/events/${firstEvent.id}` : "/organizer/money",
      ctaLabel: paymentRequired > 0 ? "View payment status" : "Open money center",
      done: paymentsTracked,
    },
  ]

  const completedCount = steps.filter((step) => step.done).length

  return {
    role: "organizer",
    title: "Organizer getting started",
    subtitle: "Launch, review, and collect fees without leaving your command center.",
    steps,
    completedCount,
    totalCount: steps.length,
    isComplete: completedCount === steps.length,
  }
}
