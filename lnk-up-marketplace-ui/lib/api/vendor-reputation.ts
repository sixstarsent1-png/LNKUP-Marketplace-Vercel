import { getPublicWpSiteUrl } from "@/lib/api/config"

export type VendorReputationTier =
  | "new"
  | "building"
  | "trusted"
  | "top_rated"
  | "at_risk"
  | "unknown"

export type VendorReputationBadgeType =
  | "verified"
  | "documents"
  | "completed_events"
  | "rating"
  | "reliability"
  | "response"
  | "platform"

export type VendorReputationBadge = {
  id: string
  label: string
  description?: string
  type: VendorReputationBadgeType
  earned: boolean
  earnedAt?: string
  state?: "earned" | "locked" | "in_progress" | "unavailable"
}

export type VendorReputationSummary = {
  vendorId?: string
  profileId?: string
  tier: VendorReputationTier
  tierLabel?: string
  score?: number
  rating?: number
  reviewCount: number
  completedEvents: number
  noShowRate?: number
  cancellationRate?: number
  responseRate?: number
  paymentReliability?: number
  documentCompliance?: number
  wouldBookAgainRate?: number
  badges: VendorReputationBadge[]
  strengths?: string[]
  improvements?: string[]
  raw?: unknown
}

export type OrganizerReview = {
  id: string
  eventId?: string
  eventName?: string
  eventDate?: string
  organizerName?: string
  rating?: number
  text?: string
  wouldBookAgain?: boolean
  categories?: {
    professionalism?: number
    setupReadiness?: number
    productQuality?: number
    communication?: number
    cleanliness?: number
    arrivalTime?: number
    customerEngagement?: number
  }
  createdAt?: string
  raw?: unknown
}

export type VendorCompletedEvent = {
  id: string
  eventId?: string
  applicationId?: string
  title: string
  date?: string
  city?: string
  state?: string
  organizerName?: string
  status: "completed" | "review_pending" | "reviewed" | "unknown"
  rating?: number
  badgeEarned?: string
  raw?: unknown
}

export type VendorReviewEligibility = {
  applicationId: string
  eventId?: string
  eventName: string
  status:
    | "awaiting_organizer_review"
    | "review_received"
    | "vendor_feedback_available"
    | "not_eligible"
  reviewId?: string
  feedbackSubmitted?: boolean
}

export type OrganizerVendorReputationPreview = {
  tier: VendorReputationTier
  tierLabel?: string
  score?: number
  rating?: number
  reviewCount?: number
  completedEvents?: number
  wouldBookAgainRate?: number
  badges?: VendorReputationBadge[]
  riskFlags?: Array<{
    id: string
    label: string
    severity: "info" | "warning" | "urgent"
  }>
}

export type PublicVendorReputation = {
  slug?: string
  businessName?: string
  score?: number
  rating?: number
  reviewCount: number
  completedEvents: number
  tier: VendorReputationTier
  badges: VendorReputationBadge[]
  highlights?: OrganizerReview[]
}

export type VendorReputationResponse = {
  summary: VendorReputationSummary
  completedEvents: VendorCompletedEvent[]
}

const BADGE_SLUG_MAP: Record<string, { label: string; type: VendorReputationBadgeType; description?: string }> = {
  verified: { label: "Verified Vendor", type: "verified", description: "Identity and profile verified" },
  verified_vendor: { label: "Verified Vendor", type: "verified" },
  lnkup_verified: { label: "LNKup Approved", type: "platform" },
  lnkup_approved: { label: "LNKup Approved", type: "platform" },
  documents_ready: { label: "Documents Ready", type: "documents" },
  insurance_verified: { label: "Insurance Verified", type: "documents" },
  license_verified: { label: "License Verified", type: "documents" },
  health_permit_verified: { label: "Health Permit Verified", type: "documents" },
  top_rated: { label: "Top Rated Vendor", type: "rating" },
  top_rated_vendor: { label: "Top Rated Vendor", type: "rating" },
  completed_10: { label: "Completed 10+ Events", type: "completed_events" },
  completed_50: { label: "Completed 50+ Events", type: "completed_events" },
  fast_responder: { label: "Fast Responder", type: "response" },
  reliable_setup: { label: "Reliable Setup", type: "reliability" },
  would_book_again: { label: "Would Book Again", type: "reliability" },
  verified_organizer: { label: "Verified Organizer", type: "verified" },
}

