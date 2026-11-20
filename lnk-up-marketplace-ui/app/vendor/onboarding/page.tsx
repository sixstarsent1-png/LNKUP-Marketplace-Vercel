"use client"

import { Suspense, useEffect } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { SessionExpiredCard } from "@/components/auth/session-expired-card"
import { VendorOnboardingStepper } from "@/components/vendor/vendor-onboarding-stepper"
import { ErrorState, SkeletonList } from "@/components/marketplace/states"
import { useVendorOnboarding } from "@/lib/hooks/use-vendor-onboarding"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { VendorOnboardingStep } from "@/lib/api/vendor-onboarding"

function OnboardingContent() {
  const router = useRouter()
  const { state, loading, error, unauthenticated, saving, saveStep, retry, isComplete } =
    useVendorOnboarding()

  useEffect(() => {
    trackLnkupEvent({ event: "vendor_onboarding_started", source: "onboarding_page" })
  }, [])

  useEffect(() => {
    if (isComplete && state?.currentStep === "complete") {
      /* stay on page to show completion CTAs */
    }
  }, [isComplete, state])

  if (unauthenticated) {
    return <SessionExpiredCard description="Sign in to continue setting up your vendor profile." />
  }

  if (loading) {
    return <SkeletonList count={2} />
  }

  if (error) {
    return <ErrorState description={error} onRetry={retry} />
  }

  if (!state) {
    return <ErrorState onRetry={retry} />
  }

  async function handleSave(step: VendorOnboardingStep, action: "complete" | "skip") {
    const result = await saveStep(step, action)
    if (result.success && action === "complete" && step === "first_search") {
      router.push("/vendor/find")
    }
  }

  return (
    <VendorOnboardingStepper state={state} saving={saving} onSave={handleSave} />
  )
}

export default function VendorOnboardingPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OnboardingContent />
    </Suspense>
  )
}
