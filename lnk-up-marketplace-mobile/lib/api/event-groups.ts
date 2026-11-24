import { mapEventMedia, type EventMedia, type WpEventMedia } from "@/lib/api/event-media"
import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { formatShortDate } from "@/lib/format/date"

/** One vendor option (booth / category slot) inside an event. Each keeps its own price and rules. */
export type EventOption = {
  opportunityId: number
  title: string
  categories: string[]
  /** null when the viewer may not see pricing (guest) or the organizer has not set it. */
  vendorFee: number | null
  spotsRemaining: number | null
  spotsTotal: number | null
  spotsUnlimited: boolean | null
  isOpen: boolean
  deadlineIso?: string
  applicationStatus?: string
  canApplyAgain: boolean
  documents: string[]
  powerAvailable: boolean
  waterAvailable: boolean
  indoor: boolean
  outdoor: boolean
  loadInTime?: string
}

/** GroupedEventSummary from `LNKup_Event_Groups` (one card per event). */
export type EventGroup = {
  key: string
  eventId: number
  primaryOpportunityId: number
  title: string
  dateIso?: string
  endDateIso?: string
  dateLabel?: string
  startTime?: string
  place: string
  venue?: string
  lat?: number
  lng?: number
  organizerName?: string
  organizerVerified: boolean
  eventTypes: string[]
  /** Organizer-provided estimate; never independently verified. */
  attendance: number | null
  media?: EventMedia
  fallbackImageUrl?: string
  imageAlt?: string
  gated: boolean
  fromPrice: number | null
  priceMax: number | null
  categories: string[]
  totalOptions: number
  openOptions: number
  fullOptions: number | null
  openSpots: number | null
  hasUnlimited: boolean | null
  deadlineIso?: string
  indoor: boolean
  outdoor: boolean
  distanceMiles: number | null
  applicationStatus?: string
  options: EventOption[]
}

export type EventDetail = EventGroup & {
  description: string
  endTime?: string
  venueAddress?: string
  eventState: string
  tourId: number | null
}

export type WpEventOption = {
  opportunity_id?: number
  title?: string
  categories?: string[]
  vendor_fee?: number | null
  spots_remaining?: number | null
  total_spots?: number | null
  spots_unlimited?: boolean | null
  is_open?: boolean
  deadline?: string | null
  application_status?: string
  can_apply_again?: boolean
  document_badges?: string[]
  power_available?: boolean
  water_available?: boolean
  indoor?: boolean
  outdoor?: boolean
  load_in_time?: string
}

export type WpEventGroup = {
  item_type?: string
  key?: string
  event_id?: number
  opportunity_id?: number
  title?: string
  date?: string | null
  end_date?: string | null
  start_time?: string | null
  place?: string
  city?: string
  state?: string
  venue_name?: string
  lat?: number | null
  lng?: number | null
  organizer?: { id?: number; name?: string; verified?: boolean }
  event_types?: string[]
  attendance?: number | null
  media?: WpEventMedia | null
  featured_image?: string
  category_fallback_url?: string
  image_alt?: string
  gated?: boolean
  from_price?: number | null
  price_max?: number | null
  categories?: string[]
  availability?: {
    total_options?: number
    open_options?: number
    full_options?: number | null
    open_spots?: number | null
    has_unlimited?: boolean | null
  }
  deadline?: string | null
  environment?: { indoor?: boolean; outdoor?: boolean }
  distance_miles?: number | null
  application_status?: string
  options?: WpEventOption[]
  description?: string
  end_time?: string | null
  venue_address?: string
  event_state?: string
  tour_id?: number
}

function isoDay(value?: string | null): string | undefined {
  if (!value) return undefined
  const match = String(value).trim().match(/^(\d{4}-\d{2}-\d{2})/)
  return match ? match[1] : undefined
}

function numOrNull(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null
}

