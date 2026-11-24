import { getOrganizerDashboardUrl, isLiveApiEnabled } from "@/lib/api/config"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { mapEventMedia, type EventMedia, type WpEventMedia } from "@/lib/api/event-media"
import {
  fetchOrganizerEventSummary,
  fetchOrganizerPortfolio,
  type EventCapacity,
  type EventMoneyGross,
  type OrganizerEventPresentation,
} from "@/lib/api/organizer-portfolio"
import { MOCK_ORGANIZER_EVENTS } from "@/lib/data/fixtures"
import { parseCalendarDate } from "@/lib/format/date"

export type OrganizerEventStatus = "upcoming" | "past" | "unknown"

export type OrganizerEventOpportunity = {
  id: string
  label?: string
  category?: string
  vendorFee?: number
  spotsTotal?: number
  spotsRemaining?: number
  status?: string
}

export type OrganizerEventLite = {
  id: string
  title: string
  date?: string
  city?: string
  state?: string
  venueName?: string
  venueAddress?: string
  description?: string
  startTime?: string
  endTime?: string
  status: OrganizerEventStatus
  /** WordPress post status: publish | draft | pending | private (absent on older servers). */
  publishStatus?: string
  imageUrl?: string
  media?: EventMedia
  opportunitiesCount?: number
  opportunities?: OrganizerEventOpportunity[]
  requestsCount?: number
  /** Applications waiting on the organizer (submitted / under review). */
  toReviewCount?: number
  needsInfoCount?: number
  approvedCount?: number
  paymentRequiredCount?: number
  paidCount?: number
  waitlistedCount?: number
  /** True when counts came from the server summary (no roster request needed). */
  countsFromServer?: boolean
  wordpressUrl?: string
  opportunityId?: string
  vendorFee?: number
  spotsTotal?: number
  spotsRemaining?: number
  /** Canonical "City, ST" from the server; prefer over joining city/state locally. */
  place?: string
  capacity?: EventCapacity
  moneyGross?: EventMoneyGross
}

/** Overlay server presentation fields (canonical title, place, capacity, gross money). */
export function withPresentation(
  event: OrganizerEventLite,
  presentation: OrganizerEventPresentation | null | undefined,
): OrganizerEventLite {
  if (!presentation) return event
  return {
    ...event,
    title: presentation.title || event.title,
    place: presentation.place || undefined,
    capacity: presentation.capacity,
    moneyGross: presentation.money,
  }
}

export type OrganizerEventUpdatePayload = {
  event_name?: string
  event_date?: string
  city?: string
  state?: string
  venue_name?: string
  venue_address?: string
  description?: string
  start_time?: string
  end_time?: string
}

export type OrganizerOpportunityCreateInput = {
  categories: string[]
  vendor_category?: string
  vendor_fee: number
  spots_total: number
  opportunity_title?: string
}

type WpInviteOption = {
  event_id?: number
  event_title?: string
  date?: string
  city?: string
  state?: string
  venue_name?: string
  venue_address?: string
  description?: string
  start_time?: string
  end_time?: string
  post_status?: string
  /** live | draft — draft until the event has an open vendor slot. */
  listing_status?: string
  image_url?: string
  media?: WpEventMedia | null
  counts?: {
    total?: number
    to_review?: number
    needs_info?: number
    approved?: number
    payment_required?: number
    confirmed?: number
    waitlisted?: number
    declined?: number
  }
  opportunities?: Array<{
    id?: number
    label?: string
    status?: string
    vendor_fee?: number
    spots_total?: number
    spots_remaining?: number
    vendor_category?: string
    category?: string
  }>
}

type WpRosterGroups = {
  applied?: unknown[]
  approved?: unknown[]
  paid?: unknown[]
  missing_payment?: unknown[]
  checked_in?: unknown[]
  waitlisted?: unknown[]
  rejected?: unknown[]
}

