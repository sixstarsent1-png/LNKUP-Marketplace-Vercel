import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapOrganizerActivityList,
  mapOrganizerEventMoney,
  mockOrganizerEventMoney,
} from "@/lib/api/organizer-financial"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { id: eventId } = await context.params

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        summary: { ...mockOrganizerEventMoney, eventId },
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view event finances.")
    }

    const auth = toWpAuth(session)
    const financials = await wpFetchJson(`/financial/events/${eventId}/financials`, { auth })

    let activity: unknown = null
    try {
      activity = await wpFetchJson("/financial/activity", {
        auth,
        query: { role: "organizer", event_id: eventId, limit: 5 },
      })
    } catch {
      activity = null
    }

    const summary = mapOrganizerEventMoney(eventId, financials)
    if (activity) {
      summary.recentActivity = mapOrganizerActivityList(activity).slice(0, 5)
    }

    return NextResponse.json({ summary })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/events/money",
      fallbackMessage: "We couldn't load event finances.",
      fallbackCode: "organizer_event_money_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