export function mapEventOption(raw: WpEventOption): EventOption | null {
  const opportunityId = Number(raw.opportunity_id ?? 0)
  if (!opportunityId) return null
  return {
    opportunityId,
    title: raw.title?.trim() || "Vendor space",
    categories: Array.isArray(raw.categories) ? raw.categories.filter(Boolean) : [],
    vendorFee: numOrNull(raw.vendor_fee),
    spotsRemaining: numOrNull(raw.spots_remaining),
    spotsTotal: numOrNull(raw.total_spots),
    spotsUnlimited: typeof raw.spots_unlimited === "boolean" ? raw.spots_unlimited : null,
    isOpen: Boolean(raw.is_open),
    deadlineIso: isoDay(raw.deadline),
    applicationStatus: raw.application_status?.trim() || undefined,
    canApplyAgain: raw.can_apply_again === true,
    documents: Array.isArray(raw.document_badges) ? raw.document_badges : [],
    powerAvailable: Boolean(raw.power_available),
    waterAvailable: Boolean(raw.water_available),
    indoor: Boolean(raw.indoor),
    outdoor: Boolean(raw.outdoor),
    loadInTime: raw.load_in_time || undefined,
  }
}

export function mapEventGroup(raw: WpEventGroup): EventGroup | null {
  const options = (raw.options ?? []).map(mapEventOption).filter((o): o is EventOption => o !== null)
  const primary = Number(raw.opportunity_id ?? options[0]?.opportunityId ?? 0)
  if (!primary) return null
  const dateIso = isoDay(raw.date)
  const availability = raw.availability ?? {}
  return {
    key: raw.key || (raw.event_id ? `e${raw.event_id}` : `o${primary}`),
    eventId: Number(raw.event_id ?? 0),
    primaryOpportunityId: primary,
    title: raw.title?.trim() || "Event",
    dateIso,
    endDateIso: isoDay(raw.end_date),
    dateLabel: dateIso ? formatShortDate(dateIso) : undefined,
    startTime: raw.start_time || undefined,
    place: raw.place || [raw.city, raw.state].filter(Boolean).join(", "),
    venue: raw.venue_name || undefined,
    lat: numOrNull(raw.lat) ?? undefined,
    lng: numOrNull(raw.lng) ?? undefined,
    organizerName: raw.organizer?.name || undefined,
    organizerVerified: Boolean(raw.organizer?.verified),
    eventTypes: raw.event_types ?? [],
    attendance: numOrNull(raw.attendance),
    media: mapEventMedia(raw.media, raw.featured_image),
    fallbackImageUrl: raw.category_fallback_url || undefined,
    imageAlt: raw.image_alt || undefined,
    gated: Boolean(raw.gated),
    fromPrice: numOrNull(raw.from_price),
    priceMax: numOrNull(raw.price_max),
    categories: raw.categories ?? [],
    totalOptions: Number(availability.total_options ?? options.length),
    openOptions: Number(availability.open_options ?? options.filter((o) => o.isOpen).length),
    fullOptions: numOrNull(availability.full_options),
    openSpots: numOrNull(availability.open_spots),
    hasUnlimited: typeof availability.has_unlimited === "boolean" ? availability.has_unlimited : null,
    deadlineIso: isoDay(raw.deadline),
    indoor: Boolean(raw.environment?.indoor),
    outdoor: Boolean(raw.environment?.outdoor),
    distanceMiles: numOrNull(raw.distance_miles),
    applicationStatus: raw.application_status?.trim() || undefined,
    options,
  }
}

export function mapEventDetail(raw: WpEventGroup): EventDetail | null {
  const group = mapEventGroup(raw)
  if (!group) return null
  return {
    ...group,
    description: (raw.description ?? "").trim(),
    endTime: raw.end_time || undefined,
    venueAddress: raw.venue_address || undefined,
    eventState: raw.event_state || "unknown",
    tourId: raw.tour_id ? Number(raw.tour_id) : null,
  }
}

/**
 * Group ungrouped opportunity rows by event on the client. Only used when the server predates
 * `group_by=event`; the server grouping is authoritative because it paginates by event.
 */
