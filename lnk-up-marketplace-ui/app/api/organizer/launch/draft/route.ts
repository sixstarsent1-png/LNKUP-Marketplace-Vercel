import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import type { OrganizerOnboardingState } from "@/lib/api/organizer-onboarding"
import {
  type OrganizerLaunchDraft,
  buildMockLaunchDraft,
  emptyLaunchDraft,
  hasMeaningfulLaunchDraft,
  launchDraftFromOnboarding,
  validateLaunchDraft,
  withLaunchClientToken,
} from "@/lib/api/organizer-launch"
import {
  getOrganizerLaunchDraftCookie,
  getOrganizerOnboardingCookie,
  setOrganizerLaunchDraftCookie,
} from "@/lib/auth/organizer-draft-cookies"
import { getSession } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(buildMockLaunchDraft())
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const draft = await getOrganizerLaunchDraftCookie<OrganizerLaunchDraft>()
    if (draft && hasMeaningfulLaunchDraft(draft)) {
      return NextResponse.json(draft)
    }

    const onboarding = await getOrganizerOnboardingCookie<OrganizerOnboardingState>()
    const seeded = launchDraftFromOnboarding(onboarding)
    if (seeded) {
      return NextResponse.json(seeded)
    }

    return NextResponse.json(draft ?? emptyLaunchDraft())
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/launch/draft",
      fallbackMessage: "We couldn't load your launch draft.",
      fallbackCode: "launch_draft_failed",
    })
  }
}

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as OrganizerLaunchDraft

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ...body, status: "draft" as const })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const errors = validateLaunchDraft(body)
    const draft: OrganizerLaunchDraft = withLaunchClientToken({
      ...body,
      status: errors.length === 0 ? "ready_to_publish" : "draft",
    })
    await setOrganizerLaunchDraftCookie(draft)
    return NextResponse.json({ ...draft, validationErrors: errors })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/launch/draft",
      fallbackMessage: "We couldn't save your launch draft.",
      fallbackCode: "launch_draft_save_failed",
    })
  }
}
