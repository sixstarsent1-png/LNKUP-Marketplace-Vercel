import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { parseInviteRequest } from "@/lib/api/match-request"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import {
  mapInviteJob,
  mapInvitePreview,
  type WpInviteJobResponse,
  type WpInvitePreviewResponse,
} from "@/lib/api/match"

/**
 * Batch invites: `preview` returns recipients and suppression reasons; `send`
 * queues an organizer-approved job that WordPress revalidates per recipient.
 */
export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to invite vendors.")
    }
    const parsed = parseInviteRequest(await request.json().catch(() => null))
    if (!parsed) {
      return NextResponse.json({ code: "invalid_request", message: "Choose vendors to invite." }, { status: 400 })
    }
    const { mode, eventId, opportunityId, profileIds, message } = parsed
    const body = {
      event_id: eventId,
      opportunity_id: opportunityId,
      profile_ids: profileIds,
      message,
    }
    const auth = toWpAuth(session)

    if (mode === "preview") {
      const data = await wpFetchJson<WpInvitePreviewResponse>("/match/organizer/invites/preview", { auth, body })
      return NextResponse.json({ mode, preview: mapInvitePreview(data) })
    }
    const data = await wpFetchJson<WpInviteJobResponse>("/match/organizer/invites/batch", { auth, body })
    return NextResponse.json({ mode, job: mapInviteJob(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/invites",
      fallbackMessage: "We couldn't send those invites.",
      fallbackCode: "match_invites_failed",
      forbiddenMessage: "You can only invite vendors to your own events.",
    })
  }
}
