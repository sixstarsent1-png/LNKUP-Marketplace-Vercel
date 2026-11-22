import type { EventItem } from "@/lib/data"
import { mapOpportunityList } from "@/lib/api/map-opportunity"
import type { WpOpportunityItem } from "@/lib/api/types"

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

export const VENDOR_MATCH_SECTION_LABELS: Record<VendorMatchSection, { title: string; subtitle: string }> = {
  recommended: { title: "Recommended for you", subtitle: "Matched to your categories and markets" },
  preferred_markets: { title: "Near your preferred cities", subtitle: "Upcoming events in your markets" },
  new: { title: "New opportunities", subtitle: "Recently published and accepting vendors" },
  deadlines: { title: "Application deadlines approaching", subtitle: "Relevant events closing soon" },
  previous_organizers: { title: "Previously worked with", subtitle: "New events from organizers you've booked with" },
  tours: { title: "Tours accepting vendors", subtitle: "Only the stops that match your markets" },
  series: { title: "Recurring markets", subtitle: "Series with upcoming dates" },
}

export type MatchBand = "strong" | "good" | "possible"

export interface VendorMatch {
  band: MatchBand
  bandLabel: string
  reasons: string[]
}

export interface MatchedOpportunity {
  kind: "opportunity"
  event: EventItem
  match: VendorMatch | null
  moreDates: number
}

export interface MatchedTour {
  kind: "tour"
  id: string
  title: string
  type: "tour" | "series"
  matchingStops: number
  places: string[]
  nextDate: string | null
  feeMin: number
  feeMax: number
  reasons: string[]
}

export type VendorMatchItem = MatchedOpportunity | MatchedTour

export type VendorMatchSectionResult =
  | { status: "disabled" }
  | { status: "needs_profile" }
  | { status: "ready"; section: VendorMatchSection; items: VendorMatchItem[]; total: number }

type WpVendorMatch = { band?: string; band_label?: string; reasons?: string[] }

type WpMatchedOpportunity = WpOpportunityItem & {
  match?: WpVendorMatch
  more_dates?: number
}

type WpMatchedTour = {
  kind: "tour"
  id: number
  title?: string
  type?: string
  matching_stops?: number
  places?: string[]
  next_date?: string | null
  fee_min?: number
  fee_max?: number
  match?: WpVendorMatch
}

export type WpVendorRecommendationsResponse = {
  enabled?: boolean
  needs_profile?: boolean
  section?: string
  total?: number
  items?: Array<WpMatchedOpportunity | WpMatchedTour>
}

function toBand(value: string | undefined): MatchBand {
  return value === "strong" || value === "good" ? value : "possible"
}

function mapVendorMatch(match: WpVendorMatch | undefined): VendorMatch | null {
  if (!match) return null
  return {
    band: toBand(match.band),
    bandLabel: match.band_label ?? "",
    reasons: Array.isArray(match.reasons) ? match.reasons.filter((r) => typeof r === "string") : [],
  }
}

function isWpTour(item: WpMatchedOpportunity | WpMatchedTour): item is WpMatchedTour {
  return (item as WpMatchedTour).kind === "tour"
}

export function isVendorMatchSection(value: string): value is VendorMatchSection {
  return (VENDOR_MATCH_SECTIONS as readonly string[]).includes(value)
}

/**
 * Map the WordPress recommendations payload into typed UI items.
 * Match bands only; raw scores are never sent to vendors.
 */
export function mapVendorRecommendations(
  section: VendorMatchSection,
  data: WpVendorRecommendationsResponse | undefined,
): VendorMatchSectionResult {
  if (!data || data.enabled === false) return { status: "disabled" }
  if (data.needs_profile) return { status: "needs_profile" }

  const raw = Array.isArray(data.items) ? data.items : []
  const tours = raw.filter(isWpTour)
  const opportunities = raw.filter((item): item is WpMatchedOpportunity => !isWpTour(item))
  const mapped = opportunities.flatMap((item): MatchedOpportunity[] => {
    const [event] = mapOpportunityList([item])
    if (!event) return []
    return [
      {
        kind: "opportunity",
        event,
        match: mapVendorMatch(item.match),
        moreDates: Number(item.more_dates ?? 0),
      },
    ]
  })

  const items: VendorMatchItem[] = [
    ...mapped,
    ...tours.map((tour): MatchedTour => ({
      kind: "tour",
      id: String(tour.id),
      title: tour.title ?? "",
      type: tour.type === "series" ? "series" : "tour",
      matchingStops: Number(tour.matching_stops ?? 0),
      places: Array.isArray(tour.places) ? tour.places : [],
      nextDate: tour.next_date ?? null,
      feeMin: Number(tour.fee_min ?? 0),
      feeMax: Number(tour.fee_max ?? 0),
      reasons: mapVendorMatch(tour.match)?.reasons ?? [],
    })),
  ]

  return { status: "ready", section, items, total: Number(data.total ?? items.length) }
}

