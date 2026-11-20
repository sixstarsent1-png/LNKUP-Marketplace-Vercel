import type { OrganizerVendorReputationPreview } from "@/lib/api/vendor-reputation"
import { mapOrganizerReputationPreview } from "@/lib/api/vendor-reputation"
import type { OrganizerVendorNoteSummary, OrganizerVendorTimelineItem } from "@/lib/api/organizer-crm"
import { getOrganizerDashboardUrl } from "@/lib/api/config"

export type OrganizerVendorDocStatus = {
  type: string
  status: string
}

export type OrganizerVendorSharedEvent = {
  applicationId?: string
  eventId?: string
  eventTitle: string
  status: string
  date?: string
}

export type OrganizerVendorProfilePreview = {
  id: string
  businessName: string
  category?: string
  description?: string
  logoUrl?: string
  coverUrl?: string
  photos?: string[]
  citiesServed?: string[]
  statesServed?: string[]
  products?: string[]
  website?: string
  instagram?: string
  tiktok?: string
  contactEmail?: string
  contactPhone?: string
  documentsStatus?: string
  documents?: OrganizerVendorDocStatus[]
  trustBadges?: string[]
  completedEvents?: number
  rating?: number
  reputation?: OrganizerVendorReputationPreview
  applicationMessage?: string
  setupNeeds?: {
    setupType?: string
    powerNeeds?: string
    waterNeeds?: string
    spaceNeeds?: string
    tentSize?: string
    truckSize?: string
    trailerSize?: string
  }
  pastEvents?: OrganizerVendorSharedEvent[]
  upcomingEvents?: OrganizerVendorSharedEvent[]
  profileStrengthScore?: number
  profileStrengthTier?: string
  wordpressCrmUrl?: string
  readinessBlockers?: string[]
  timeline?: OrganizerVendorTimelineItem[]
  notes?: OrganizerVendorNoteSummary[]
  raw?: unknown
}

type WpCrmVendor = {
  profile_id?: number
  business_name?: string
  category?: string | string[]
  description?: string
  logo?: string
  cover_image?: string
  gallery?: string[]
  city?: string
  state?: string
  cities?: string[]
  cities_served?: string[]
  states?: string[]
  states_served?: string[]
  products?: string[]
  products_services?: string
  website?: string
  instagram?: string
  tiktok?: string
  email?: string
  phone?: string
  verified?: boolean
  badges?: string[] | Array<{ id?: string; label?: string; name?: string }>
  completed_events?: number
  avg_rating?: number
  review_count?: number
  vendor_score?: number
  reputation_score?: number
  reputation_label?: string
  no_show_risk?: number | string
  reputation?: Record<string, unknown>
  reliability?: Record<string, unknown>
  setup_type?: string
  power_needs?: string
  water_needs?: string
  tent_size?: string
  booth_size?: string
  truck_size?: string
  trailer_size?: string
  space_needed?: string
  documents?: Array<{ type?: string; status?: string }>
  past_events?: Array<Record<string, unknown>>
  upcoming_events?: Array<Record<string, unknown>>
  profile_strength_score?: number
  profile_strength_tier?: string
}

function mapSharedEvents(rows: Array<Record<string, unknown>> | undefined): OrganizerVendorSharedEvent[] | undefined {
  if (!Array.isArray(rows) || rows.length === 0) return undefined
  return rows.map((row) => ({
    applicationId: row.application_id != null ? String(row.application_id) : undefined,
    eventId: row.event_id != null ? String(row.event_id) : undefined,
    eventTitle: String(row.event_title ?? "Event"),
    status: String(row.status ?? ""),
    date: row.date ? String(row.date) : undefined,
  }))
}

function mapProducts(data: WpCrmVendor): string[] | undefined {
  if (Array.isArray(data.products) && data.products.length) return data.products.map(String)
  if (typeof data.products_services === "string" && data.products_services.trim()) {
    return data.products_services
      .split(/[,;\n]/)
      .map((p) => p.trim())
      .filter(Boolean)
  }
  return undefined
}

