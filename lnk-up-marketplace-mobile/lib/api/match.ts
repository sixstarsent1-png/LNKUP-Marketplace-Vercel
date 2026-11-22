import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import type { WpOpportunityItem } from "@/lib/api/types"
import { mapWpToOpportunity, type MarketplaceOpportunity } from "@/lib/api/marketplace"

/**
 * Matching & discovery client. All scoring happens in WordPress
 * (`lnkup/v1/match/*`); the app only renders server results.
 */

export const VENDOR_MATCH_SECTIONS = [
  "recommended",
  "preferred_markets",
  "new",
  "deadlines",
  "previous_organizers",
  "tours",
  "series",
] as const

export type VendorMatchSection = (typeof VENDOR_MATCH_SECTIONS)[number]

export const VENDOR_MATCH_SECTION_TITLES: Record<VendorMatchSection, string> = {
  recommended: "Recommended for you",
  preferred_markets: "Near your preferred cities",
  new: "New opportunities",
  deadlines: "Deadlines approaching",
  previous_organizers: "Previously worked with",
  tours: "Tours accepting vendors",
  series: "Recurring markets",
}

export type MatchBand = "strong" | "good" | "possible"

export type VendorMatch = {
  band: MatchBand
  bandLabel: string
  reasons: string[]
}

export type MatchedOpportunity = {
  kind: "opportunity"
  opportunity: MarketplaceOpportunity
  match: VendorMatch | null
  moreDates: number
}

export type MatchedTour = {
  kind: "tour"
  id: number
  title: string
  type: "tour" | "series"
  matchingStops: number
  places: string[]
  nextDate: string | null
  reasons: string[]
}

export type VendorMatchItem = MatchedOpportunity | MatchedTour

export type VendorMatchSectionResult =
  | { status: "disabled" }
  | { status: "needs_profile" }
  | { status: "ready"; items: VendorMatchItem[]; total: number }

type WpVendorMatch = { band?: string; band_label?: string; reasons?: string[] }

type WpRecommendationItem = WpOpportunityItem & {
  kind?: string
  id?: number
  title?: string
  type?: string
  matching_stops?: number
  places?: string[]
  next_date?: string | null
  match?: WpVendorMatch
  more_dates?: number
}

type WpRecommendationsResponse = {
  enabled?: boolean
  needs_profile?: boolean
  total?: number
  items?: WpRecommendationItem[]
}

function toBand(value: string | undefined): MatchBand {
  return value === "strong" || value === "good" ? value : "possible"
}

function mapMatch(match: WpVendorMatch | undefined): VendorMatch | null {
  if (!match) return null
  return {
    band: toBand(match.band),
    bandLabel: match.band_label ?? "",
    reasons: Array.isArray(match.reasons) ? match.reasons : [],
  }
}

export function mapRecommendations(data: WpRecommendationsResponse | undefined): VendorMatchSectionResult {
  if (!data || data.enabled === false) return { status: "disabled" }
  if (data.needs_profile) return { status: "needs_profile" }
  const items: VendorMatchItem[] = []
  for (const raw of data.items ?? []) {
    if (raw.kind === "tour") {
      items.push({
        kind: "tour",
        id: Number(raw.id ?? 0),
        title: raw.title ?? "",
        type: raw.type === "series" ? "series" : "tour",
        matchingStops: Number(raw.matching_stops ?? 0),
        places: Array.isArray(raw.places) ? raw.places : [],
        nextDate: raw.next_date ?? null,
        reasons: mapMatch(raw.match)?.reasons ?? [],
      })
      continue
    }
    const opportunity = mapWpToOpportunity(raw)
    if (!opportunity) continue
    items.push({ kind: "opportunity", opportunity, match: mapMatch(raw.match), moreDates: Number(raw.more_dates ?? 0) })
  }
  return { status: "ready", items, total: Number(data.total ?? items.length) }
}

export async function fetchVendorMatchSection(
  section: VendorMatchSection,
  auth?: WpAuth,
  signal?: AbortSignal,
): Promise<VendorMatchSectionResult> {
  if (!isLiveApiEnabled() || !auth) return { status: "disabled" }
  const data = await wpFetchJson<WpRecommendationsResponse>("/match/vendor/recommendations", {
    auth,
    query: { section, per_page: 6 },
    signal,
  })
  return mapRecommendations(data)
}

