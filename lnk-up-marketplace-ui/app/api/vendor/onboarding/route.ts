import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpProfileStrength, WpVerificationVendor } from "@/lib/api/types"
import {
  deriveOnboardingState,
  type VendorOnboardingStep,
} from "@/lib/api/vendor-onboarding"
import { mockVendorOnboarding } from "@/lib/api/vendor-onboarding-mock"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  getOnboardingProgress,
  setOnboardingProgress,
  type OnboardingStepKey,
} from "@/lib/auth/onboarding-progress"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { isLiveApiEnabled } from "@/lib/api/config"

const VALID_STEPS = new Set<OnboardingStepKey>([
  "business",
  "categories",
  "service_area",
  "setup",
  "documents",
  "first_search",
])

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockVendorOnboarding)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to continue onboarding.")
    }

    const auth = toWpAuth(session)
    const progress = await getOnboardingProgress()

    let verification: WpVerificationVendor
    try {
      verification = await wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth })
    } catch {
      verification = {}
    }

    let strength: WpProfileStrength | undefined
    try {
      strength = await wpFetchJson<WpProfileStrength>("/growth/profile-strength", { auth })
    } catch {
      strength = undefined
    }

    const state = deriveOnboardingState(
      strength,
      verification,
      progress,
      String(session.userId ?? session.username),
    )

    return NextResponse.json(state)
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/onboarding",
      fallbackMessage: "We couldn't load onboarding progress.",
      fallbackCode: "onboarding_failed",
    })
  }
}

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as {
      step?: VendorOnboardingStep
      action?: "complete" | "skip"
    }

    if (!body.step || !VALID_STEPS.has(body.step as OnboardingStepKey)) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid onboarding step." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ success: true, mocked: true })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to save onboarding progress.")
    }

    const progress = await getOnboardingProgress()
    const step = body.step as OnboardingStepKey

    if (body.action === "skip") {
      if (!progress.skippedSteps.includes(step)) {
        progress.skippedSteps.push(step)
      }
    } else {
      if (!progress.completedSteps.includes(step)) {
        progress.completedSteps.push(step)
      }
    }
    progress.lastStep = step
    await setOnboardingProgress(progress)

    const auth = toWpAuth(session)
    let verification: WpVerificationVendor = {}
    let strength: WpProfileStrength | undefined
    try {
      verification = await wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth })
    } catch {
      verification = {}
    }
    try {
      strength = await wpFetchJson<WpProfileStrength>("/growth/profile-strength", { auth })
    } catch {
      strength = undefined
    }

    const state = deriveOnboardingState(
      strength,
      verification,
      progress,
      String(session.userId ?? session.username),
    )

    return NextResponse.json({ success: true, state })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/onboarding",
      fallbackMessage: "We couldn't save onboarding progress.",
      fallbackCode: "onboarding_save_failed",
    })
  }
}
