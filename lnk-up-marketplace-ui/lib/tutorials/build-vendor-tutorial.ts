import type { VendorOnboardingState } from "@/lib/api/vendor-onboarding"
import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import type { RoleTutorial } from "@/lib/tutorials/tutorial-contract"
import { filterBooked, filterPaymentRequired } from "@/lib/hooks/use-vendor-requests"

export function buildVendorTutorial(input: {
  requests: VendorRequest[]
  favoritesCount: number
  onboarding?: VendorOnboardingState | null
  readiness?: VendorReadiness | null
}): RoleTutorial {
  const profileCompletion =
    input.readiness?.profileCompletion ?? input.onboarding?.profileCompletion ?? 0
  const onboardingComplete = input.onboarding?.currentStep === "complete"
  const requiredGaps =
    (input.readiness?.blockers?.length ?? 0) +
    (input.readiness?.missingItems?.filter((item) => item.severity === "required").length ?? 0)
  const profileReady =
    onboardingComplete || (profileCompletion >= 70 && requiredGaps === 0)

  const hasDiscoverySignal = input.favoritesCount > 0 || input.requests.length > 0
  const hasApplication = input.requests.length > 0
  const paymentRequired = filterPaymentRequired(input.requests)
  const booked = filterBooked(input.requests)
  const paymentComplete = booked.length > 0

  const nextPayment = paymentRequired[0]
  const nextBooked = booked[0]

  const steps = [
    {
      id: "profile_readiness" as const,
      label: "Complete profile and readiness",
      description: "Add business details and required documents so organizers can approve you faster.",
      href: profileReady ? "/vendor/profile" : "/vendor/onboarding",
      ctaLabel: profileReady ? "Review profile" : "Continue setup",
      done: profileReady,
    },
    {
      id: "discover_opportunity" as const,
      label: "Find and save an opportunity",
      description: "Browse events near you and save booths you want to apply for.",
      href: "/vendor/find",
      ctaLabel: "Find events",
      done: hasDiscoverySignal,
    },
    {
      id: "submit_application" as const,
      label: "Apply to an event",
      description: "Submit your application so organizers can review your fit.",
      href: hasApplication ? "/vendor/requests" : "/vendor/find",
      ctaLabel: hasApplication ? "View requests" : "Apply now",
      done: hasApplication,
    },
    {
      id: "complete_payment_pass" as const,
      label: "Complete payment and open your pass",
      description: "Pay your booth fee and unlock load-in details for event day.",
      href: nextPayment
        ? `/vendor/requests?pay=${nextPayment.id}`
        : nextBooked
          ? `/vendor/pass/${nextBooked.id}`
          : "/vendor/wallet",
      ctaLabel: nextPayment ? "Continue payment" : nextBooked ? "View pass" : "Open wallet",
      done: paymentComplete,
    },
  ]

  const completedCount = steps.filter((step) => step.done).length

  return {
    role: "vendor",
    title: "Vendor getting started",
    subtitle: "Follow these steps to go from profile to booked booth.",
    steps,
    completedCount,
    totalCount: steps.length,
    isComplete: completedCount === steps.length,
  }
}
