import { NextResponse } from "next/server"
import { isLiveApiEnabled, getOrganizerDashboardUrl } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  type OrganizerOnboardingStep,
  type OrganizerOnboardingState,
  type WpOnboardingPayload,
  buildMockOnboardingState,
  computeLaunchReadiness,
  nextOnboardingStep,
  toServerStep,
  ONBOARDING_STEP_ORDER,
} from "@/lib/api/organizer-onboarding"
import { WpFetchError, wpFetchJson, type WpAuth } from "@/lib/api/wp-fetch"
import {
  getOrganizerOnboardingCookie,
  setOrganizerOnboardingCookie,
} from "@/lib/auth/organizer-draft-cookies"
import { getSession, toWpAuth } from "@/lib/auth/session"

type OnboardingCookie = {
  currentStep: OrganizerOnboardingStep
  completedSteps: OrganizerOnboardingStep[]
  skippedSteps?: OrganizerOnboardingStep[]
  draft?: OrganizerOnboardingState["draft"]
}

/**
 * WordPress guided setup, or null when it is switched off (404) or unreachable — the cookie
 * wizard keeps working either way.
 */
async function fetchServerOnboarding(auth: WpAuth): Promise<WpOnboardingPayload | null> {
  try {
    const res = await wpFetchJson<{ success?: boolean; data?: WpOnboardingPayload }>("/organizer/onboarding", { auth })
    return res?.data ?? null
  } catch (error) {
    if (!(error instanceof WpFetchError && error.status === 404)) {
      console.warn("organizer/onboarding: guided setup unavailable", error)
    }
    return null
  }
}

async function patchServerOnboarding(auth: WpAuth, changes: Record<string, unknown>): Promise<void> {
  try {
    await wpFetchJson("/organizer/onboarding", { method: "PATCH", body: changes, auth })
  } catch (error) {
    if (!(error instanceof WpFetchError && (error.status === 404 || error.status === 400))) {
      console.warn("organizer/onboarding: could not save guided setup step", error)
    }
  }
}

function buildState(cookie: OnboardingCookie | null, server: WpOnboardingPayload | null = null): OrganizerOnboardingState {
  const completed = cookie?.completedSteps ?? []
  const currentStep = cookie?.currentStep ?? "organizer_basics"
  const wpUrl = getOrganizerDashboardUrl()
  const firstEvent = server?.steps.find((s) => s.key === "first_event")

  const fallbackAction =
    currentStep === "complete"
      ? { label: "Launch event", href: "/organizer/launch" }
      : wpUrl && completed.includes("publish_or_handoff")
        ? { label: "Finish on LNKup web", href: `${wpUrl}#create` }
        : { label: "Continue setup", href: "/organizer/onboarding" }

  return {
    currentStep,
    completedSteps: completed,
    skippedSteps: cookie?.skippedSteps ?? [],
    launchReadiness: server ? server.percent : computeLaunchReadiness(completed),
    hasEvent: firstEvent ? firstEvent.status !== "not_started" : completed.includes("event_details"),
    hasOpportunity: firstEvent ? firstEvent.status === "complete" : completed.includes("fees_spots_deadline"),
    draft: cookie?.draft,
    server,
    nextAction:
      server && !server.is_complete && server.next_step
        ? { label: server.next_step.title, href: server.next_step.url }
        : fallbackAction,
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
    const server = await fetchServerOnboarding(toWpAuth(session))
    return NextResponse.json(buildState(cookie, server))
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
        skippedSteps: skip ? [current] : [],
        launchReadiness: computeLaunchReadiness(Array.from(completed)),
        draft: draft ?? mock.draft,
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }
    const auth = toWpAuth(session)

    const existing = (await getOrganizerOnboardingCookie<OnboardingCookie>()) ?? {
      currentStep: "organizer_basics" as OrganizerOnboardingStep,
      completedSteps: [] as OrganizerOnboardingStep[],
    }

    const current = step ?? existing.currentStep
    const completed = new Set(existing.completedSteps)
    const skipped = new Set(existing.skippedSteps ?? [])
    if (action === "advance" && ONBOARDING_STEP_ORDER.includes(current)) {
      if (skip) {
        skipped.add(current)
        completed.delete(current)
      } else {
        completed.add(current)
        skipped.delete(current)
      }
    }

    const next = action === "complete" ? ("complete" as OrganizerOnboardingStep) : nextOnboardingStep(current)
    const mergedDraft = { ...existing.draft, ...draft }

    // Organization basics belong to the organizer's WordPress profile, not just this browser.
    if (current === "organizer_basics" && !skip && (draft?.organizationName || draft?.city || draft?.state)) {
      const profile: Record<string, string> = {}
      if (draft?.organizationName) profile.public_name = draft.organizationName
      if (draft?.city) profile.city = draft.city
      if (draft?.state) profile.state = draft.state
      await wpFetchJson("/organizer/profile", { method: "POST", body: profile, auth })
    }

    const serverSkip = skip && ["format", "payouts"].includes(toServerStep(current)) ? { skip: toServerStep(current) } : {}
    await patchServerOnboarding(auth, { current_step: toServerStep(next), ...serverSkip })

    const updated: OnboardingCookie = {
      currentStep: next,
      completedSteps: Array.from(completed),
      skippedSteps: Array.from(skipped),
      draft: mergedDraft,
    }
    await setOrganizerOnboardingCookie(updated)

    return NextResponse.json(buildState(updated, await fetchServerOnboarding(auth)))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/onboarding",
      fallbackMessage: "We couldn't save onboarding progress.",
      fallbackCode: "onboarding_save_failed",
    })
  }
}
