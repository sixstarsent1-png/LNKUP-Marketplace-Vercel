import type { MarketplaceMapPin, MarketplaceOpportunity } from "@/lib/api/marketplace-discovery"
import { opportunityToEventItem } from "@/lib/api/marketplace-discovery"
import type { EventItem } from "@/lib/data"
import { events } from "@/lib/data"

const CITY_COORDS: Record<string, { lat: number; lng: number }> = {
  Charlotte: { lat: 35.2271, lng: -80.8431 },
  Atlanta: { lat: 33.749, lng: -84.388 },
  Raleigh: { lat: 35.7796, lng: -78.6382 },
  Miami: { lat: 25.7617, lng: -80.1918 },
  Dallas: { lat: 32.7767, lng: -96.797 },
  Houston: { lat: 29.7604, lng: -95.3698 },
  Nashville: { lat: 36.1627, lng: -86.7816 },
  Greensboro: { lat: 36.0726, lng: -79.792 },
}

export function mockOpportunitiesFromEvents(): MarketplaceOpportunity[] {
  return events
    .filter((e) => e.status !== "closed")
    .map((e, i) => ({
      id: e.id,
      opportunityId: e.opportunityId ?? 100 + i,
      title: e.name,
      eventDate: e.date,
      city: e.city,
      state: e.state,
      venue: e.venue,
      eventType: i % 2 === 0 ? "Festival" : "Market",
      categories: e.categoriesNeeded,
      vendorFee: e.vendorFee,
      expectedAttendance: e.expectedAttendance,
      applicationDeadline: e.deadline,
      spotsRemaining: e.spotsRemaining,
      spotsTotal: e.totalSpots,
      organizerName: e.organizer,
      organizerVerified: e.organizerVerified,
      indoor: i % 3 === 0,
      outdoor: i % 3 !== 0,
      documentsRequired: i % 4 === 0,
      imageUrl: e.image,
      trending: e.trending,
      applicationStatus: i === 2 ? "payment_required" : i === 3 ? "approved" : undefined,
      saved: i === 0,
    }))
}

export function mockMapPins(): MarketplaceMapPin[] {
  return mockOpportunitiesFromEvents().map((opp, i) => {
    const coords = CITY_COORDS[opp.city ?? "Charlotte"] ?? { lat: 35.22, lng: -80.84 }
    return {
      id: `pin-${opp.id}`,
      opportunityId: String(opp.opportunityId ?? i),
      title: opp.title,
      city: opp.city,
      state: opp.state,
      lat: coords.lat + (i % 5) * 0.02,
      lng: coords.lng + (i % 5) * 0.02,
      eventDate: opp.eventDate,
      vendorFee: opp.vendorFee,
      category: opp.categories?.[0],
      applicationStatus: opp.applicationStatus,
      saved: opp.saved,
    }
  })
}

export function mockSearchResults(filters: {
  query?: string
  city?: string
  category?: string
}): { items: MarketplaceOpportunity[]; total: number } {
  let items = mockOpportunitiesFromEvents()
  if (filters.query) {
    const q = filters.query.toLowerCase()
    items = items.filter(
      (o) =>
        o.title.toLowerCase().includes(q) ||
        (o.city ?? "").toLowerCase().includes(q),
    )
  }
  if (filters.city) {
    items = items.filter((o) => o.city?.toLowerCase() === filters.city!.toLowerCase())
  }
  if (filters.category) {
    items = items.filter((o) => o.categories?.includes(filters.category!))
  }
  return { items, total: items.length }
}

export function mockOpportunitiesAsEvents(): EventItem[] {
  return mockOpportunitiesFromEvents().map(opportunityToEventItem)
}
