import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorRequestList, mapWpVendorApplication } from "@/lib/api/vendor-requests"
import {
  enrichOperationsEvent,
  mapVendorApplicationToOperationsEvent,
  mockVendorOperationsEvents,
  type VendorOperationsEvent,
  type WpVendorHomeResponse,
} from "@/lib/api/vendor-operations"
import { mapVendorPacket, type WpPacketPassResponse } from "@/lib/api/vendor-packet"
import type { WpVendorApplicationsResponse } from "@/lib/api/types"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

const ENRICH_STATUSES = new Set(["paid", "completed", "payment_required", "approved"])

import type { VendorRequest } from "@/lib/api/vendor-requests"

async function enrichEvent(event: VendorOperationsEvent, request: VendorRequest, auth: ReturnType<typeof toWpAuth>) {
  try {
    const [vendorHome, passData] = await Promise.all([
      wpFetchJson<WpVendorHomeResponse>("/operations/vendor-home", {
        auth,
        query: { application_id: event.requestId },
      }).catch(() => null),
      wpFetchJson<WpPacketPassResponse>(`/packets/pass/${event.requestId}`, { auth }).catch(() => null),
    ])

    const packet = passData ? mapVendorPacket(event.requestId, passData) : undefined
    let mapped = mapVendorApplicationToOperationsEvent(request, packet)
    mapped = enrichOperationsEvent(mapped, vendorHome)
    return mapped
  } catch {
    return event
  }
}

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockVendorOperationsEvents() })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your event operations.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", { auth })
    const requests = mapVendorRequestList(data)

    const baseEvents = requests
      .filter((r) => r.status !== "rejected" && r.status !== "cancelled")
      .map((r) => mapVendorApplicationToOperationsEvent(r))

    const toEnrich = requests.filter((r) => ENRICH_STATUSES.has(r.status))
    const enriched = await Promise.all(
      toEnrich.map((r) => {
        const base = mapVendorApplicationToOperationsEvent(r)
        return enrichEvent(base, r, auth)
      }),
    )

    const enrichedById = new Map(enriched.map((e) => [e.requestId, e]))
    const items = baseEvents.map((e) => enrichedById.get(e.requestId) ?? e)

    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/operations",
      fallbackMessage: "We couldn't load your event operations. Please try again.",
      fallbackCode: "operations_failed",
      forbiddenMessage: "You don't have access to event operations.",
    })
  }
}