/* -------------------------------------------------------------------------- */
/* Organizer: Find Vendors                                                    */
/* -------------------------------------------------------------------------- */

export const RECRUITMENT_STAGES = [
  "identified",
  "shortlisted",
  "invited",
  "opened",
  "applied",
  "approved",
  "paid",
  "declined",
] as const

export type RecruitmentStage = (typeof RECRUITMENT_STAGES)[number]

export interface MatchedVendor {
  profileId: string
  vendorUserId: string
  businessName: string
  logo: string
  primaryCategory: string
  categories: string[]
  city: string
  state: string
  description: string
  gallery: string[]
  verified: boolean
  completedEvents: number
  band: MatchBand
  reasons: string[]
  bestOpportunityId: string
  stage: RecruitmentStage | null
  acceptsInvites: boolean
}

type WpMatchedVendor = {
  profile_id?: number
  vendor_user_id?: number
  business_name?: string
  logo?: string
  primary_category?: string
  categories?: string[]
  city?: string
  state?: string
  description?: string
  gallery?: string[]
  verified?: boolean
  completed_events?: number
  pipeline_stage?: string
  accept_invites?: boolean
  match?: { band?: string; reasons?: string[]; best_opportunity_id?: number }
}

export type WpEventVendorsResponse = {
  enabled?: boolean
  needs_open_opportunity?: boolean
  items?: WpMatchedVendor[]
  total?: number
  page?: number
  pages?: number
}

export type EventVendorMatchesResult =
  | { status: "disabled" }
  | { status: "needs_open_opportunity" }
  | { status: "ready"; items: MatchedVendor[]; total: number; page: number; pages: number }

function toStage(value: string | undefined): RecruitmentStage | null {
  return value && (RECRUITMENT_STAGES as readonly string[]).includes(value) ? (value as RecruitmentStage) : null
}

export function mapEventVendorMatches(data: WpEventVendorsResponse | undefined): EventVendorMatchesResult {
  if (!data || data.enabled === false) return { status: "disabled" }
  if (data.needs_open_opportunity) return { status: "needs_open_opportunity" }
  const items = (Array.isArray(data.items) ? data.items : []).map(
    (v): MatchedVendor => ({
      profileId: String(v.profile_id ?? ""),
      vendorUserId: String(v.vendor_user_id ?? ""),
      businessName: v.business_name ?? "Vendor",
      logo: v.logo ?? "",
      primaryCategory: v.primary_category ?? "",
      categories: Array.isArray(v.categories) ? v.categories : [],
      city: v.city ?? "",
      state: v.state ?? "",
      description: v.description ?? "",
      gallery: Array.isArray(v.gallery) ? v.gallery : [],
      verified: Boolean(v.verified),
      completedEvents: Number(v.completed_events ?? 0),
      band: toBand(v.match?.band),
      reasons: Array.isArray(v.match?.reasons) ? v.match.reasons : [],
      bestOpportunityId: String(v.match?.best_opportunity_id ?? ""),
      stage: toStage(v.pipeline_stage),
      acceptsInvites: v.accept_invites !== false,
    }),
  )
  return {
    status: "ready",
    items,
    total: Number(data.total ?? items.length),
    page: Number(data.page ?? 1),
    pages: Number(data.pages ?? 1),
  }
}

export interface RecruitmentSuggestion {
  type: string
  title: string
  message: string
  action: string
}

export interface RecruitmentReadinessItem {
  key: string
  label: string
  done: boolean
}

export interface RecruitmentSummary {
  enabled: boolean
  readiness: RecruitmentReadinessItem[]
  suggestions: RecruitmentSuggestion[]
  pipeline: Record<RecruitmentStage, number>
  matchingCount: number
  justPublished: boolean
}

export type WpRecruitmentResponse = {
  enabled?: boolean
  readiness?: RecruitmentReadinessItem[]
  suggestions?: RecruitmentSuggestion[]
  pipeline?: Partial<Record<RecruitmentStage, number>>
  matching_count?: number
  just_published?: boolean
}

