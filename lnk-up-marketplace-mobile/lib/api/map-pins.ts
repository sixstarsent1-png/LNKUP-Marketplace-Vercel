import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_OPPORTUNITIES } from "@/lib/data/fixtures"

export type MarketplaceMapPin = {
  id: string
  opportunityId: number
  eventId?: number
  title: string
  city?: string
  state?: string
  lat: number
  lng: number
  eventDate?: string
  vendorFee?: number | null
  category?: string
  spotsRemaining?: number
  /** Vendor options at this event (pins are one per event). */
  optionCount?: number
}

/**
 * One pin per event: vendor options at the same event share a marker, keeping the lowest fee
 * and the number of options. Pins without an event stay one per opportunity.
 */
export function groupPinsByEvent(pins: MarketplaceMapPin[]): MarketplaceMapPin[] {
  const byKey = new Map<string, MarketplaceMapPin>()
  for (const pin of pins) {
    const key = pin.eventId ? `e${pin.eventId}` : `o${pin.opportunityId}`
    const existing = byKey.get(key)
    if (!existing) {
      byKey.set(key, { ...pin, id: `pin-${key}`, optionCount: 1 })
      continue
    }
    const fees = [existing.vendorFee, pin.vendorFee].filter((f): f is number => typeof f === "number" && f > 0)
    byKey.set(key, {
      ...existing,
      optionCount: (existing.optionCount ?? 1) + 1,
      vendorFee: fees.length ? Math.min(...fees) : existing.vendorFee,
      spotsRemaining: (existing.spotsRemaining ?? 0) + (pin.spotsRemaining ?? 0),
    })
  }
  return [...byKey.values()]
}

export type MapPinsResult = {
  pins: MarketplaceMapPin[]
  unavailable: boolean
  mocked?: boolean
}

type WpMapPin = {
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
  spots_remaining?: number
}

type WpMapPinsResponse = {
  pins?: WpMapPin[]
}

export type MapPinsQuery = {
  city?: string
  state?: string
  /** Optional client-side near-me filter (pins API has no radius param). */
  lat?: number
  lng?: number
  radiusMiles?: number
}

const CITY_COORDS: Record<string, { lat: number; lng: number }> = {
  Charlotte: { lat: 35.2271, lng: -80.8431 },
  Atlanta: { lat: 33.749, lng: -84.388 },
  Miami: { lat: 25.7617, lng: -80.1918 },
  Nashville: { lat: 36.1627, lng: -86.7816 },
  Raleigh: { lat: 35.7796, lng: -78.6382 },
}

const DEFAULT_CENTER = { lat: 35.2271, lng: -80.8431 }

export function defaultMapRegion(pins: MarketplaceMapPin[]) {
  if (pins.length === 0) {
    return {
      latitude: DEFAULT_CENTER.lat,
      longitude: DEFAULT_CENTER.lng,
      latitudeDelta: 0.35,
      longitudeDelta: 0.35,
    }
  }
  const lats = pins.map((p) => p.lat)
  const lngs = pins.map((p) => p.lng)
  const minLat = Math.min(...lats)
  const maxLat = Math.max(...lats)
  const minLng = Math.min(...lngs)
  const maxLng = Math.max(...lngs)
  const latitude = (minLat + maxLat) / 2
  const longitude = (minLng + maxLng) / 2
  const latitudeDelta = Math.max(0.08, (maxLat - minLat) * 1.4 || 0.2)
  const longitudeDelta = Math.max(0.08, (maxLng - minLng) * 1.4 || 0.2)
  return { latitude, longitude, latitudeDelta, longitudeDelta }
}

function haversineMiles(aLat: number, aLng: number, bLat: number, bLng: number): number {
  const toRad = (d: number) => (d * Math.PI) / 180
  const R = 3958.8
  const dLat = toRad(bLat - aLat)
  const dLng = toRad(bLng - aLng)
  const lat1 = toRad(aLat)
  const lat2 = toRad(bLat)
  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)))
}

function filterPins(pins: MarketplaceMapPin[], query: MapPinsQuery): MarketplaceMapPin[] {
  let next = pins
  if (query.city) {
    const city = query.city.toLowerCase()
    next = next.filter((p) => (p.city ?? "").toLowerCase() === city)
  }
  if (query.state) {
    const state = query.state.toLowerCase()
    next = next.filter((p) => (p.state ?? "").toLowerCase() === state)
  }
  if (
    query.lat != null &&
    query.lng != null &&
    query.radiusMiles != null &&
    query.radiusMiles > 0
  ) {
    next = next.filter(
      (p) => haversineMiles(query.lat!, query.lng!, p.lat, p.lng) <= query.radiusMiles!,
    )
  }
  return next
}

function mapWpPins(data: WpMapPinsResponse): MarketplaceMapPin[] {
  return (data.pins ?? [])
    .filter((p) => typeof p.lat === "number" && typeof p.lng === "number")
    .filter((p) => p.lat !== 0 || p.lng !== 0)
    .map((p) => ({
      id: `pin-${p.opportunity_id ?? `${p.lat}-${p.lng}`}`,
      opportunityId: Number(p.opportunity_id ?? 0),
      eventId: p.event_id ? Number(p.event_id) : undefined,
      title: p.title ?? "Opportunity",
      city: p.city,
      state: p.state,
      lat: p.lat!,
      lng: p.lng!,
      eventDate: p.deadline,
      vendorFee: p.vendor_fee,
      category: p.categories?.[0],
      spotsRemaining: p.spots_remaining,
    }))
    .filter((p) => p.opportunityId > 0)
}

function mockMapPins(): MarketplaceMapPin[] {
  return MOCK_OPPORTUNITIES.filter((opp) => opp.opportunityId).map((opp, i) => {
    const coords = CITY_COORDS[opp.city ?? "Charlotte"] ?? DEFAULT_CENTER
    return {
      id: `pin-${opp.opportunityId}`,
      opportunityId: opp.opportunityId!,
      title: opp.title,
      city: opp.city,
      state: opp.state,
      lat: coords.lat + (i % 5) * 0.015,
      lng: coords.lng + (i % 5) * 0.015,
      eventDate: opp.eventDate,
      vendorFee: opp.vendorFee,
      category: opp.categories[0],
      spotsRemaining: opp.spotsRemaining,
    }
  })
}

/**
 * Fetch discovery map pins from WP (same source as web BFF `/marketplace/map-pins`).
 * City/state go to WP; lat/lng/radius are applied client-side (pins API has no radius).
 */
export async function fetchMapPins(
  query: MapPinsQuery = {},
  auth?: WpAuth,
): Promise<MapPinsResult> {
  if (!isLiveApiEnabled()) {
    const pins = filterPins(mockMapPins(), query)
    return { pins, unavailable: pins.length === 0, mocked: true }
  }

  const data = await wpFetchJson<WpMapPinsResponse>("/marketplace/map-pins", {
    auth,
    query: {
      city: query.city,
      state: query.state,
    },
  })
  const pins = groupPinsByEvent(
    filterPins(mapWpPins(data), {
      lat: query.lat,
      lng: query.lng,
      radiusMiles: query.radiusMiles,
    }),
  )
  return { pins, unavailable: pins.length === 0 }
}
