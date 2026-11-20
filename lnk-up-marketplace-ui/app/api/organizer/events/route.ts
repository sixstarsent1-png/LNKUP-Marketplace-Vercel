import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  enrichEventWithRoster,
  mapInviteOptionToEventLite,
  type OrganizerEventLite,
} from "@/lib/api/organizer-events"
import { getOrganizerDashboardUrl } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your events.")
    }

    const auth = toWpAuth(session)
    const inviteRaw = await wpFetchJson<{ items?: unknown[] } | unknown[]>("/crm/events/invite-options", { auth })
    const inviteList = Array.isArray(inviteRaw)
      ? inviteRaw
      : Array.isArray(inviteRaw?.items)
        ? inviteRaw.items
        : []
    const baseEvents = inviteList
      .slice(0, 20)
      .map((item) => mapInviteOptionToEventLite(item as never))

    const enriched = await Promise.all(
      baseEvents.map(async (event) => {
        if (!event.id) return event
        try {
          const roster = await wpFetchJson<{ groups?: unknown; total?: number }>(
            `/crm/events/${event.id}/roster`,
            { auth },
          )
          return enrichEventWithRoster(event, roster as never)
        } catch {
          return event
        }
      }),
    )

    const dash = getOrganizerDashboardUrl()
    const items: OrganizerEventLite[] = enriched.map((e) => ({
      ...e,
      wordpressUrl: e.wordpressUrl || (dash ? `${dash}?view=operations&event=${e.id}` : undefined),
    }))

    return NextResponse.json({ items, total: items.length })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/events",
      fallbackMessage: "We couldn't load your events. Please try again.",
      fallbackCode: "organizer_events_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