/* ------------------------------------------------------------------ */
/* Vendor matching preferences                                         */
/* ------------------------------------------------------------------ */

export type MatchPreferences = {
  categories: number[]
  primaryCategory: number
  city: string
  state: string
  zip: string
  citiesServed: string[]
  statesServed: string[]
  radiusMiles: number
  travelNational: boolean
  eventTypes: string[]
  availableMonths: number[]
  feeMax: number | null
  envPref: "" | "indoor" | "outdoor" | "both"
  discoverable: boolean
  acceptInvites: boolean
}

export type MatchPreferencesPayload = {
  preferences: MatchPreferences
  completeness: { score: number; missing: Array<{ key: string; label: string }> }
  options: {
    categories: Array<{ id: number; name: string }>
    eventTypes: Array<{ id: number; name: string }>
  }
}

type WpPreferencesResponse = {
  needs_profile?: boolean
  preferences?: {
    categories?: number[]
    primary_category?: number
    city?: string
    state?: string
    zip?: string
    cities_served?: string[]
    states_served?: string[]
    radius_miles?: number
    travel_national?: boolean
    event_types?: string[]
    available_months?: number[]
    fee_max?: number | null
    env_pref?: string
    discoverable?: boolean
    accept_invites?: boolean
  }
  completeness?: { score?: number; missing?: Array<{ key: string; label: string }> }
  options?: { categories?: Array<{ id: number; name: string }>; event_types?: Array<{ id: number; name: string }> }
}

function mapPreferences(data: WpPreferencesResponse): MatchPreferencesPayload | null {
  if (data.needs_profile || !data.preferences) return null
  const p = data.preferences
  const env = p.env_pref === "indoor" || p.env_pref === "outdoor" || p.env_pref === "both" ? p.env_pref : ""
  return {
    preferences: {
      categories: p.categories ?? [],
      primaryCategory: Number(p.primary_category ?? 0),
      city: p.city ?? "",
      state: p.state ?? "",
      zip: p.zip ?? "",
      citiesServed: p.cities_served ?? [],
      statesServed: p.states_served ?? [],
      radiusMiles: Number(p.radius_miles ?? 0),
      travelNational: Boolean(p.travel_national),
      eventTypes: p.event_types ?? [],
      availableMonths: p.available_months ?? [],
      feeMax: p.fee_max ?? null,
      envPref: env,
      discoverable: p.discoverable !== false,
      acceptInvites: p.accept_invites !== false,
    },
    completeness: {
      score: Number(data.completeness?.score ?? 0),
      missing: data.completeness?.missing ?? [],
    },
    options: {
      categories: data.options?.categories ?? [],
      eventTypes: data.options?.event_types ?? [],
    },
  }
}

export async function fetchMatchPreferences(auth: WpAuth): Promise<MatchPreferencesPayload | null> {
  const data = await wpFetchJson<WpPreferencesResponse>("/match/vendor/preferences", { auth })
  return mapPreferences(data)
}

export async function saveMatchPreferences(
  auth: WpAuth,
  patch: Partial<MatchPreferences>,
): Promise<MatchPreferencesPayload | null> {
  const body: Record<string, unknown> = {}
  if (patch.categories) body.categories = patch.categories
  if (patch.primaryCategory !== undefined) body.primary_category = patch.primaryCategory
  if (patch.city !== undefined) body.city = patch.city
  if (patch.state !== undefined) body.state = patch.state
  if (patch.zip !== undefined) body.zip = patch.zip
  if (patch.citiesServed) body.cities_served = patch.citiesServed
  if (patch.statesServed) body.states_served = patch.statesServed
  if (patch.radiusMiles !== undefined) body.radius_miles = patch.radiusMiles
  if (patch.travelNational !== undefined) body.travel_national = patch.travelNational
  if (patch.eventTypes) body.event_types = patch.eventTypes
  if (patch.availableMonths) body.available_months = patch.availableMonths
  if (patch.feeMax !== undefined) body.fee_max = patch.feeMax ?? ""
  if (patch.envPref !== undefined) body.env_pref = patch.envPref
  if (patch.discoverable !== undefined) body.discoverable = patch.discoverable
  if (patch.acceptInvites !== undefined) body.accept_invites = patch.acceptInvites
  const data = await wpFetchJson<WpPreferencesResponse>("/match/vendor/preferences", {
    auth,
    method: "POST",
    body,
  })
  return mapPreferences(data)
}

