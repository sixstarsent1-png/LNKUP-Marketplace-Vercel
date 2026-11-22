import { isLiveApiEnabled } from "@/lib/api/config"
import { withFixtureFallback } from "@/lib/api/fixture-honesty"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import type { WpOpportunityItem, WpSearchResponse } from "@/lib/api/types"
import { MOCK_OPPORTUNITIES } from "@/lib/data/fixtures"
import { formatShortDate } from "@/lib/format/date"

export type MarketplaceSort =
  | "recommended"
  | "newest"
  | "closing_soon"
  | "fee_low"
  | "closest"

export type MarketplaceOpportunity = {
  id: string
  opportunityId?: number
  title: string
  eventDate?: string
  /** Raw Y-m-d event day, for "already happened" checks. */
  eventDateIso?: string
  /** Raw Y-m-d application deadline. */
  deadlineIso?: string
  city?: string
  state?: string
  venue?: string
  categories: string[]
  vendorFee: number
  expectedAttendance: number
  applicationDeadline?: string
  spotsRemaining: number
  spotsTotal: number
  organizerName?: string
  organizerVerified: boolean
  trending: boolean
  excerpt?: string
  description?: string
  indoor?: boolean
  outdoor?: boolean
  powerAvailable?: boolean
  loadInTime?: string
  /** Vendor's application status when logged in (WP marketplace search). */
  applicationStatus?: string
  /** Server says the vendor may apply again (withdrawn, or declined and re-opened). */
  canApplyAgain?: boolean
  /** Event cover (or the theme's category image when the organizer has not uploaded one). */
  imageUrl?: string
  gallery?: Array<{
    id: number
    url: string
    type: "image" | "video"
    thumb?: string
  }>
}

export type SearchParams = {
  query?: string
  city?: string
  state?: string
  category?: string
  feeMin?: number
  feeMax?: number
  verifiedOrganizerOnly?: boolean
  lat?: number
  lng?: number
  radiusMiles?: number
  sort?: MarketplaceSort | string
  page?: number
}

export type SearchResult = {
  items: MarketplaceOpportunity[]
  total: number
  page: number
  pages: number
  empty: boolean
}

export type ActiveFilterChip = {
  key: string
  label: string
}

const SORT_TO_WP: Record<string, string> = {
  recommended: "recommended",
  newest: "newest",
  closing_soon: "closing_soon",
  fee_low: "fee_low",
  closest: "closest",
}

const formatDate = formatShortDate

function isoDay(value?: string): string | undefined {
  if (!value) return undefined
  const match = value.trim().match(/^(\d{4}-\d{2}-\d{2})/)
  return match ? match[1] : undefined
}

function formatDeadline(deadline?: string, label?: string): string | undefined {
  if (label) return label
  if (!deadline) return undefined
  return formatDate(deadline)
}

export function mapWpToOpportunity(item: WpOpportunityItem): MarketplaceOpportunity | null {
  if (item.item_type === "external_event") return null
  const opportunityId = item.opportunity_id ?? item.id
  if (!opportunityId) return null

  const categories = item.categories ?? item.categories_needed ?? []
  const spotsRemaining = item.spots_remaining ?? 0
  const totalSpots = item.total_spots ?? spotsRemaining

  return {
    id: `opp-${opportunityId}`,
    opportunityId,
    title: item.name ?? item.event_name ?? "Event opportunity",
    eventDate: formatDate(item.date ?? item.event_date),
    eventDateIso: isoDay(item.date ?? item.event_date),
    deadlineIso: isoDay(item.deadline),
    city: item.city ?? "",
    state: item.state ?? "",
    venue: item.venue_name ?? "",
    categories,
    vendorFee: item.vendor_fee ?? 0,
    expectedAttendance: item.attendance ?? 0,
    applicationDeadline: formatDeadline(item.deadline, item.deadline_label),
    spotsRemaining,
    spotsTotal: totalSpots || spotsRemaining,
    organizerName: item.organizer_name ?? "",
    organizerVerified: Boolean(item.organizer_verified),
    trending: (item.trending_score ?? 0) > 50,
    excerpt: item.excerpt?.trim() || undefined,
    description: item.description?.trim() || item.excerpt?.trim() || undefined,
    indoor: item.indoor,
    outdoor: item.outdoor,
    powerAvailable: item.power_available,
    loadInTime: item.load_in_time || undefined,
    applicationStatus: item.application_status?.trim() || undefined,
    canApplyAgain: item.can_apply_again === true,
    imageUrl: item.featured_image?.trim() || undefined,
    gallery: Array.isArray(item.gallery)
      ? item.gallery
          .map((g) => {
            const url = String(g.url ?? "")
            if (!url) return null
            return {
              id: Number(g.id ?? 0),
              url,
              type: (g.type === "video" ? "video" : "image") as "image" | "video",
              thumb: g.thumb ? String(g.thumb) : undefined,
            }
          })
          .filter((g): g is NonNullable<typeof g> => Boolean(g))
      : undefined,
  }
}