export function deriveEventStatus(date?: string): OrganizerEventStatus {
  const parsed = date ? parseCalendarDate(date) : null
  if (!parsed) return "unknown"
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const day = new Date(parsed)
  day.setHours(0, 0, 0, 0)
  return day.getTime() >= today.getTime() ? "upcoming" : "past"
}

export function organizerEventStatusLabel(status: OrganizerEventStatus): string {
  switch (status) {
    case "upcoming":
      return "Upcoming"
    case "past":
      return "Past"
    case "unknown":
      return "Date TBD"
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

export type EventListingState = "draft" | "live" | "past" | "no_date"

/** What the organizer needs to know first: not published yet, live, or over. */
export function eventListingState(event: OrganizerEventLite): EventListingState {
  if (event.publishStatus && event.publishStatus !== "publish") return "draft"
  if (event.status === "past") return "past"
  if (event.status === "unknown") return "no_date"
  return "live"
}

export function eventListingLabel(state: EventListingState): string {
  switch (state) {
    case "draft":
      return "Draft"
    case "live":
      return "Live"
    case "past":
      return "Past"
    case "no_date":
      return "Date TBD"
    default: {
      const exhaustive: never = state
      return exhaustive
    }
  }
}

function mapInviteOpportunity(
  row: NonNullable<WpInviteOption["opportunities"]>[number],
): OrganizerEventOpportunity | null {
  if (row?.id == null) return null
  const label = row.label ? String(row.label) : undefined
  const category =
    (row.vendor_category ? String(row.vendor_category) : undefined) ||
    (row.category ? String(row.category) : undefined) ||
    label
  return {
    id: String(row.id),
    label,
    category,
    vendorFee: row.vendor_fee,
    spotsTotal: row.spots_total,
    spotsRemaining: row.spots_remaining,
    status: row.status ? String(row.status) : undefined,
  }
}

export function mapInviteOptionToEventLite(item: WpInviteOption): OrganizerEventLite {
  const id = String(item.event_id ?? "")
  const base = getOrganizerDashboardUrl()
  const date = item.date
  const opportunities = (Array.isArray(item.opportunities) ? item.opportunities : [])
    .map((row) => mapInviteOpportunity(row))
    .filter((row): row is OrganizerEventOpportunity => Boolean(row))
  const primaryOpp = opportunities[0]
  const counts = item.counts
  return {
    id,
    title: item.event_title || "Event",
    date,
    city: item.city,
    state: item.state,
    venueName: item.venue_name,
    venueAddress: item.venue_address,
    description: item.description,
    startTime: item.start_time,
    endTime: item.end_time,
    status: deriveEventStatus(date),
    publishStatus:
      item.post_status && item.post_status !== "publish"
        ? item.post_status
        : item.listing_status === "draft"
          ? "draft"
          : item.post_status || undefined,
    imageUrl: mapEventMedia(item.media, item.image_url)?.url,
    media: mapEventMedia(item.media, item.image_url),
    ...(counts
      ? {
          countsFromServer: true,
          requestsCount: counts.total ?? 0,
          toReviewCount: counts.to_review ?? 0,
          needsInfoCount: counts.needs_info ?? 0,
          approvedCount: (counts.approved ?? 0) + (counts.payment_required ?? 0),
          paymentRequiredCount: counts.payment_required ?? 0,
          paidCount: counts.confirmed ?? 0,
          waitlistedCount: counts.waitlisted ?? 0,
        }
      : {}),
    opportunitiesCount: opportunities.length,
    opportunities,
    wordpressUrl: base ? `${base}?view=operations&event=${id}` : undefined,
    opportunityId: primaryOpp?.id,
    vendorFee: primaryOpp?.vendorFee,
    spotsTotal: primaryOpp?.spotsTotal,
    spotsRemaining: primaryOpp?.spotsRemaining,
  }
}

export function enrichEventWithRoster(
  event: OrganizerEventLite,
  roster: { groups?: WpRosterGroups; total?: number },
): OrganizerEventLite {
  const groups = roster.groups ?? {}
  const applied =
    (groups.applied?.length ?? 0) +
    (groups.waitlisted?.length ?? 0) +
    (groups.approved?.length ?? 0) +
    (groups.paid?.length ?? 0) +
    (groups.missing_payment?.length ?? 0) +
    (groups.rejected?.length ?? 0)
  return {
    ...event,
    requestsCount: roster.total ?? applied,
    // Approved queue = approved awaiting pay (missing_payment) + approved group; paid counted separately.
    approvedCount: (groups.approved?.length ?? 0) + (groups.missing_payment?.length ?? 0),
    paymentRequiredCount: groups.missing_payment?.length ?? 0,
    // Checked-in vendors leave the server's `paid` group but are still paid.
    paidCount: (groups.paid?.length ?? 0) + (groups.checked_in?.length ?? 0),
  }
}

async function loadInviteList(
  auth: WpAuth,
  options: { includeAll?: boolean } = {},
): Promise<WpInviteOption[]> {
  const qs = options.includeAll ? "?include_all=1" : ""
  const inviteRaw = await wpFetchJson<{ items?: unknown[] } | unknown[]>(
    `/crm/events/invite-options${qs}`,
    { auth },
  )
  const inviteList = Array.isArray(inviteRaw)
    ? inviteRaw
    : Array.isArray(inviteRaw?.items)
      ? inviteRaw.items
      : []
  return inviteList as WpInviteOption[]
}

/** Roster counts only for servers that don't send `counts` with invite-options. */
async function enrichRoster(
  event: OrganizerEventLite,
  auth: WpAuth,
): Promise<OrganizerEventLite> {
  if (event.countsFromServer) return event
  try {
    const roster = await wpFetchJson<{ groups?: WpRosterGroups; total?: number }>(
      `/crm/events/${event.id}/roster`,
      { auth },
    )
    return enrichEventWithRoster(event, roster)
  } catch {
    return event
  }
}

function buildMockOrganizerEvents(): { items: OrganizerEventLite[]; total: number } {
  return {
    items: MOCK_ORGANIZER_EVENTS.map((item) => ({
      ...item,
      status: item.status ?? deriveEventStatus(item.date),
    })),
    total: MOCK_ORGANIZER_EVENTS.length,
  }
}

const EVENTS_CACHE_PREFIX = "organizer.events:"

/**
 * Organizer events with roster counts. Live mode never substitutes demo events: a failed
 * request surfaces as an error so the screen shows its retry state. Shared across tabs.
 */
export async function fetchOrganizerEvents(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<{
  items: OrganizerEventLite[]
  total: number
}> {
  if (!isLiveApiEnabled()) {
    return buildMockOrganizerEvents()
  }
  if (!auth) {
    throw new Error("Sign in as an organizer to continue.")
  }
  return cachedRequest(
    `${EVENTS_CACHE_PREFIX}${auth.username}`,
    async () => {
      const [inviteList, portfolio] = await Promise.all([
        loadInviteList(auth, { includeAll: true }),
        fetchOrganizerPortfolio(auth).catch(() => new Map<string, OrganizerEventPresentation>()),
      ])
      const baseEvents = inviteList
        .map((item) => withPresentation(mapInviteOptionToEventLite(item), portfolio.get(String(item.event_id ?? ""))))
        .filter((event) => event.id)
        .slice(0, 50)

      const enriched = await Promise.all(baseEvents.map((event) => enrichRoster(event, auth)))

      return { items: enriched, total: enriched.length }
    },
    options,
  )
}

/** Call after publish, review, or check-in so events and roster counts re-read. */
export function invalidateOrganizerEvents(): void {
  invalidateCache(EVENTS_CACHE_PREFIX)
  // Home's launch checklist and counts follow the events (prefix only; importing Home would cycle).
  invalidateCache("organizer.home:")
  invalidateCache("org-mobile:")
}

export type DuplicateEventInput = {
  name: string
  date: string
  keepLocation: boolean
  city?: string
  state?: string
  clientToken: string
}

/**
 * Copy an event's configuration (booth types, prices, packet, map) to a new date. The server
 * requires a date and either an explicit "same location" or the new city and state.
 */
export async function duplicateOrganizerEvent(
  eventId: string,
  input: DuplicateEventInput,
  auth: WpAuth,
): Promise<{ eventId: string; message?: string }> {
  const body: Record<string, unknown> = {
    event_name: input.name,
    event_date: input.date,
    client_token: input.clientToken,
  }
  if (input.keepLocation) {
    body.keep_location = true
  } else {
    body.city = input.city ?? ""
    body.state = input.state ?? ""
  }
  const res = await wpFetchJson<{ event_id?: number | string; message?: string }>(`/organizer/events/${eventId}/duplicate`, {
    method: "POST",
    auth,
    body,
  })
  invalidateOrganizerEvents()
  return { eventId: String(res.event_id ?? ""), message: res.message }
}

export async function fetchOrganizerEvent(
  eventId: string,
  auth?: WpAuth,
): Promise<OrganizerEventLite | null> {
  if (!eventId) return null

  if (!isLiveApiEnabled()) {
    const found = MOCK_ORGANIZER_EVENTS.find((item) => item.id === eventId)
    if (!found) return null
    return { ...found, status: found.status ?? deriveEventStatus(found.date) }
  }

  if (!auth) return null

  const [inviteList, summary] = await Promise.all([
    loadInviteList(auth, { includeAll: true }),
    fetchOrganizerEventSummary(eventId, auth).catch(() => null),
  ])
  const match = inviteList
    .map((item) => mapInviteOptionToEventLite(item))
    .find((event) => event.id === eventId)

  if (match) {
    return enrichRoster(withPresentation(match, summary), auth)
  }

  // Fallback: roster ownership proves the event exists even if invite-options lags.
  try {
    const roster = await wpFetchJson<{
      groups?: WpRosterGroups
      total?: number
      event?: { title?: string; date?: string; city?: string; state?: string }
      event_title?: string
    }>(`/crm/events/${eventId}/roster`, { auth })
    const base = getOrganizerDashboardUrl()
    const title =
      roster.event?.title || roster.event_title || `Event ${eventId}`
    const date = roster.event?.date
    const lite: OrganizerEventLite = {
      id: eventId,
      title,
      date,
      city: roster.event?.city,
      state: roster.event?.state,
      status: deriveEventStatus(date),
      wordpressUrl: base ? `${base}?view=operations&event=${eventId}` : undefined,
    }
    return enrichEventWithRoster(withPresentation(lite, summary), roster)
  } catch {
    return null
  }
}

export async function updateOpportunityPricing(
  opportunityId: string,
  input: { vendorFee: number; spotsTotal: number },
  auth?: WpAuth,
): Promise<{
  vendorFee: number
  spotsTotal: number
  spotsRemaining: number
  message?: string
}> {
  if (!opportunityId) {
    throw new Error("Missing opportunity.")
  }
  if (!auth) {
    throw new Error("Sign in as an organizer to continue.")
  }

  if (!isLiveApiEnabled()) {
    return {
      vendorFee: input.vendorFee,
      spotsTotal: input.spotsTotal,
      spotsRemaining: input.spotsTotal,
      message: "Vendor fee and spots updated (demo).",
    }
  }

  const res = await wpFetchJson<{
    vendor_fee?: number
    spots_total?: number
    spots_remaining?: number
    message?: string
  }>(`/organizer/opportunities/${opportunityId}/pricing`, {
    auth,
    method: "POST",
    body: {
      vendor_fee: input.vendorFee,
      spots_total: input.spotsTotal,
    },
  })

  invalidateOrganizerEvents()
  return {
    vendorFee: Number(res.vendor_fee ?? input.vendorFee),
    spotsTotal: Number(res.spots_total ?? input.spotsTotal),
    spotsRemaining: Number(res.spots_remaining ?? input.spotsTotal),
    message: res.message,
  }
}

export async function updateOrganizerEvent(
  eventId: string,
  payload: OrganizerEventUpdatePayload,
  auth?: WpAuth,
): Promise<{ message?: string }> {
  if (!eventId) throw new Error("Missing event.")
  if (!auth) throw new Error("Sign in as an organizer to continue.")

  if (!isLiveApiEnabled()) {
    return { message: "Event updated (demo)." }
  }

  const res = await wpFetchJson<{ message?: string }>(`/organizer/events/${eventId}`, {
    auth,
    method: "PATCH",
    body: payload,
  })
  invalidateOrganizerEvents()
  return { message: res.message ?? "Event updated." }
}

/**
 * Open or close a vendor slot. Closing keeps existing applications and hides the slot from
 * vendor discovery; reopening lists it again.
 */
export async function setOpportunityListingStatus(
  opportunityId: string,
  status: "open" | "closed",
  auth?: WpAuth,
): Promise<{ status: "open" | "closed"; message?: string }> {
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  if (!isLiveApiEnabled()) return { status, message: status === "closed" ? "Slot closed (demo)." : "Slot reopened (demo)." }
  const res = await wpFetchJson<{ status?: string; message?: string }>(
    `/organizer/opportunities/${opportunityId}/status`,
    { auth, method: "POST", body: { status } },
  )
  invalidateOrganizerEvents()
  return { status: res.status === "closed" ? "closed" : "open", message: res.message }
}

/**
 * Add vendor types to an event. `clientToken` makes a retried submit (network drop, double tap)
 * return the slots already created instead of duplicating them.
 */
export async function addOrganizerOpportunities(
  eventId: string,
  opportunities: OrganizerOpportunityCreateInput[],
  auth?: WpAuth,
  clientToken?: string,
): Promise<{
  opportunities?: OrganizerEventOpportunity[]
  message?: string
}> {
  if (!eventId) throw new Error("Missing event.")
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  if (!opportunities.length) throw new Error("Add at least one vendor type.")

  if (!isLiveApiEnabled()) {
    return {
      message: "Vendor type added (demo).",
      opportunities: opportunities.map((row, index) => ({
        id: `demo-${Date.now()}-${index}`,
        label: row.opportunity_title ?? row.vendor_category ?? row.categories[0],
        category: row.vendor_category ?? row.categories[0],
        vendorFee: row.vendor_fee,
        spotsTotal: row.spots_total,
        spotsRemaining: row.spots_total,
      })),
    }
  }

  const res = await wpFetchJson<{
    message?: string
    opportunities?: Array<{
      id?: number
      opportunity_id?: number
      title?: string
      opportunity_title?: string
      vendor_fee?: number
      spots_total?: number
      spots_remaining?: number
      vendor_category?: string
      categories?: string[]
    }>
  }>(`/organizer/events/${eventId}/opportunities`, {
    auth,
    method: "POST",
    body: { opportunities, ...(clientToken ? { client_token: clientToken } : {}) },
  })
  invalidateOrganizerEvents()

  const mapped = (Array.isArray(res.opportunities) ? res.opportunities : [])
    .map((row): OrganizerEventOpportunity | null => {
      const id = row.id ?? row.opportunity_id
      if (id == null) return null
      const category =
        row.vendor_category ||
        (Array.isArray(row.categories) ? row.categories[0] : undefined) ||
        row.title ||
        row.opportunity_title
      return {
        id: String(id),
        label: row.opportunity_title ?? row.title ?? category,
        category: category ? String(category) : undefined,
        vendorFee: row.vendor_fee,
        spotsTotal: row.spots_total,
        spotsRemaining: row.spots_remaining,
      }
    })
    .filter((row): row is OrganizerEventOpportunity => Boolean(row))

  return {
    message: res.message ?? "Vendor type added.",
    opportunities: mapped,
  }
}