/* ------------------------------------------------------------------ */
/* Organizer: Find Vendors & recruitment                               */
/* ------------------------------------------------------------------ */

export type MatchedVendor = {
  profileId: number
  businessName: string
  logo: string
  primaryCategory: string
  city: string
  state: string
  description: string
  verified: boolean
  completedEvents: number
  band: MatchBand
  reasons: string[]
  stage: string
  acceptsInvites: boolean
}

export type EventVendorMatches =
  | { status: "disabled" }
  | { status: "needs_open_opportunity" }
  | { status: "ready"; items: MatchedVendor[]; total: number; pages: number }

type WpEventVendorsResponse = {
  enabled?: boolean
  needs_open_opportunity?: boolean
  total?: number
  pages?: number
  items?: Array<{
    profile_id?: number
    business_name?: string
    logo?: string
    primary_category?: string
    city?: string
    state?: string
    description?: string
    verified?: boolean
    completed_events?: number
    pipeline_stage?: string
    accept_invites?: boolean
    match?: { band?: string; reasons?: string[] }
  }>
}

export async function fetchEventVendorMatches(
  auth: WpAuth,
  eventId: number,
  filters: { category?: number; city?: string; verified?: boolean; page?: number } = {},
): Promise<EventVendorMatches> {
  if (!isLiveApiEnabled()) return { status: "disabled" }
  const data = await wpFetchJson<WpEventVendorsResponse>(`/match/organizer/events/${eventId}/vendors`, {
    auth,
    query: {
      category: filters.category || undefined,
      city: filters.city || undefined,
      verified: filters.verified ? 1 : undefined,
      page: filters.page ?? 1,
      exclude_applied: 1,
    },
  })
  if (data.enabled === false) return { status: "disabled" }
  if (data.needs_open_opportunity) return { status: "needs_open_opportunity" }
  return {
    status: "ready",
    total: Number(data.total ?? 0),
    pages: Number(data.pages ?? 1),
    items: (data.items ?? []).map((v) => ({
      profileId: Number(v.profile_id ?? 0),
      businessName: v.business_name ?? "Vendor",
      logo: v.logo ?? "",
      primaryCategory: v.primary_category ?? "",
      city: v.city ?? "",
      state: v.state ?? "",
      description: v.description ?? "",
      verified: Boolean(v.verified),
      completedEvents: Number(v.completed_events ?? 0),
      band: toBand(v.match?.band),
      reasons: v.match?.reasons ?? [],
      stage: v.pipeline_stage ?? "",
      acceptsInvites: v.accept_invites !== false,
    })),
  }
}

export type InvitePreview = {
  recipients: Array<{ profileId: number; businessName: string }>
  suppressed: Array<{ profileId: number; businessName: string; reasonLabel: string }>
  subject: string
  body: string
}

export async function previewInvites(
  auth: WpAuth,
  eventId: number,
  profileIds: number[],
  message = "",
): Promise<InvitePreview> {
  const data = await wpFetchJson<{
    recipients?: Array<{ profile_id?: number; business_name?: string }>
    suppressed?: Array<{ profile_id?: number; business_name?: string; reason_label?: string }>
    template?: { subject?: string; body?: string }
  }>("/match/organizer/invites/preview", {
    auth,
    method: "POST",
    body: { event_id: eventId, profile_ids: profileIds, message },
  })
  return {
    recipients: (data.recipients ?? []).map((r) => ({ profileId: Number(r.profile_id ?? 0), businessName: r.business_name ?? "" })),
    suppressed: (data.suppressed ?? []).map((s) => ({
      profileId: Number(s.profile_id ?? 0),
      businessName: s.business_name ?? "",
      reasonLabel: s.reason_label ?? "Not sent",
    })),
    subject: data.template?.subject ?? "",
    body: data.template?.body ?? "",
  }
}

export type InviteJob = { id: number; status: string; total: number; sent: number; failed: number }