export function mapRecruitment(data: WpRecruitmentResponse | undefined): RecruitmentSummary {
  const pipeline = Object.fromEntries(
    RECRUITMENT_STAGES.map((stage) => [stage, Number(data?.pipeline?.[stage] ?? 0)]),
  ) as Record<RecruitmentStage, number>
  return {
    enabled: data?.enabled !== false,
    readiness: Array.isArray(data?.readiness) ? data.readiness : [],
    suggestions: Array.isArray(data?.suggestions) ? data.suggestions : [],
    pipeline,
    matchingCount: Number(data?.matching_count ?? 0),
    justPublished: Boolean(data?.just_published),
  }
}

export interface InvitePreview {
  recipients: Array<{ profileId: string; businessName: string }>
  suppressed: Array<{ profileId: string; businessName: string; reasonLabel: string }>
  subject: string
  body: string
}

export type WpInvitePreviewResponse = {
  recipients?: Array<{ profile_id?: number; business_name?: string }>
  suppressed?: Array<{ profile_id?: number; business_name?: string; reason_label?: string }>
  template?: { subject?: string; body?: string }
}

export function mapInvitePreview(data: WpInvitePreviewResponse | undefined): InvitePreview {
  return {
    recipients: (data?.recipients ?? []).map((r) => ({
      profileId: String(r.profile_id ?? ""),
      businessName: r.business_name ?? "",
    })),
    suppressed: (data?.suppressed ?? []).map((s) => ({
      profileId: String(s.profile_id ?? ""),
      businessName: s.business_name ?? "",
      reasonLabel: s.reason_label ?? "Not sent",
    })),
    subject: data?.template?.subject ?? "",
    body: data?.template?.body ?? "",
  }
}

export interface TourStop {
  eventId: string
  title: string
  date: string
  place: string
  accepting: boolean
  matchesMe: boolean
  feeLabel: string | null
  openOpportunityId: string | null
  applicationStatus: string | null
}

export interface TourDetail {
  id: string
  title: string
  description: string
  type: "tour" | "series"
  organizerName: string
  matchingStops: number
  stops: TourStop[]
}

export type WpTourDetail = {
  id?: number
  title?: string
  description?: string
  type?: string
  organizer_name?: string
  matching_stops?: number
  stops?: Array<{
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
      is_open?: boolean
      application_status?: string
      can_apply_again?: boolean
    }>
  }>
}

export function mapTourDetail(data: WpTourDetail): TourDetail {
  return {
    id: String(data.id ?? ""),
    title: data.title ?? "",
    description: data.description ?? "",
    type: data.type === "series" ? "series" : "tour",
    organizerName: data.organizer_name ?? "",
    matchingStops: Number(data.matching_stops ?? 0),
    stops: (data.stops ?? []).map((stop) => {
      const opportunities = stop.opportunities ?? []
      // Withdrawn or re-opened declined applications do not block applying again.
      const applied = opportunities.find((o) => o.application_status && !o.can_apply_again)
      const open = opportunities.find((o) => o.is_open && (!o.application_status || o.can_apply_again))
      const feeLabel =
        stop.fee_min == null
          ? null
          : stop.fee_min === stop.fee_max
            ? `$${stop.fee_min}`
            : `$${stop.fee_min}–$${stop.fee_max}`
      return {
        eventId: String(stop.event_id ?? ""),
        title: stop.title ?? "",
        date: stop.date ?? "",
        place: [stop.city, stop.state].filter(Boolean).join(", "),
        accepting: Boolean(stop.accepting),
        matchesMe: Boolean(stop.matches_me),
        feeLabel,
        openOpportunityId: open?.opportunity_id ? String(open.opportunity_id) : null,
        applicationStatus: applied?.application_status ?? null,
      }
    }),
  }
}

export interface InviteJob {
  id: string
  status: "queued" | "sending" | "complete" | "cancelled"
  total: number
  sent: number
  failed: number
  suppressed: number
}

export type WpInviteJobResponse = {
  id?: number
  status?: string
  total?: number
  sent?: number
  failed?: number
  suppressed?: number
}

export function mapInviteJob(data: WpInviteJobResponse | undefined): InviteJob {
  const status = data?.status
  return {
    id: String(data?.id ?? ""),
    status: status === "sending" || status === "complete" || status === "cancelled" ? status : "queued",
    total: Number(data?.total ?? 0),
    sent: Number(data?.sent ?? 0),
    failed: Number(data?.failed ?? 0),
    suppressed: Number(data?.suppressed ?? 0),
  }
}