export function groupOpportunitiesLocally(items: MarketplaceOpportunity[]): EventGroup[] {
  const groups = new Map<string, MarketplaceOpportunity[]>()
  for (const item of items) {
    const key = item.eventId ? `e${item.eventId}` : `o${item.opportunityId ?? item.id}`
    const list = groups.get(key) ?? []
    list.push(item)
    groups.set(key, list)
  }
  const out: EventGroup[] = []
  for (const [key, slots] of groups) {
    const first = slots[0]
    const options: EventOption[] = slots
      .filter((s) => s.opportunityId)
      .map((s) => ({
        opportunityId: s.opportunityId as number,
        title: s.categories.join(" · ") || "Vendor space",
        categories: s.categories,
        vendorFee: s.vendorFee,
        spotsRemaining: s.spotsTotal ? s.spotsRemaining : null,
        spotsTotal: s.spotsTotal || null,
        spotsUnlimited: s.spotsTotal ? false : null,
        isOpen: s.spotsTotal ? s.spotsRemaining > 0 : true,
        deadlineIso: s.deadlineIso,
        applicationStatus: s.applicationStatus,
        canApplyAgain: Boolean(s.canApplyAgain),
        documents: [],
        powerAvailable: Boolean(s.powerAvailable),
        waterAvailable: false,
        indoor: Boolean(s.indoor),
        outdoor: Boolean(s.outdoor),
        loadInTime: s.loadInTime,
      }))
    if (options.length === 0) continue
    const fees = options.filter((o) => o.isOpen && o.vendorFee != null && o.vendorFee > 0).map((o) => o.vendorFee as number)
    const categories = Array.from(new Set(options.flatMap((o) => o.categories)))
    out.push({
      key,
      eventId: first.eventId ?? 0,
      primaryOpportunityId: options[0].opportunityId,
      title: first.title,
      dateIso: first.eventDateIso,
      dateLabel: first.eventDate,
      place: [first.city, first.state].filter(Boolean).join(", "),
      venue: first.venue || undefined,
      organizerName: first.organizerName || undefined,
      organizerVerified: first.organizerVerified,
      eventTypes: [],
      attendance: first.expectedAttendance > 0 ? first.expectedAttendance : null,
      media: first.media,
      gated: first.vendorFee == null,
      fromPrice: fees.length ? Math.min(...fees) : null,
      priceMax: fees.length ? Math.max(...fees) : null,
      categories,
      totalOptions: options.length,
      openOptions: options.filter((o) => o.isOpen).length,
      fullOptions: options.filter((o) => !o.isOpen).length,
      openSpots: null,
      hasUnlimited: null,
      deadlineIso: options.map((o) => o.deadlineIso).filter(Boolean).sort()[0],
      indoor: options.some((o) => o.indoor),
      outdoor: options.some((o) => o.outdoor),
      distanceMiles: null,
      applicationStatus: options.find((o) => o.applicationStatus)?.applicationStatus,
      options,
    })
  }
  return out
}

/**
 * Saves stay per vendor option (existing favorites are never migrated). An event reads as saved
 * when any of its options is saved; saving from a card saves its primary option.
 */
export function savedOptionIds(group: Pick<EventGroup, "options">, savedIds: ReadonlySet<number>): number[] {
  return group.options.map((o) => o.opportunityId).filter((id) => savedIds.has(id))
}

/** Toggle an event card's saved state through the existing per-option favorites. */
export async function toggleGroupSaved(
  group: Pick<EventGroup, "options" | "primaryOpportunityId">,
  savedIds: ReadonlySet<number>,
  setSaved: (id: number, wanted: boolean) => Promise<boolean | null>,
): Promise<void> {
  const saved = savedOptionIds(group, savedIds)
  if (saved.length === 0) {
    await setSaved(group.primaryOpportunityId, true)
    return
  }
  for (const id of saved) await setSaved(id, false)
}

/** `GET /marketplace/events/{id}`: one event with every listed vendor option. */
export async function fetchEventDetail(eventId: number, auth?: WpAuth, signal?: AbortSignal): Promise<EventDetail | null> {
  const data = await wpFetchJson<WpEventGroup>(`/marketplace/events/${eventId}`, { auth, signal })
  return mapEventDetail(data)
}