export function mapWpOpportunityList(items: WpOpportunityItem[] | undefined): MarketplaceOpportunity[] {
  if (!items?.length) return []
  return items.map(mapWpToOpportunity).filter((o): o is MarketplaceOpportunity => o !== null)
}

export function filtersToWpQuery(params: SearchParams): Record<string, string | number | boolean | undefined> {
  const q: Record<string, string | number | boolean | undefined> = {
    per_page: 20,
    page: params.page ?? 1,
  }
  if (params.query?.trim()) q.q = params.query.trim()
  if (params.city?.trim()) q.city = params.city.trim()
  if (params.state?.trim()) q.state = params.state.trim()
  if (params.category) q.categories = params.category
  if (params.feeMin !== undefined) q.fee_min = params.feeMin
  if (params.feeMax !== undefined) q.fee_max = params.feeMax
  if (params.verifiedOrganizerOnly) q.verified_organizer = true
  if (
    params.radiusMiles &&
    params.lat !== undefined &&
    params.lng !== undefined
  ) {
    q.radius = params.radiusMiles
    q.lat = params.lat
    q.lng = params.lng
    q.sort = "closest"
  } else if (params.sort) {
    q.sort = SORT_TO_WP[params.sort] ?? params.sort
  } else {
    q.sort = "recommended"
  }
  return q
}

export function hasActiveFilters(params: SearchParams): boolean {
  return Boolean(
    params.query?.trim() ||
      params.city?.trim() ||
      params.state?.trim() ||
      params.category ||
      params.feeMin !== undefined ||
      params.feeMax !== undefined ||
      params.verifiedOrganizerOnly ||
      (params.lat !== undefined && params.lng !== undefined) ||
      (params.sort && params.sort !== "recommended"),
  )
}

export function filtersToChips(params: SearchParams): ActiveFilterChip[] {
  const chips: ActiveFilterChip[] = []
  if (params.query?.trim()) chips.push({ key: "query", label: `"${params.query.trim()}"` })
  if (params.city?.trim()) chips.push({ key: "city", label: params.city.trim() })
  if (params.state?.trim()) chips.push({ key: "state", label: params.state.trim() })
  if (params.category) chips.push({ key: "category", label: params.category })
  if (params.feeMax === 0) chips.push({ key: "fee", label: "Free" })
  else if (params.feeMax !== undefined) chips.push({ key: "fee", label: `Under $${params.feeMax}` })
  if (params.verifiedOrganizerOnly) chips.push({ key: "verified", label: "Verified organizers" })
  if (params.lat !== undefined && params.lng !== undefined) {
    chips.push({ key: "near", label: `Near me · ${params.radiusMiles ?? 50} mi` })
  }
  if (params.sort && params.sort !== "recommended" && params.sort !== "closest") {
    chips.push({ key: "sort", label: `Sort: ${params.sort.replace(/_/g, " ")}` })
  }
  return chips
}

export function clearFilterKey(params: SearchParams, key: string): SearchParams {
  const next = { ...params, page: 1 }
  if (key === "query") next.query = undefined
  if (key === "city") next.city = undefined
  if (key === "state") next.state = undefined
  if (key === "category") next.category = undefined
  if (key === "fee") {
    next.feeMin = undefined
    next.feeMax = undefined
  }
  if (key === "verified") next.verifiedOrganizerOnly = undefined
  if (key === "near") {
    next.lat = undefined
    next.lng = undefined
    next.radiusMiles = undefined
    if (next.sort === "closest") next.sort = "recommended"
  }
  if (key === "sort") next.sort = "recommended"
  return next
}

export const EMPTY_SEARCH: SearchParams = { sort: "recommended", page: 1 }

function filterMock(params: SearchParams): MarketplaceOpportunity[] {
  let items = [...MOCK_OPPORTUNITIES]
  const q = (params.query ?? "").trim().toLowerCase()
  if (q) {
    items = items.filter(
      (item) =>
        item.title.toLowerCase().includes(q) ||
        (item.city ?? "").toLowerCase().includes(q) ||
        item.categories.some((c) => c.toLowerCase().includes(q)),
    )
  }
  if (params.city?.trim()) {
    const city = params.city.trim().toLowerCase()
    items = items.filter((item) => (item.city ?? "").toLowerCase().includes(city))
  }
  if (params.state?.trim()) {
    const state = params.state.trim().toLowerCase()
    items = items.filter((item) => (item.state ?? "").toLowerCase() === state)
  }
  if (params.category) {
    const cat = params.category.toLowerCase()
    items = items.filter((item) =>
      item.categories.some((c) => c.toLowerCase().includes(cat.replace(" vendor", "").trim()) || c.toLowerCase() === cat),
    )
  }
  if (params.feeMax !== undefined) {
    items = items.filter((item) => item.vendorFee <= (params.feeMax ?? 0))
  }
  if (params.feeMin !== undefined) {
    items = items.filter((item) => item.vendorFee >= (params.feeMin ?? 0))
  }
  if (params.verifiedOrganizerOnly) {
    items = items.filter((item) => item.organizerVerified)
  }
  if (params.sort === "fee_low") {
    items = [...items].sort((a, b) => a.vendorFee - b.vendorFee)
  } else if (params.sort === "newest") {
    items = [...items].reverse()
  }
  return items
}

