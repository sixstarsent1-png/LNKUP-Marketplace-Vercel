import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapFulfillment, type WpFulfillmentResponse } from "@/lib/api/match"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

/** Vendor slot fulfillment for one of the organizer's events (owner or event staff only; enforced by WordPress). */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your events.")
    }
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "invalid_event", message: "Choose an event." }, { status: 400 })
    }
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mapFulfillment(undefined))
    }
    const data = await wpFetchJson<WpFulfillmentResponse>(`/organizer/events/${id}/fulfillment`, { auth: toWpAuth(session) })
    return NextResponse.json(mapFulfillment(data))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/events/fulfillment",
      fallbackMessage: "We couldn't load vendor slots for this event.",
      fallbackCode: "event_fulfillment_failed",
      forbiddenMessage: "You can only view slots for your own events.",
    })
  }
}
