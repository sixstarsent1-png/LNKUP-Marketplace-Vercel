import { getOrganizerDashboardUrl, isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import { invalidateCache } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_ORGANIZER_VENDOR_DIRECTORY } from "@/lib/data/fixtures"

export type OrganizerDirectoryVendor = {
  id: string
  name: string
  category?: string
  city?: string
  state?: string
  citiesServed?: string[]
  logoUrl?: string
  coverUrl?: string
  rating?: number
  reviewCount?: number
  eventsCompleted?: number
  verified?: boolean
  favorite?: boolean
  wordpressUrl?: string
  vendorUserId?: string
}

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

export type OrganizerVendorSetupNeeds = {
  setupType?: string
  powerNeeds?: string
  waterNeeds?: string
  tentSize?: string
  trailerSize?: string
  truckSize?: string
  spaceNeeds?: string
}

export type OrganizerVendorReputationSummary = {
  tier?: string
  tierLabel?: string
  score?: number
  rating?: number
  reviewCount?: number
  completedEvents?: number
  wouldBookAgainRate?: number
}

export type OrganizerVendorProfilePeek = OrganizerDirectoryVendor & {
  bio?: string
  products?: string
  email?: string
  phone?: string
  website?: string
  instagram?: string
  coverUrl?: string
  logoUrl?: string
  gallery?: string[]
  documents?: OrganizerVendorDocStatus[]
  setupNeeds?: OrganizerVendorSetupNeeds
  pastEvents?: OrganizerVendorSharedEvent[]
  upcomingEvents?: OrganizerVendorSharedEvent[]
  reputation?: OrganizerVendorReputationSummary
  profileStrengthScore?: number
  profileStrengthTier?: string
  readinessBlockers?: string[]
  applicationMessage?: string
}

function categoryLabel(raw: unknown): string | undefined {
  if (typeof raw === "string" && raw.trim()) return raw
  if (Array.isArray(raw)) {
    const parts = raw
      .map((item) => {
        if (typeof item === "string") return item
        if (item && typeof item === "object") {
          const row = item as Record<string, unknown>
          return String(row.name ?? row.label ?? row.title ?? "")
        }
        return ""
      })
      .filter(Boolean)
    return parts.length ? parts.join(", ") : undefined
  }
  return undefined
}

function stringList(raw: unknown): string[] | undefined {
  if (typeof raw === "string" && raw.trim()) return [raw.trim()]
  if (!Array.isArray(raw)) return undefined
  const parts = raw.map((item) => String(item).trim()).filter(Boolean)
  return parts.length ? parts : undefined
}

function nonEmptyString(value: unknown): string | undefined {
  if (typeof value !== "string") return undefined
  const trimmed = value.trim()
  return trimmed ? trimmed : undefined
}

function crmVendorUrl(profileId: string): string | undefined {
  const base = getOrganizerDashboardUrl()
  return base ? `${base}?vendor=${encodeURIComponent(profileId)}` : undefined
}

function mapSharedEvents(rows: unknown): OrganizerVendorSharedEvent[] | undefined {
  if (!Array.isArray(rows) || rows.length === 0) return undefined
  return rows.map((row) => {
    const item = (row && typeof row === "object" ? row : {}) as Record<string, unknown>
    return {
      applicationId: item.application_id != null ? String(item.application_id) : undefined,
      eventId: item.event_id != null ? String(item.event_id) : undefined,
      eventTitle: String(item.event_title ?? "Event"),
      status: String(item.status ?? ""),
      date: item.date ? String(item.date) : undefined,
    }
  })
}

function mapDocuments(raw: unknown): OrganizerVendorDocStatus[] | undefined {
  if (!Array.isArray(raw) || raw.length === 0) return undefined
  return raw
    .map((row) => {
      const item = (row && typeof row === "object" ? row : {}) as Record<string, unknown>
      return {
        type: String(item.type ?? "Document"),
        status: String(item.status ?? "—"),
      }
    })
    .filter((doc) => doc.type)
}

function mapProductsLabel(item: Record<string, unknown>): string | undefined {
  if (typeof item.products_services === "string") return nonEmptyString(item.products_services)
  if (Array.isArray(item.products) && item.products.length) {
    return item.products.map(String).filter(Boolean).join(", ")
  }
  if (typeof item.products === "string") return nonEmptyString(item.products)
  return nonEmptyString(item.services)
}

