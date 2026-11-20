import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { getOrganizerDashboardUrl, isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  type OrganizerLaunchDraft,
  type OrganizerLaunchPublishResult,
  draftToWpPayload,
  mockPublishResult,
  validateLaunchDraft,
} from "@/lib/api/organizer-launch"
import {
  clearOrganizerLaunchDraftCookie,
  getOrganizerLaunchDraftCookie,
} from "@/lib/auth/organizer-draft-cookies"
import { getSession, toWpAuth } from "@/lib/auth/session"

function handoffResult(draft: OrganizerLaunchDraft): OrganizerLaunchPublishResult {
  const wp = getOrganizerDashboardUrl()
  return {
    status: "handoff_required",
    wordpressUrl: wp ? `${wp}#create` : undefined,
    message: "Launch event creation is available on LNKup web right now.",
    raw: { draftSummary: draft },
  }
}

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as OrganizerLaunchDraft | { useDraft?: boolean }
    const useDraft = "useDraft" in body && body.useDraft

    let draft: OrganizerLaunchDraft
    if (useDraft) {
      const stored = await getOrganizerLaunchDraftCookie<OrganizerLaunchDraft>()
      if (!stored) {
        return NextResponse.json(
          { status: "validation_error", message: "No saved draft found.", validationErrors: [] },
          { status: 400 },
        )
      }
      draft = stored
    } else {
      draft = body as OrganizerLaunchDraft
    }

    const validationErrors = validateLaunchDraft(draft)
    if (validationErrors.length > 0) {
      return NextResponse.json({
        status: "validation_error",
        message: "Add at least one vendor category before publishing.",
        validationErrors,
      } satisfies OrganizerLaunchPublishResult)
    }

    if (!isLiveApiEnabled()) {
      const result = mockPublishResult(draft)
      await clearOrganizerLaunchDraftCookie()
      return NextResponse.json(result)
    }

    const session = await getSession()
    if (!session) {
      return NextResponse.json(
        { status: "auth_required", message: "Sign in as an organizer to continue." } satisfies OrganizerLaunchPublishResult,
        { status: 401 },
      )
    }

    const auth = toWpAuth(session)
    const payload = draftToWpPayload(draft)

    try {
      const wpResult = await wpFetchJson<{
        event_id?: number
        opportunity_id?: number
        view_event_url?: string
        application_url?: string
      }>("/organizer/launch/publish", {
        method: "POST",
        auth,
        body: payload,
      })

      if (wpResult.event_id) {
        await clearOrganizerLaunchDraftCookie()
        const dash = getOrganizerDashboardUrl()
        return NextResponse.json({
          status: "published",
          eventId: String(wpResult.event_id),
          opportunityId: wpResult.opportunity_id ? String(wpResult.opportunity_id) : undefined,
          eventUrl: `/organizer/events/${wpResult.event_id}`,
          applicationUrl: wpResult.application_url,
          wordpressUrl: dash ? `${dash}?view=operations&event=${wpResult.event_id}` : wpResult.view_event_url,
          message: "Your event and vendor opportunity are live.",
        } satisfies OrganizerLaunchPublishResult)
      }
    } catch (error) {
      if (error instanceof WpFetchError && error.status !== 404) {
        if (error.status === 401) {
          return NextResponse.json(
            { status: "auth_required", message: "Sign in as an organizer to continue." },
            { status: 401 },
          )
        }
        if (error.status === 403) {
          return NextResponse.json(
            { status: "forbidden", message: "You don't have access to publish events." },
            { status: 403 },
          )
        }
        return NextResponse.json({
          status: "error",
          message: "We couldn't publish this opportunity. Try again or finish on LNKup web.",
        } satisfies OrganizerLaunchPublishResult)
      }
    }

    return NextResponse.json(handoffResult(draft))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/launch/publish",
      fallbackMessage: "We couldn't publish this opportunity.",
      fallbackCode: "launch_publish_failed",
    })
  }
}