export async function searchMarketplace(
  params: SearchParams = {},
  auth?: WpAuth,
  signal?: AbortSignal,
): Promise<SearchResult> {
  return withFixtureFallback(
    "marketplace.search",
    async () => {
      const data = await wpFetchJson<WpSearchResponse>("/marketplace/search", {
        auth,
        query: filtersToWpQuery(params),
        signal,
      })

      const items = mapWpOpportunityList(data.items)
      return {
        items,
        total: data.total ?? items.length,
        page: data.page ?? 1,
        pages: data.pages ?? 1,
        empty: Boolean(data.empty_state) || items.length === 0,
      }
    },
    () => {
      const filtered = filterMock(params)
      return {
        items: filtered,
        total: filtered.length,
        page: 1,
        pages: 1,
        empty: filtered.length === 0,
      }
    },
  )
}

/** Home rail — WordPress `GET /marketplace/sections/recommended` (not search sort). */
export async function fetchRecommendedOpportunities(
  auth?: WpAuth,
  options?: { limit?: number },
): Promise<MarketplaceOpportunity[]> {
  const limit = options?.limit ?? 3

  if (!isLiveApiEnabled()) {
    return MOCK_OPPORTUNITIES.slice(0, limit)
  }

  const data = await wpFetchJson<WpSearchResponse>("/marketplace/sections/recommended", {
    auth,
    query: { limit },
  })

  return mapWpOpportunityList(data.items).slice(0, limit)
}

/** Home rail — matches web BFF: trending section with `closing_soon`. */
export async function fetchClosingSoonOpportunities(
  auth?: WpAuth,
  options?: { limit?: number },
): Promise<MarketplaceOpportunity[]> {
  const limit = options?.limit ?? 3

  if (!isLiveApiEnabled()) {
    const withDeadline = MOCK_OPPORTUNITIES.filter((o) => Boolean(o.applicationDeadline))
    const source = withDeadline.length > 0 ? withDeadline : MOCK_OPPORTUNITIES
    return source.slice(0, limit)
  }

  const data = await wpFetchJson<WpSearchResponse>("/marketplace/sections/trending", {
    auth,
    query: { section: "closing_soon", limit },
  })

  return mapWpOpportunityList(data.items).slice(0, limit)
}

/** Detail via `GET /marketplace/compare?ids=` (includes excerpt + full description). */
export async function fetchOpportunityDetail(
  opportunityId: number,
  auth?: WpAuth,
): Promise<MarketplaceOpportunity | null> {
  if (!opportunityId) return null

  if (!isLiveApiEnabled()) {
    return (
      MOCK_OPPORTUNITIES.find((item) => item.opportunityId === opportunityId) ?? {
        ...MOCK_OPPORTUNITIES[0],
        opportunityId,
        id: `opp-${opportunityId}`,
      }
    )
  }

  const data = await wpFetchJson<WpSearchResponse>("/marketplace/compare", {
    auth,
    query: { ids: opportunityId },
  })

  const items = mapWpOpportunityList(data.items)
  return items.find((item) => item.opportunityId === opportunityId) ?? items[0] ?? null
}

export function searchParamsToQueryRecord(params: SearchParams): Record<string, string> {
  const out: Record<string, string> = {}
  if (params.query) out.q = params.query
  if (params.city) out.city = params.city
  if (params.state) out.state = params.state
  if (params.category) out.category = params.category
  if (params.feeMin !== undefined) out.feeMin = String(params.feeMin)
  if (params.feeMax !== undefined) out.feeMax = String(params.feeMax)
  if (params.verifiedOrganizerOnly) out.verified = "1"
  if (params.lat !== undefined) out.lat = String(params.lat)
  if (params.lng !== undefined) out.lng = String(params.lng)
  if (params.radiusMiles !== undefined) out.radius = String(params.radiusMiles)
  if (params.sort) out.sort = params.sort
  return out
}

export function queryRecordToSearchParams(query: Record<string, string | string[] | undefined>): SearchParams {
  const get = (key: string): string | undefined => {
    const v = query[key]
    if (Array.isArray(v)) return v[0]
    return v
  }
  const num = (key: string): number | undefined => {
    const raw = get(key)
    if (raw == null || raw === "") return undefined
    const n = Number(raw)
    return Number.isFinite(n) ? n : undefined
  }

  return {
    query: get("q"),
    city: get("city"),
    state: get("state"),
    category: get("category"),
    feeMin: num("feeMin"),
    feeMax: num("feeMax"),
    verifiedOrganizerOnly: get("verified") === "1" || get("verified") === "true",
    lat: num("lat"),
    lng: num("lng"),
    radiusMiles: num("radius"),
    sort: (get("sort") as MarketplaceSort | undefined) ?? "recommended",
    page: 1,
  }
}