function mapReputation(item: Record<string, unknown>): OrganizerVendorReputationSummary | undefined {
  const rep =
    item.reputation && typeof item.reputation === "object"
      ? (item.reputation as Record<string, unknown>)
      : {}
  const score =
    Number.isFinite(Number(rep.score ?? item.reputation_score ?? item.vendor_score))
      ? Number(rep.score ?? item.reputation_score ?? item.vendor_score)
      : undefined
  const rating =
    Number.isFinite(Number(rep.rating ?? item.avg_rating))
      ? Number(rep.rating ?? item.avg_rating)
      : undefined
  const reviewCount =
    Number.isFinite(Number(rep.review_count ?? item.review_count))
      ? Number(rep.review_count ?? item.review_count)
      : undefined
  const completedEvents =
    Number.isFinite(Number(rep.completed_events ?? item.completed_events))
      ? Number(rep.completed_events ?? item.completed_events)
      : undefined
  const tier = nonEmptyString(rep.tier ?? item.reputation_label ?? item.profile_strength_tier)
  const tierLabel = nonEmptyString(rep.tier_label ?? rep.label ?? item.reputation_label)
  const wouldBookAgainRate = Number.isFinite(Number(rep.would_book_again_rate))
    ? Number(rep.would_book_again_rate)
    : undefined

  if (
    score == null &&
    rating == null &&
    reviewCount == null &&
    completedEvents == null &&
    !tier &&
    !tierLabel
  ) {
    return undefined
  }

  return {
    tier,
    tierLabel,
    score,
    rating,
    reviewCount,
    completedEvents,
    wouldBookAgainRate,
  }
}

function mapReadinessBlockers(intel: Record<string, unknown> | undefined): string[] | undefined {
  if (!intel) return undefined
  const readiness = intel.readiness
  const rows = Array.isArray(readiness)
    ? readiness
    : readiness && typeof readiness === "object" && Array.isArray((readiness as { items?: unknown[] }).items)
      ? ((readiness as { items: unknown[] }).items)
      : []

  const blockers = rows
    .map((row) => {
      const item = (row && typeof row === "object" ? row : {}) as Record<string, unknown>
      if (item.done === true || item.status === "done") return ""
      return String(item.label ?? "").trim()
    })
    .filter(Boolean)

  return blockers.length ? blockers.slice(0, 8) : undefined
}

export function mapDirectoryVendor(item: Record<string, unknown>): OrganizerDirectoryVendor | null {
  const id = String(item.profile_id ?? item.id ?? item.vendor_profile_id ?? "")
  if (!id) return null
  const name = String(item.business_name ?? item.name ?? item.title ?? "Vendor")
  return {
    id,
    name,
    category: categoryLabel(item.category ?? item.categories),
    city: item.city ? String(item.city) : undefined,
    state: item.state ? String(item.state) : undefined,
    citiesServed: stringList(item.cities_served ?? item.citiesServed),
    logoUrl: nonEmptyString(item.logo ?? item.logo_url),
    coverUrl: nonEmptyString(item.cover_image ?? item.cover ?? item.cover_url),
    rating: Number.isFinite(Number(item.avg_rating)) ? Number(item.avg_rating) : undefined,
    reviewCount: Number.isFinite(Number(item.review_count)) ? Number(item.review_count) : undefined,
    eventsCompleted: Number.isFinite(Number(item.completed_events))
      ? Number(item.completed_events)
      : undefined,
    verified: Boolean(item.verified),
    favorite: Boolean(item.is_favorite ?? item.favorite),
    wordpressUrl: crmVendorUrl(id),
    vendorUserId: item.vendor_user_id ? String(item.vendor_user_id) : undefined,
  }
}

