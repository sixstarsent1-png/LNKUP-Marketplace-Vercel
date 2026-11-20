import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { MarketplaceMapPin } from "@/lib/api/marketplace-discovery"
import { mockMapPins } from "@/lib/api/marketplace-discovery-mock"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

type WpMapPinsResponse = {
  pins?: Array<{
    opportunity_id?: number
    event_id?: number
    title?: string
    city?: string
    state?: string
    lat?: number
    lng?: number
    vendor_fee?: number
    categories?: string[]
    deadline?: string
    application_status?: string
  }>
}

function mapPins(data: WpMapPinsResponse): MarketplaceMapPin[] {
  return (data.pins ?? [])
    .filter((p) => p.lat !== undefined && p.lng !== undefined)
    .map((p) => ({
      id: `pin-${p.opportunity_id}`,
      opportunityId: String(p.opportunity_id ?? ""),
      eventId: p.event_id ? String(p.event_id) : undefined,
      title: p.title ?? "Opportunity",
      city: p.city,
      state: p.state,
      lat: p.lat!,
      lng: p.lng!,
      eventDate: p.deadline,
      vendorFee: p.vendor_fee,
      category: p.categories?.[0],
      applicationStatus: p.application_status,
    }))
}

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url)
    const city = searchParams.get("city") ?? undefined
    const state = searchParams.get("state") ?? undefined
    const bbox = searchParams.get("bbox") ?? undefined

    if (!isLiveApiEnabled()) {
      let pins = mockMapPins()
      if (city) {
        pins = pins.filter((p) => p.city?.toLowerCase() === city.toLowerCase())
      }
      return NextResponse.json({ pins, mocked: true })
    }

    const data = await wpFetchJson<WpMapPinsResponse>("/marketplace/map-pins", {
      query: { city, state, bbox },
    })

    const pins = mapPins(data)
    return NextResponse.json({ pins, unavailable: pins.length === 0 })
  } catch (error) {
    return NextResponse.json(
      { pins: [], unavailable: true, message: "Map pins unavailable" },
      { status: 200 },
    )
  }
}