export const TIER_LABELS: Record<VendorReputationTier, string> = {
  new: "New vendor",
  building: "Building reputation",
  trusted: "Trusted vendor",
  top_rated: "Top rated",
  at_risk: "Needs review",
  unknown: "Reputation pending",
}

export function mapVendorReputationTier(input: {
  score?: number
  label?: string
  isNew?: boolean
  noShowRate?: number
  cancellationRate?: number
}): VendorReputationTier {
  const label = (input.label ?? "").toLowerCase()
  if (input.isNew || label.includes("new vendor")) return "new"
  if (label.includes("elite") || label.includes("top rated")) return "top_rated"
  if (label.includes("trusted")) return "trusted"
  if ((input.noShowRate ?? 0) > 0.15 || (input.cancellationRate ?? 0) > 0.2) return "at_risk"
  const score = input.score ?? 0
  if (score >= 85) return "top_rated"
  if (score >= 70) return "trusted"
  if (score >= 40) return "building"
  if (score > 0) return "building"
  return "unknown"
}

export function mapWpBadge(
  slugOrItem: string | { slug?: string; label?: string; earned_at?: string; source?: string },
  earned = true,
): VendorReputationBadge {
  const slug =
    typeof slugOrItem === "string"
      ? slugOrItem
      : slugOrItem.slug ?? slugOrItem.label ?? "badge"
  const key = slug.toLowerCase().replace(/\s+/g, "_")
  const mapped = BADGE_SLUG_MAP[key] ?? {
    label: typeof slugOrItem === "object" && slugOrItem.label ? slugOrItem.label : slug.replace(/_/g, " "),
    type: "platform" as const,
  }
  const earnedAt = typeof slugOrItem === "object" ? slugOrItem.earned_at : undefined
  return {
    id: key,
    label: mapped.label,
    description: mapped.description,
    type: mapped.type,
    earned,
    earnedAt,
    state: earned ? "earned" : "locked",
  }
}

function formatDate(value?: string): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
}

function mapReviewStatus(status?: string): VendorCompletedEvent["status"] {
  const s = (status ?? "").toLowerCase()
  if (s === "reviewed" || s === "published") return "reviewed"
  if (s === "pending" || s === "awaiting" || s === "review_pending") return "review_pending"
  if (s === "completed" || s === "done") return "completed"
  return "unknown"
}

export function mapWpReview(item: Record<string, unknown>): OrganizerReview {
  const cats = (item.category_ratings ?? {}) as Record<string, number>
  return {
    id: String(item.id ?? ""),
    eventId: item.event_id ? String(item.event_id) : undefined,
    eventName: (item.event_title as string) ?? (item.event_name as string),
    eventDate: formatDate(item.event_date as string),
    organizerName: item.organizer_name as string | undefined,
    rating: typeof item.overall_rating === "number" ? item.overall_rating : undefined,
    text: item.public_text as string | undefined,
    wouldBookAgain:
      typeof cats.would_book_again === "number" ? cats.would_book_again >= 4 : undefined,
    categories: {
      professionalism: cats.professionalism,
      setupReadiness: cats.setup_readiness,
      productQuality: cats.quality ?? cats.product_quality,
      communication: cats.communication,
      cleanliness: cats.cleanliness,
      arrivalTime: cats.arrival_time,
      customerEngagement: cats.engagement ?? cats.customer_engagement,
    },
    createdAt: formatDate(item.created_at as string),
    raw: item,
  }
}