export function mapVendorProfilePeek(
  item: Record<string, unknown>,
  intel?: Record<string, unknown>,
): OrganizerVendorProfilePeek | null {
  const base = mapDirectoryVendor(item)
  if (!base) return null

  const gallery = Array.isArray(item.gallery)
    ? item.gallery.map(String).filter(Boolean)
    : undefined

  const setupNeeds: OrganizerVendorSetupNeeds = {
    setupType: nonEmptyString(item.setup_type),
    powerNeeds: nonEmptyString(item.power_needs),
    waterNeeds: nonEmptyString(item.water_needs),
    tentSize: nonEmptyString(item.tent_size ?? item.booth_size),
    trailerSize: nonEmptyString(item.trailer_size),
    truckSize: nonEmptyString(item.truck_size),
    spaceNeeds: nonEmptyString(item.space_needed),
  }
  const hasSetup = Object.values(setupNeeds).some(Boolean)

  return {
    ...base,
    bio: nonEmptyString(item.bio ?? item.about ?? item.description),
    products: mapProductsLabel(item),
    email: nonEmptyString(item.email ?? item.contact_email),
    phone: nonEmptyString(item.phone ?? item.contact_phone),
    website: nonEmptyString(item.website),
    instagram: nonEmptyString(item.instagram),
    coverUrl: nonEmptyString(item.cover_image ?? item.cover_url),
    logoUrl: nonEmptyString(item.logo ?? item.logo_url),
    gallery,
    documents: mapDocuments(item.documents),
    setupNeeds: hasSetup ? setupNeeds : undefined,
    pastEvents: mapSharedEvents(item.past_events),
    upcomingEvents: mapSharedEvents(item.upcoming_events),
    reputation: mapReputation(item),
    profileStrengthScore: Number.isFinite(Number(item.profile_strength_score))
      ? Number(item.profile_strength_score)
      : undefined,
    profileStrengthTier: nonEmptyString(item.profile_strength_tier),
    readinessBlockers: mapReadinessBlockers(intel),
    applicationMessage:
      typeof intel?.message === "string" ? nonEmptyString(intel.message) : undefined,
    vendorUserId: item.vendor_user_id ? String(item.vendor_user_id) : base.vendorUserId,
    wordpressUrl: base.wordpressUrl ?? crmVendorUrl(base.id),
  }
}

export type OrganizerDiscoverSort =
  | "score"
  | "rating"
  | "completed"
  | "reliable"
  | "new"

export type OrganizerDiscoverFilters = {
  city?: string
  category?: string
  verified?: boolean
  sort?: OrganizerDiscoverSort
}

export async function fetchOrganizerVendorDirectory(options: {
  auth?: WpAuth
  query?: string
  scope?: "discover" | "my"
  page?: number
  perPage?: number
  filters?: OrganizerDiscoverFilters
  signal?: AbortSignal
}): Promise<{ items: OrganizerDirectoryVendor[]; total: number; page: number; pages: number }> {
  const { auth, query, scope = "discover", page = 1, perPage = 24, filters, signal } = options

  if (!isLiveApiEnabled()) {
    const q = (query ?? "").trim().toLowerCase()
    const cityFilter = (filters?.city ?? "").trim().toLowerCase()
    const categoryFilter = (filters?.category ?? "").trim().toLowerCase()
    let filtered = MOCK_ORGANIZER_VENDOR_DIRECTORY.filter((vendor) => {
      if (q) {
        const hay = `${vendor.name} ${vendor.category ?? ""} ${vendor.city ?? ""}`.toLowerCase()
        if (!hay.includes(q)) return false
      }
      if (cityFilter && !(vendor.city ?? "").toLowerCase().includes(cityFilter)) return false
      if (categoryFilter && !(vendor.category ?? "").toLowerCase().includes(categoryFilter)) {
        return false
      }
      if (filters?.verified && !vendor.verified) return false
      return true
    })
    const sort = filters?.sort ?? "score"
    filtered = [...filtered].sort((a, b) => {
      if (sort === "rating") return (b.rating ?? 0) - (a.rating ?? 0)
      if (sort === "completed") return (b.eventsCompleted ?? 0) - (a.eventsCompleted ?? 0)
      if (sort === "new") return (a.eventsCompleted ?? 0) - (b.eventsCompleted ?? 0)
      return (b.rating ?? 0) - (a.rating ?? 0)
    })
    const start = (page - 1) * perPage
    const items = filtered.slice(start, start + perPage)
    return {
      items,
      total: filtered.length,
      page,
      pages: Math.max(1, Math.ceil(filtered.length / perPage)),
    }
  }

  if (!auth) {
    return { items: [], total: 0, page: 1, pages: 1 }
  }

  const data = await wpFetchJson<{
    items?: unknown[]
    total?: number
    page?: number
    pages?: number
  }>("/crm/vendors", {
    auth,
    query: {
      scope,
      q: query?.trim() || undefined,
      city: filters?.city?.trim() || undefined,
      category: filters?.category?.trim() || undefined,
      verified: filters?.verified ? 1 : undefined,
      sort: filters?.sort || undefined,
      per_page: perPage,
      page,
    },
    signal,
  })

  const items = (Array.isArray(data.items) ? data.items : [])
    .map((row) => mapDirectoryVendor((row && typeof row === "object" ? row : {}) as Record<string, unknown>))
    .filter((row): row is OrganizerDirectoryVendor => Boolean(row))

  const total = data.total ?? items.length
  const pages = data.pages ?? Math.max(1, Math.ceil(total / perPage))

  return { items, total, page: data.page ?? page, pages }
}

