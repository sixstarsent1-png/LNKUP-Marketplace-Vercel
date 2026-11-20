import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import {
  mapVendorPacket,
  type WpPacketPassResponse,
} from "@/lib/api/vendor-packet"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your event pass.")
    }

    const { id } = await context.params
    const auth = toWpAuth(session)

    const data = await wpFetchJson<WpPacketPassResponse>(`/packets/pass/${id}`, { auth })
    const packet = mapVendorPacket(id, data)

    return NextResponse.json(packet)
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that event pass." },
        { status: 404 },
      )
    }
    return toErrorResponse(error, {
      context: "vendor/requests/packet",
      fallbackMessage: "We couldn't load your event pass. Please try again.",
      fallbackCode: "packet_failed",
      forbiddenMessage: "You don't have access to this event pass.",
    })
  }
}
