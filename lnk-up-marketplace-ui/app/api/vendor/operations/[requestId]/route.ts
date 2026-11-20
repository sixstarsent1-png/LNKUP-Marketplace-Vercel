import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  enrichOperationsEvent,
  mapVendorApplicationToOperationsEvent,
  mockVendorOperationsEvents,
  type WpVendorHomeResponse,
} from "@/lib/api/vendor-operations"
import { mapVendorPacket, type WpPacketPassResponse } from "@/lib/api/vendor-packet"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ requestId: string }> }

function requestFromPacket(requestId: string, packet: ReturnType<typeof mapVendorPacket>): VendorRequest {
  return {
    id: requestId,
    eventName: packet.eventName,
    eventDate: packet.eventDate,
    city: packet.city,
    state: packet.state,
    organizerName: packet.organizerName,
    status: packet.status === "locked" ? "payment_required" : "paid",
  }
}

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { requestId } = await context.params

    if (!isLiveApiEnabled()) {
      const item = mockVendorOperationsEvents().find((e) => e.requestId === requestId)
      if (!item) {
        return NextResponse.json(
          { code: "not_found", message: "We couldn't find that event operation." },
          { status: 404 },
        )
      }
      return NextResponse.json(item)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view event operations.")
    }

    const auth = toWpAuth(session)

    const [vendorHome, passData] = await Promise.all([
      wpFetchJson<WpVendorHomeResponse>("/operations/vendor-home", {
        auth,
        query: { application_id: requestId },
      }).catch(() => null),
      wpFetchJson<WpPacketPassResponse>(`/packets/pass/${requestId}`, { auth }),
    ])

    const packet = mapVendorPacket(requestId, passData)
    const request = requestFromPacket(requestId, packet)
    let event = mapVendorApplicationToOperationsEvent(request, packet)
    event = enrichOperationsEvent(event, vendorHome)

    return NextResponse.json(event)
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that event operation." },
        { status: 404 },
      )
    }
    return toErrorResponse(error, {
      context: "vendor/operations/detail",
      fallbackMessage: "We couldn't load event operations. Please try again.",
      fallbackCode: "operations_detail_failed",
      forbiddenMessage: "You don't have access to this event operation.",
    })
  }
}