export function mapWpCompletedEvent(item: Record<string, unknown>): VendorCompletedEvent {
  const appId = item.application_id ? String(item.application_id) : undefined
  return {
    id: appId ?? String(item.event_id ?? ""),
    eventId: item.event_id ? String(item.event_id) : undefined,
    applicationId: appId,
    title: (item.event_name as string) ?? "Event",
    date: formatDate(item.date as string),
    city: item.city as string | undefined,
    state: item.state as string | undefined,
    organizerName: (item.organizer_name as string) ?? (item.organizer as string),
    status: mapReviewStatus(item.review_status as string),
    rating: typeof item.rating === "number" ? item.rating : undefined,
    raw: item,
  }
}

export function mapWpEligibilityFromEvent(event: VendorCompletedEvent): VendorReviewEligibility {
  let status: VendorReviewEligibility["status"] = "not_eligible"
  if (event.status === "review_pending") status = "awaiting_organizer_review"
  else if (event.status === "reviewed") status = "review_received"
  else if (event.status === "completed") status = "awaiting_organizer_review"

  return {
    applicationId: event.applicationId ?? event.id,
    eventId: event.eventId,
    eventName: event.title,
    status,
  }
}

type WpReputationBlock = {
  score?: number
  label?: string
  avg_rating?: number
  review_count?: number
  is_new?: boolean
  badges?: Array<{ slug?: string; label?: string; earned_at?: string }>
  metrics?: {
    completed_events?: number
    no_show_rate?: number
    cancellation_rate?: number
    response_rate?: number
    payment_completion_rate?: number
    document_compliance_rate?: number
    repeat_booking_rate?: number
    avg_organizer_rating?: number
    review_count?: number
  }
  public_slug?: string
}

type WpVendorDashboard = {
  reputation?: WpReputationBlock
  tips?: string[]
  reviews?: unknown[]
  events?: unknown[]
  available_badges?: Array<{ slug?: string; label?: string }>
}

export function mapWpVendorDashboard(
  data: WpVendorDashboard,
  profileId?: string,
  verificationBadges?: string[],
): VendorReputationResponse {
  const rep = data.reputation ?? {}
  const metrics = rep.metrics ?? {}
  const tier = mapVendorReputationTier({
    score: rep.score,
    label: rep.label,
    isNew: rep.is_new,
    noShowRate: metrics.no_show_rate,
    cancellationRate: metrics.cancellation_rate,
  })

  const earnedBadges = (rep.badges ?? []).map((b) => mapWpBadge(b, true))
  const availableBadges = (data.available_badges ?? []).map((b) =>
    mapWpBadge(b.slug ?? b.label ?? "badge", false),
  )
  const verificationMapped = (verificationBadges ?? []).map((b) => mapWpBadge(b, true))

  const badgeMap = new Map<string, VendorReputationBadge>()
  for (const b of [...earnedBadges, ...verificationMapped, ...availableBadges]) {
    const existing = badgeMap.get(b.id)
    if (!existing || b.earned) badgeMap.set(b.id, b)
  }

  const summary: VendorReputationSummary = {
    profileId,
    tier,
    tierLabel: rep.label ?? TIER_LABELS[tier],
    score: rep.score,
    rating: rep.avg_rating ?? metrics.avg_organizer_rating,
    reviewCount: rep.review_count ?? metrics.review_count ?? 0,
    completedEvents: metrics.completed_events ?? 0,
    noShowRate: metrics.no_show_rate,
    cancellationRate: metrics.cancellation_rate,
    responseRate: metrics.response_rate,
    paymentReliability: metrics.payment_completion_rate,
    documentCompliance: metrics.document_compliance_rate,
    wouldBookAgainRate: metrics.repeat_booking_rate,
    badges: Array.from(badgeMap.values()),
    strengths: [],
    improvements: data.tips ?? [],
    raw: data,
  }

  const completedEvents = (data.events ?? []).map((e) =>
    mapWpCompletedEvent(e as Record<string, unknown>),
  )

  return { summary, completedEvents }
}

