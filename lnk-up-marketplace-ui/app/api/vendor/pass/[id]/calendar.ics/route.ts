import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorPacket, mockVendorPacketUnlocked, type WpPacketPassResponse } from "@/lib/api/vendor-packet"
import { buildCalendarIcs } from "@/lib/vendor/calendar-ics"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { id } = await context.params

    let packet
    if (!isLiveApiEnabled()) {
      packet = { ...mockVendorPacketUnlocked, requestId: id, applicationId: id }
    } else {
      const session = await getSession()
      if (!session) {
        return authRequiredResponse("Please sign in to download your calendar event.")
      }

      const auth = toWpAuth(session)
      const data = await wpFetchJson<WpPacketPassResponse>(`/packets/pass/${id}`, { auth })
      packet = mapVendorPacket(id, data)
    }

    const ics = buildCalendarIcs(packet)
    if (!ics) {
      return NextResponse.json(
        {
          code: "calendar_unavailable",
          message: "Calendar download is available after your pass is unlocked with an event date.",
        },
        { status: 400 },
      )
    }

    return new NextResponse(ics, {
      status: 200,
      headers: {
        "Content-Type": "text/calendar; charset=utf-8",
        "Content-Disposition": `attachment; filename="lnkup-event-${id}.ics"`,
      },
    })
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that event pass." },
        { status: 404 },
      )
    }
    return toErrorResponse(error, {
      context: "vendor/pass/calendar",
      fallbackMessage: "We couldn't generate your calendar file. Please try again.",
      fallbackCode: "calendar_failed",
      forbiddenMessage: "You don't have access to this event pass.",
    })
  }
}
