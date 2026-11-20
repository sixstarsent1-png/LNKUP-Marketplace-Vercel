import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapOrganizerEventMoney,
  mockOrganizerEventMoney,
} from "@/lib/api/organizer-financial"
import { mapInviteOptionToEventLite } from "@/lib/api/organizer-events"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

const MAX_EVENTS = 10

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        items: [mockOrganizerEventMoney],
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view event finances.")
    }

    const auth = toWpAuth(session)
    const inviteRaw = await wpFetchJson<{ items?: unknown[] } | unknown[]>("/crm/events/invite-options", { auth })
    const inviteList = Array.isArray(inviteRaw)
      ? inviteRaw
      : Array.isArray(inviteRaw?.items)
        ? inviteRaw.items
        : []

    const baseEvents = inviteList
      .slice(0, MAX_EVENTS)
      .map((item) => mapInviteOptionToEventLite(item as never))
      .filter((event) => event.id)

    const items = await Promise.all(
      baseEvents.map(async (event) => {
        try {
          const financials = await wpFetchJson(`/financial/events/${event.id}/financials`, { auth })
          return mapOrganizerEventMoney(event.id, financials, event.title)
        } catch {
          return mapOrganizerEventMoney(event.id, {}, event.title)
        }
      }),
    )

    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/money/events",
      fallbackMessage: "We couldn't load event finances.",
      fallbackCode: "organizer_money_events_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