export function mapOrganizerReputationPreview(
  crm: Record<string, unknown>,
  intel?: Record<string, unknown>,
): OrganizerVendorReputationPreview {
  const rep = (crm.reputation ?? {}) as WpReputationBlock
  const reliability = (crm.reliability ?? crm.metrics ?? {}) as Record<string, number>
  const score = (crm.vendor_score as number) ?? (crm.reputation_score as number) ?? rep.score
  const tier = mapVendorReputationTier({
    score,
    label: (crm.reputation_label as string) ?? rep.label,
    isNew: rep.is_new,
    noShowRate: reliability.no_show_rate,
    cancellationRate: reliability.cancellation_rate,
  })

  const badges: VendorReputationBadge[] = []
  if (crm.verified) badges.push(mapWpBadge("verified", true))
  for (const b of (crm.badges as string[]) ?? []) {
    badges.push(mapWpBadge(b, true))
  }
  for (const b of rep.badges ?? []) {
    badges.push(mapWpBadge(b, true))
  }

  const riskFlags: OrganizerVendorReputationPreview["riskFlags"] = []
  if (crm.no_show_risk && Number(crm.no_show_risk) > 0.1) {
    riskFlags.push({
      id: "no_show",
      label: "Higher no-show history — confirm arrival plan",
      severity: "warning",
    })
  }
  const intelFlags = (intel?.flags ?? []) as Array<{ type?: string; label?: string; message?: string }>
  for (const flag of intelFlags) {
    if (flag.label || flag.message) {
      riskFlags.push({
        id: flag.type ?? "flag",
        label: flag.label ?? flag.message ?? "Review vendor history",
        severity: "info",
      })
    }
  }

  return {
    tier,
    tierLabel: (crm.reputation_label as string) ?? rep.label ?? TIER_LABELS[tier],
    score,
    rating: (crm.avg_rating as number) ?? rep.avg_rating,
    reviewCount: (crm.review_count as number) ?? rep.review_count,
    completedEvents: (crm.completed_events as number) ?? rep.metrics?.completed_events,
    wouldBookAgainRate: reliability.repeat_booking_rate,
    badges,
    riskFlags: riskFlags.length ? riskFlags : undefined,
  }
}

export function mapPublicVendorReputation(data: Record<string, unknown>): PublicVendorReputation {
  const rep = (data.reputation ?? {}) as WpReputationBlock
  const metrics = rep.metrics ?? {}
  const tier = mapVendorReputationTier({
    score: rep.score,
    label: rep.label,
    isNew: rep.is_new,
  })

  const badges = (rep.badges ?? []).map((b) => mapWpBadge(b, true))
  if (data.verified) badges.unshift(mapWpBadge("verified", true))

  const reviews = ((data.reviews as unknown[]) ?? [])
    .slice(0, 3)
    .map((r) => mapWpReview(r as Record<string, unknown>))

  return {
    slug: (data.public_slug as string) ?? undefined,
    businessName: data.business_name as string | undefined,
    score: rep.score,
    rating: rep.avg_rating,
    reviewCount: rep.review_count ?? metrics.review_count ?? 0,
    completedEvents: metrics.completed_events ?? 0,
    tier,
    badges,
    highlights: reviews,
  }
}

export function buildFeedbackHandoff(): {
  status: "handoff_required"
  message: string
  wordpressUrl?: string
} {
  const base = getPublicWpSiteUrl()
  return {
    status: "handoff_required",
    message: "Review collection is managed on LNKup web right now.",
    wordpressUrl: base ? `${base}/vendor-dashboard/?view=reviews` : undefined,
  }
}

