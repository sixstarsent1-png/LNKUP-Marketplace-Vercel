import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { mapRecruitment, type WpRecruitmentResponse } from "@/lib/api/match"

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to recruit vendors.")
    }
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "invalid_event", message: "Choose an event." }, { status: 400 })
    }
    const data = await wpFetchJson<WpRecruitmentResponse>(`/match/organizer/events/${id}/recruitment`, {
      auth: toWpAuth(session),
    })
    return NextResponse.json(mapRecruitment(data))
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/recruitment",
      fallbackMessage: "We couldn't load recruitment tools.",
      fallbackCode: "match_recruitment_failed",
      forbiddenMessage: "You can only recruit vendors for your own events.",
    })
  }
}
