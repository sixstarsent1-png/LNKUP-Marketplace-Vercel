import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { parseShortlistRequest } from "@/lib/api/match-request"

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to shortlist vendors.")
    }
    const parsed = parseShortlistRequest(await request.json().catch(() => null))
    if (!parsed) {
      return NextResponse.json({ code: "invalid_request", message: "Choose vendors to shortlist." }, { status: 400 })
    }
    const data = await wpFetchJson<{ updated?: number[]; skipped?: unknown[] }>("/match/organizer/recruitment/shortlist", {
      auth: toWpAuth(session),
      body: {
        event_id: parsed.eventId,
        profile_ids: parsed.profileIds,
        action: parsed.action,
      },
    })
    return NextResponse.json({ updated: data.updated ?? [], skipped: data.skipped ?? [] })
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/shortlist",
      fallbackMessage: "We couldn't update your shortlist.",
      fallbackCode: "match_shortlist_failed",
      forbiddenMessage: "You can only shortlist vendors for your own events.",
    })
  }
}