export const mockVendorReputationNew: VendorReputationResponse = {
  summary: {
    tier: "new",
    tierLabel: "New vendor — building reputation",
    score: 12,
    rating: undefined,
    reviewCount: 0,
    completedEvents: 0,
    badges: [
      mapWpBadge("verified", false),
      mapWpBadge("documents_ready", false),
      mapWpBadge("top_rated", false),
    ].map((b) => ({ ...b, state: "locked" as const })),
    improvements: ["Complete your profile", "Upload required documents", "Book your first event"],
  },
  completedEvents: [],
}

export const mockVendorReputationTrusted: VendorReputationResponse = {
  summary: {
    tier: "trusted",
    tierLabel: "Trusted vendor",
    score: 78,
    rating: 4.7,
    reviewCount: 12,
    completedEvents: 14,
    responseRate: 0.92,
    paymentReliability: 0.98,
    documentCompliance: 1,
    badges: [
      mapWpBadge("verified", true),
      mapWpBadge("documents_ready", true),
      mapWpBadge("completed_10", true),
      mapWpBadge("fast_responder", true),
      mapWpBadge("top_rated", false),
    ],
    strengths: ["Strong communication", "Reliable setup", "On-time check-in"],
    improvements: ["Add more product photos"],
  },
  completedEvents: [
    {
      id: "req-4",
      applicationId: "req-4",
      title: "Nashville Street Fair",
      date: "May 10, 2026",
      city: "Nashville",
      state: "TN",
      organizerName: "Music City Markets",
      status: "reviewed",
      rating: 5,
    },
    {
      id: "req-5",
      applicationId: "req-5",
      title: "Atlanta Food Fest",
      date: "Apr 2, 2026",
      city: "Atlanta",
      state: "GA",
      organizerName: "Southern Eats Co.",
      status: "review_pending",
    },
  ],
}

export const mockVendorReputationTopRated: VendorReputationResponse = {
  summary: {
    tier: "top_rated",
    tierLabel: "Top rated vendor",
    score: 94,
    rating: 4.9,
    reviewCount: 48,
    completedEvents: 52,
    responseRate: 0.97,
    paymentReliability: 1,
    documentCompliance: 1,
    wouldBookAgainRate: 0.96,
    badges: [
      mapWpBadge("verified", true),
      mapWpBadge("lnkup_approved", true),
      mapWpBadge("documents_ready", true),
      mapWpBadge("top_rated", true),
      mapWpBadge("completed_50", true),
      mapWpBadge("would_book_again", true),
      mapWpBadge("reliable_setup", true),
    ],
    strengths: ["Exceptional professionalism", "Consistent quality", "Organizers rebook often"],
    improvements: [],
  },
  completedEvents: mockVendorReputationTrusted.completedEvents,
}

export const mockOrganizerReviews: OrganizerReview[] = [
  {
    id: "rev-1",
    eventName: "Charlotte Night Market",
    eventDate: "Jun 1, 2026",
    organizerName: "Queen City Events",
    rating: 5,
    text: "Professional setup, great crowd engagement, and easy to work with on event day.",
    wouldBookAgain: true,
    categories: {
      professionalism: 5,
      setupReadiness: 5,
      communication: 5,
      customerEngagement: 5,
    },
    createdAt: "Jun 3, 2026",
  },
  {
    id: "rev-2",
    eventName: "Raleigh Summer Fair",
    eventDate: "Aug 20, 2025",
    organizerName: "Triangle Fairs",
    rating: 4,
    text: "Solid vendor — arrived on time and kept the booth clean throughout the day.",
    wouldBookAgain: true,
    categories: {
      professionalism: 4,
      arrivalTime: 5,
      cleanliness: 4,
    },
    createdAt: "Aug 22, 2025",
  },
]

export const mockReviewEligibility: VendorReviewEligibility[] = [
  {
    applicationId: "req-5",
    eventName: "Atlanta Food Fest",
    status: "awaiting_organizer_review",
  },
  {
    applicationId: "req-4",
    eventName: "Nashville Street Fair",
    status: "review_received",
    reviewId: "rev-1",
  },
]

export function mockVendorReputation(): VendorReputationResponse {
  return mockVendorReputationTrusted
}