export type InviteResult = { status: "sent" } | { status: "already_invited"; message: string }

/**
 * Invite a vendor to apply to one of your events (and optionally a specific vendor slot).
 * The server keeps one active invite per vendor/event/slot; a repeat returns `already_invited`.
 */
export async function inviteVendorFromDirectory(
  input: { vendorUserId: string; eventId?: string; opportunityId?: string; message?: string },
  auth?: WpAuth,
): Promise<InviteResult> {
  if (!isLiveApiEnabled()) return { status: "sent" }
  if (!auth) throw new Error("Sign in to invite vendors.")
  if (!input.vendorUserId) throw new Error("Missing vendor user id.")

  try {
    await wpFetchJson("/crm/invites", {
      method: "POST",
      auth,
      body: {
        vendor_user_id: Number(input.vendorUserId),
        event_id: input.eventId ? Number(input.eventId) : undefined,
        opportunity_id: input.opportunityId ? Number(input.opportunityId) : undefined,
        message: input.message,
      },
    })
  } catch (err) {
    if (err instanceof WpFetchError && (err.code === "invite_exists" || err.status === 409)) {
      return { status: "already_invited", message: err.message || "This vendor already has an open invite." }
    }
    throw err
  }
  invalidateCache("organizer.invites:")
  return { status: "sent" }
}

export async function fetchOrganizerVendorProfile(
  profileId: string,
  options?: { auth?: WpAuth; applicationId?: string },
): Promise<OrganizerVendorProfilePeek | null> {
  if (!profileId) return null
  const auth = options?.auth
  const applicationId = options?.applicationId

  if (!isLiveApiEnabled()) {
    const found = MOCK_ORGANIZER_VENDOR_DIRECTORY.find((vendor) => vendor.id === profileId)
    if (!found) return null
    return {
      ...found,
      wordpressUrl: crmVendorUrl(found.id),
      bio: "Marketplace vendor available for event opportunities. Specializes in polished booth experiences.",
      products: found.category,
      email: "vendor@example.com",
      phone: "(555) 010-2000",
      website: "https://example.com",
      gallery: [],
      documents: [
        { type: "Insurance", status: "approved" },
        { type: "Health permit", status: "pending" },
      ],
      setupNeeds: {
        setupType: "10x10 tent",
        powerNeeds: "20A",
        waterNeeds: "None",
        tentSize: "10x10",
        spaceNeeds: "One booth",
      },
      pastEvents: [
        { eventTitle: "Summer Market Demo", status: "completed", date: "2025-06-12" },
      ],
      upcomingEvents: applicationId
        ? [{ eventTitle: "Upcoming booking", status: "approved", date: "2026-08-01" }]
        : undefined,
      reputation: {
        tier: "trusted",
        tierLabel: "Trusted vendor",
        score: 78,
        rating: found.rating,
        reviewCount: found.reviewCount,
        completedEvents: found.eventsCompleted,
      },
      profileStrengthScore: 82,
      profileStrengthTier: "Strong",
      readinessBlockers: applicationId ? ["Payment complete", "Packet viewed"] : undefined,
      applicationMessage: applicationId ? "Excited to join your event lineup." : undefined,
    }
  }

  if (!auth) return null

  const data = await wpFetchJson<Record<string, unknown>>(`/crm/vendors/${profileId}`, { auth })

  let intel: Record<string, unknown> | undefined
  if (applicationId) {
    try {
      intel = await wpFetchJson<Record<string, unknown>>(
        `/crm/applications/${applicationId}/intel`,
        { auth },
      )
    } catch {
      intel = undefined
    }
  }

  return mapVendorProfilePeek(data && typeof data === "object" ? data : {}, intel)
}