export async function sendBatchInvites(
  auth: WpAuth,
  eventId: number,
  profileIds: number[],
  message = "",
): Promise<InviteJob> {
  const data = await wpFetchJson<{ id?: number; status?: string; total?: number; sent?: number; failed?: number }>(
    "/match/organizer/invites/batch",
    { auth, method: "POST", body: { event_id: eventId, profile_ids: profileIds, message } },
  )
  return {
    id: Number(data.id ?? 0),
    status: data.status ?? "queued",
    total: Number(data.total ?? 0),
    sent: Number(data.sent ?? 0),
    failed: Number(data.failed ?? 0),
  }
}

export async function shortlistVendors(auth: WpAuth, eventId: number, profileIds: number[], action: "add" | "remove" = "add") {
  await wpFetchJson("/match/organizer/recruitment/shortlist", {
    auth,
    method: "POST",
    body: { event_id: eventId, profile_ids: profileIds, action },
  })
}

export type RecruitmentSummary = {
  enabled: boolean
  suggestions: Array<{ type: string; title: string; message: string }>
  readiness: Array<{ key: string; label: string; done: boolean }>
  matchingCount: number
  justPublished: boolean
}

export async function fetchRecruitment(auth: WpAuth, eventId: number): Promise<RecruitmentSummary> {
  const data = await wpFetchJson<{
    enabled?: boolean
    suggestions?: Array<{ type: string; title: string; message: string }>
    readiness?: Array<{ key: string; label: string; done: boolean }>
    matching_count?: number
    just_published?: boolean
  }>(`/match/organizer/events/${eventId}/recruitment`, { auth })
  return {
    enabled: data.enabled !== false,
    suggestions: data.suggestions ?? [],
    readiness: data.readiness ?? [],
    matchingCount: Number(data.matching_count ?? 0),
    justPublished: Boolean(data.just_published),
  }
}

/* ------------------------------------------------------------------ */
/* Tours                                                               */
/* ------------------------------------------------------------------ */

export type TourStop = {
  eventId: number
  title: string
  date: string
  city: string
  state: string
  accepting: boolean
  matchesMe: boolean
  feeMin: number | null
  feeMax: number | null
  opportunities: Array<{
    opportunityId: number
    title: string
    isOpen: boolean
    applicationStatus: string
    /** Withdrawn, or declined and re-opened by the organizer: applying again is allowed. */
    canApplyAgain: boolean
    spotsRemaining: number
  }>
}

export type TourDetail = {
  id: number
  title: string
  description: string
  type: "tour" | "series"
  organizerName: string
  matchingStops: number
  stops: TourStop[]
}

type WpTourStop = {
  event_id?: number
  title?: string
  date?: string
  city?: string
  state?: string
  accepting?: boolean
  matches_me?: boolean
  fee_min?: number | null
  fee_max?: number | null
  opportunities?: Array<{
    opportunity_id?: number
    title?: string
    is_open?: boolean
    application_status?: string
    can_apply_again?: boolean
    spots_remaining?: number
  }>
}

export async function fetchTour(auth: WpAuth | undefined, tourId: number): Promise<TourDetail> {
  const data = await wpFetchJson<{
    id?: number
    title?: string
    description?: string
    type?: string
    organizer_name?: string
    matching_stops?: number
    stops?: WpTourStop[]
  }>(`/tours/${tourId}`, { auth })
  return {
    id: Number(data.id ?? tourId),
    title: data.title ?? "",
    description: data.description ?? "",
    type: data.type === "series" ? "series" : "tour",
    organizerName: data.organizer_name ?? "",
    matchingStops: Number(data.matching_stops ?? 0),
    stops: (data.stops ?? []).map((s) => ({
      eventId: Number(s.event_id ?? 0),
      title: s.title ?? "",
      date: s.date ?? "",
      city: s.city ?? "",
      state: s.state ?? "",
      accepting: Boolean(s.accepting),
      matchesMe: Boolean(s.matches_me),
      feeMin: s.fee_min ?? null,
      feeMax: s.fee_max ?? null,
      opportunities: (s.opportunities ?? []).map((o) => ({
        opportunityId: Number(o.opportunity_id ?? 0),
        title: o.title ?? "",
        isOpen: Boolean(o.is_open),
        applicationStatus: o.application_status ?? "",
        canApplyAgain: o.can_apply_again === true,
        spotsRemaining: Number(o.spots_remaining ?? 0),
      })),
    })),
  }
}
