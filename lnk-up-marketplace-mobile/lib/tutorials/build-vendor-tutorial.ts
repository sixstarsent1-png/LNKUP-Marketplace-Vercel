import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import {
  isPaymentDueRequest,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import type { RoleTutorial } from "@/lib/tutorials/tutorial-contract"

export function buildVendorTutorial(input: {
  requests: VendorRequest[]
  favoritesCount: number
  readiness?: VendorReadiness | null
}): RoleTutorial {
  const profileCompletion = input.readiness?.profileCompletion ?? 0
  const requiredGaps =
    (input.readiness?.blockers?.length ?? 0) +
    (input.readiness?.missingItems?.filter((item) => item.severity === "required").length ?? 0)
  const profileReady = profileCompletion >= 70 && requiredGaps === 0

  const hasDiscoverySignal = input.favoritesCount > 0 || input.requests.length > 0
  const hasApplication = input.requests.length > 0
  const paymentRequired = input.requests.filter(isPaymentDueRequest)
  const booked = input.requests.filter((r) => r.status === "paid" || r.status === "completed")
  const paymentComplete = booked.length > 0

  const nextPayment = paymentRequired[0]
  const nextBooked = booked[0]

  const steps = [
    {
      id: "profile_readiness" as const,
      label: "Finish your profile",
      description: "Add business details and required documents so organizers can approve you faster.",
      href: "/(vendor)/profile",
      ctaLabel: profileReady ? "Review profile" : "Continue setup",
      done: profileReady,
      icon: "person-outline" as const,
    },
    {
      id: "discover_opportunity" as const,
      label: "Save an event",
      description: "Browse events near you and save booths you want to apply for.",
      href: "/(vendor)/find",
      ctaLabel: "Find events",
      done: hasDiscoverySignal,
      icon: "search-outline" as const,
    },
    {
      id: "submit_application" as const,
      label: "Send an application",
      description: "Submit your application so organizers can review your fit.",
      href: hasApplication ? "/(vendor)/requests" : "/(vendor)/find",
      ctaLabel: hasApplication ? "View requests" : "Apply now",
      done: hasApplication,
      icon: "document-text-outline" as const,
    },
    {
      id: "complete_payment_pass" as const,
      label: "Unlock your pass",
      description: "Pay your booth fee and unlock load-in details for event day.",
      href: nextPayment
        ? `/pass/${nextPayment.id}`
        : nextBooked
          ? `/pass/${nextBooked.id}`
          : "/(vendor)/wallet",
      ctaLabel: nextPayment ? "Continue payment" : nextBooked ? "View pass" : "Open wallet",
      done: paymentComplete,
      icon: "ticket-outline" as const,
    },
  ]

  const completedCount = steps.filter((step) => step.done).length

  return {
    role: "vendor",
    title: "Get booked faster",
    subtitle: "Profile → find → apply → unlock your pass.",
    steps,
    completedCount,
    totalCount: steps.length,
    isComplete: completedCount === steps.length,
    pushHref: "/(vendor)/profile",
  }
}