function mapBadges(data: WpCrmVendor): string[] {
  const raw = data.badges ?? []
  const labels = raw.map((badge) => {
    if (typeof badge === "string") return badge
    return String(badge.label ?? badge.name ?? badge.id ?? "")
  }).filter(Boolean)
  if (data.verified) labels.push("verified")
  return labels
}

function mapReadinessBlockers(intel?: {
  message?: string
  readiness_label?: string
  flags?: unknown[]
  readiness?: { items?: Array<{ label?: string; done?: boolean; status?: string }> } | Array<{ label?: string; done?: boolean; status?: string }>
}): string[] | undefined {
  const fromFlags = Array.isArray(intel?.flags)
    ? intel.flags
        .map((flag) =>
          typeof flag === "string"
            ? flag
            : typeof flag === "object" && flag && "label" in flag
              ? String((flag as { label?: string }).label ?? "")
              : "",
        )
        .filter(Boolean)
    : []

  const readinessRows = Array.isArray(intel?.readiness)
    ? intel.readiness
    : Array.isArray(intel?.readiness?.items)
      ? intel.readiness.items
      : []

  const fromChecklist = readinessRows
    .filter((row) => row && row.done !== true && row.status !== "done")
    .map((row) => String(row.label ?? "").trim())
    .filter(Boolean)

  const merged = [...fromChecklist, ...fromFlags]
  return merged.length ? merged.slice(0, 8) : undefined
}

export function mapOrganizerVendorProfile(
  profileId: string,
  data: WpCrmVendor,
  intel?: {
    message?: string
    readiness_label?: string
    flags?: unknown[]
    readiness?: { items?: Array<{ label?: string; done?: boolean; status?: string }> } | Array<{ label?: string; done?: boolean; status?: string }>
  },
): OrganizerVendorProfilePreview {
  const category = Array.isArray(data.category) ? data.category.join(", ") : data.category
  const dash = getOrganizerDashboardUrl()
  const gallery = Array.isArray(data.gallery) ? data.gallery.filter(Boolean).map(String) : undefined

  return {
    id: profileId,
    businessName: data.business_name || "Vendor",
    category,
    description: data.description,
    logoUrl: data.logo,
    coverUrl: data.cover_image,
    photos: gallery,
    citiesServed: data.cities_served ?? data.cities ?? (data.city ? [data.city] : undefined),
    statesServed: data.states_served ?? data.states ?? (data.state ? [data.state] : undefined),
    products: mapProducts(data),
    website: data.website,
    instagram: data.instagram,
    tiktok: data.tiktok,
    contactEmail: data.email,
    contactPhone: data.phone,
    documentsStatus: intel?.readiness_label,
    documents: Array.isArray(data.documents)
      ? data.documents
          .map((doc) => ({
            type: String(doc.type ?? "Document"),
            status: String(doc.status ?? "—"),
          }))
          .filter((doc) => doc.type)
      : undefined,
    trustBadges: mapBadges(data),
    completedEvents: data.completed_events,
    rating: data.avg_rating,
    reputation: mapOrganizerReputationPreview(data as Record<string, unknown>, intel as Record<string, unknown>),
    applicationMessage: intel?.message,
    setupNeeds: {
      setupType: data.setup_type,
      powerNeeds: data.power_needs,
      waterNeeds: data.water_needs,
      spaceNeeds: data.space_needed,
      tentSize: data.tent_size ?? data.booth_size,
      truckSize: data.truck_size,
      trailerSize: data.trailer_size,
    },
    pastEvents: mapSharedEvents(data.past_events),
    upcomingEvents: mapSharedEvents(data.upcoming_events),
    profileStrengthScore: data.profile_strength_score,
    profileStrengthTier: data.profile_strength_tier,
    wordpressCrmUrl: dash ? `${dash}?vendor=${encodeURIComponent(profileId)}` : undefined,
    readinessBlockers: mapReadinessBlockers(intel),
    raw: { profile: data, intel },
  }
}
