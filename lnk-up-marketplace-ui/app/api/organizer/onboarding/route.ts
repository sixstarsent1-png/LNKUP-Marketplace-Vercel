import { NextResponse } from "next/server"
import { isLiveApiEnabled, getOrganizerDashboardUrl } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  type OrganizerOnboardingStep,
  type OrganizerOnboardingState,
  buildMockOnboardingState,
  computeLaunchReadiness,
  nextOnboardingStep,
  ONBOARDING_STEP_ORDER,
} from "@/lib/api/organizer-onboarding"
import {
  getOrganizerOnboardingCookie,
  setOrganizerOnboardingCookie,
} from "@/lib/auth/organizer-draft-cookies"
import { getSession } from "@/lib/auth/session"

type OnboardingCookie = {
  currentStep: OrganizerOnboardingStep
  completedSteps: OrganizerOnboardingStep[]
  draft?: OrganizerOnboardingState["draft"]
}

function buildState(cookie: OnboardingCookie | null): OrganizerOnboardingState {
  const completed = cookie?.completedSteps ?? []
  const currentStep = cookie?.currentStep ?? "organizer_basics"
  const wpUrl = getOrganizerDashboardUrl()
  const launchReadiness = computeLaunchReadiness(completed)

  return {
    currentStep,
    completedSteps: completed,
    launchReadiness,
    hasEvent: completed.includes("event_details"),
    hasOpportunity: completed.includes("fees_spots_deadline"),
    draft: cookie?.draft,
    nextAction:
      currentStep === "complete"
        ? { label: "Launch event", href: "/organizer/launch" }
        : wpUrl && completed.includes("publish_or_handoff")
          ? { label: "Finish on LNKup web", href: `${wpUrl}#create` }
          : { label: "Continue setup", href: "/organizer/onboarding" },
  }
}

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(buildMockOnboardingState())
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const cookie = await getOrganizerOnboardingCookie<OnboardingCookie>()
    return NextResponse.json(buildState(cookie))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/onboarding",
      fallbackMessage: "We couldn't load onboarding progress.",
      fallbackCode: "onboarding_failed",
    })
  }
}

export async function POST(request: Request) {
  try {
    const body = await request.json()
    const action = String(body.action ?? "advance")
    const step = body.step as OrganizerOnboardingStep | undefined
    const draft = body.draft as OrganizerOnboardingState["draft"] | undefined
    const skip = Boolean(body.skip)

    if (!isLiveApiEnabled()) {
      const mock = buildMockOnboardingState()
      const current = step ?? mock.currentStep
      const completed = new Set(mock.completedSteps)
      if (!skip) completed.add(current)
      const next = nextOnboardingStep(current)
      return NextResponse.json({
        ...mock,
        currentStep: next,
        completedSteps: Array.from(completed),
        launchReadiness: computeLaunchReadiness(Array.from(completed)),
        draft: draft ?? mock.draft,
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const existing = (await getOrganizerOnboardingCookie<OnboardingCookie>()) ?? {
      currentStep: "organizer_basics" as OrganizerOnboardingStep,
      completedSteps: [] as OrganizerOnboardingStep[],
    }

    const current = step ?? existing.currentStep
    const completed = new Set(existing.completedSteps)
    if (action === "advance" && !skip && ONBOARDING_STEP_ORDER.includes(current)) {
      completed.add(current)
    }

    const next =
      action === "complete"
        ? ("complete" as OrganizerOnboardingStep)
        : skip
          ? nextOnboardingStep(current)
          : nextOnboardingStep(current)

    const updated: OnboardingCookie = {
      currentStep: next,
      completedSteps: Array.from(completed),
      draft: { ...existing.draft, ...draft },
    }
    await setOrganizerOnboardingCookie(updated)

    return NextResponse.json(buildState(updated))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/onboarding",
      fallbackMessage: "We couldn't save onboarding progress.",
      fallbackCode: "onboarding_save_failed",
    })
  }
}
