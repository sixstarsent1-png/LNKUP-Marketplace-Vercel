import type { EventItem } from "@/lib/data"
import type { WpOpportunityItem } from "@/lib/api/types"
import { mapEventMedia, type EventMedia } from "@/lib/api/event-media"

export type MarketplaceSort =
  | "recommended"
  | "newest"
  | "closing_soon"
  | "event_date"
  | "fee_low"
  | "attendance_high"

export type MarketplaceSearchFilters = {
  query?: string
  city?: string
  state?: string
  radiusMiles?: number
  lat?: number
  lng?: number
  category?: string
  eventType?: string
  dateFrom?: string
  dateTo?: string
  deadlineBefore?: string
  feeMin?: number
  feeMax?: number
  attendanceMin?: number
  attendanceMax?: number
  indoor?: boolean
  outdoor?: boolean
  verifiedOrganizerOnly?: boolean
  documentsRequired?: boolean
  sort?: MarketplaceSort
  page?: number
  view?: "list" | "map"
  opportunityId?: string
  autoApply?: boolean
}

export type MarketplaceOpportunity = {
  id: string
  opportunityId?: number
  eventId?: string
  title: string
  eventDate?: string
  city?: string
  state?: string
  venue?: string
  eventType?: string
  categories?: string[]
  vendorFee?: number | null
  currency?: string
  expectedAttendance?: number
  applicationDeadline?: string
  spotsRemaining?: number
  spotsTotal?: number
  organizerName?: string
  organizerVerified?: boolean
  indoor?: boolean
  outdoor?: boolean
  documentsRequired?: boolean
  imageUrl?: string
  media?: EventMedia
  gallery?: Array<{
    id: number
    url: string
    type: "image" | "video"
    thumb?: string
  }>
  saved?: boolean
  applicationStatus?: string
  trending?: boolean
  href?: string
  raw?: unknown
}

export type MarketplaceMapPin = {
  id: string
  opportunityId: string
  eventId?: string
  title: string
  city?: string
  state?: string
  lat: number
  lng: number
  eventDate?: string
  vendorFee?: number | null
  category?: string
  applicationStatus?: string
  saved?: boolean
}

export type ActiveFilterChip = {
  key: string
  label: string
  value: string
}

const SORT_TO_WP: Record<MarketplaceSort, string> = {
  recommended: "recommended",
  newest: "newest",
  closing_soon: "closing_soon",
  event_date: "soonest",
  fee_low: "fee_low",
  attendance_high: "attendance",
}

function formatDate(value?: string): string {
  if (!value) return "Date TBA"
  const parsed = Date.parse(value)
  if (Number.isNaN(parsed)) return value
  return new Date(parsed).toLocaleDateString("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric",
  })
}

function formatDeadline(value?: string, label?: string): string {
  if (label) return label
  if (!value) return "Rolling"
  const parsed = Date.parse(value)
  if (Number.isNaN(parsed)) return value
  return new Date(parsed).toLocaleDateString("en-US", { month: "short", day: "numeric" })
}

export function mapWpToOpportunity(item: WpOpportunityItem): MarketplaceOpportunity | null {
  if (item.item_type === "external_event") return null
  const opportunityId = item.opportunity_id ?? item.id
  if (!opportunityId) return null

  const categories = item.categories ?? item.categories_needed ?? []
  const eventTypes = (item as { event_types?: string[] }).event_types ?? []
  const spotsRemaining = item.spots_remaining ?? 0
  const totalSpots = item.total_spots ?? spotsRemaining
  const isOpen = item.is_open !== false && spotsRemaining > 0

  return {
    id: `opp-${opportunityId}`,
    opportunityId,
    eventId: String((item as { event_id?: number }).event_id ?? opportunityId),
    title: item.name ?? item.event_name ?? "Event opportunity",
    eventDate: formatDate(item.date ?? item.event_date),
    city: item.city ?? "",
    state: item.state ?? "",
    venue: item.venue_name ?? "",
    eventType: eventTypes[0],
    categories,
    vendorFee: typeof item.vendor_fee === "number" ? item.vendor_fee : null,
    expectedAttendance: item.attendance ?? 0,
    applicationDeadline: formatDeadline(item.deadline, item.deadline_label),
    spotsRemaining,
    spotsTotal: totalSpots || spotsRemaining,
    organizerName: item.organizer_name ?? "",
    organizerVerified: Boolean(item.organizer_verified),
    indoor: Boolean((item as { indoor?: boolean }).indoor),
    outdoor: Boolean((item as { outdoor?: boolean }).outdoor),
    documentsRequired: Boolean((item as { doc_required?: boolean }).doc_required),
    imageUrl: mapEventMedia(item.media, item.featured_image)?.url,
    media: mapEventMedia(item.media, item.featured_image),
    gallery: Array.isArray((item as { gallery?: unknown }).gallery)
      ? ((item as { gallery: Array<Record<string, unknown>> }).gallery
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
          .filter((g): g is NonNullable<typeof g> => Boolean(g)))
      : undefined,
    applicationStatus: (item as { application_status?: string }).application_status,
    trending: (item.trending_score ?? 0) > 50,
    href: `/vendor/find`,
    raw: item,
    saved: false,
  }
}

export function mapWpOpportunityList(items: WpOpportunityItem[] | undefined): MarketplaceOpportunity[] {
  if (!items?.length) return []
  return items.map(mapWpToOpportunity).filter((o): o is MarketplaceOpportunity => o !== null)
}

export function opportunityToEventItem(opp: MarketplaceOpportunity): EventItem {
  const isOpen = (opp.spotsRemaining ?? 0) > 0
  return {
    id: opp.id,
    opportunityId: opp.opportunityId,
    name: opp.title,
    image: opp.imageUrl ?? "",
    imageFit: opp.media?.fit,
    imageBackground: opp.media?.background,
    imageAlt: opp.media?.alt,
    date: opp.eventDate ?? "Date TBA",
    city: opp.city ?? "",
    state: opp.state ?? "",
    venue: opp.venue ?? "",
    organizer: opp.organizerName ?? "",
    organizerVerified: opp.organizerVerified ?? false,
    expectedAttendance: opp.expectedAttendance ?? 0,
    vendorFee: typeof opp.vendorFee === "number" ? opp.vendorFee : null,
    spotsRemaining: opp.spotsRemaining ?? 0,
    totalSpots: opp.spotsTotal ?? opp.spotsRemaining ?? 0,
    categoriesNeeded: opp.categories ?? [],
    deadline: opp.applicationDeadline ?? "Rolling",
    trending: opp.trending,
    readiness: 0,
    vendorRequests: 0,
    approvedVendors: 0,
    paidVendors: 0,
    revenue: 0,
    status: isOpen ? "live" : "closed",
    eventType: opp.eventType,
    documentsRequired: opp.documentsRequired,
    indoor: opp.indoor,
    outdoor: opp.outdoor,
    applicationStatus: opp.applicationStatus,
    gallery: opp.gallery,
  } as EventItem & {
    eventType?: string
    documentsRequired?: boolean
    indoor?: boolean
    outdoor?: boolean
    applicationStatus?: string
    gallery?: EventItem["gallery"]
  }
}

export function opportunityMatchesId(
  event: { id?: string; opportunityId?: number | string },
  opportunityId?: string,
): boolean {
  if (!opportunityId) return false
  return (
    String(event.opportunityId) === opportunityId ||
    event.id === opportunityId ||
    event.id === `opp-${opportunityId}`
  )
}

export function filtersToWpQuery(filters: MarketplaceSearchFilters): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.query?.trim()) q.q = filters.query.trim()
  if (filters.city) q.city = filters.city
  if (filters.state) q.state = filters.state
  if (filters.radiusMiles && filters.lat !== undefined && filters.lng !== undefined) {
    q.radius = String(filters.radiusMiles)
    q.lat = String(filters.lat)
    q.lng = String(filters.lng)
    q.sort = "closest"
  }
  if (filters.category) q.categories = filters.category
  if (filters.eventType) q.event_types = filters.eventType
  if (filters.dateFrom) q.date_from = filters.dateFrom
  if (filters.dateTo) q.date_to = filters.dateTo
  if (filters.deadlineBefore) q.date_to = filters.deadlineBefore
  if (filters.feeMin !== undefined) q.fee_min = String(filters.feeMin)
  if (filters.feeMax !== undefined) q.fee_max = String(filters.feeMax)
  if (filters.attendanceMin !== undefined) q.attendance_min = String(filters.attendanceMin)
  if (filters.attendanceMax !== undefined) q.attendance_max = String(filters.attendanceMax)
  if (filters.indoor) q.indoor = "true"
  if (filters.outdoor) q.outdoor = "true"
  if (filters.verifiedOrganizerOnly) q.verified_organizer = "true"
  if (filters.documentsRequired) q.doc_required = "coi"
  if (filters.sort && !q.sort) q.sort = SORT_TO_WP[filters.sort] ?? filters.sort
  if (filters.page) q.page = String(filters.page)
  q.per_page = "24"
  return q
}

export function filtersToSearchParams(filters: MarketplaceSearchFilters): URLSearchParams {
  const params = new URLSearchParams()
  const set = (key: string, value?: string | number | boolean) => {
    if (value === undefined || value === "" || value === false) return
    params.set(key, String(value))
  }
  set("q", filters.query)
  set("city", filters.city)
  set("state", filters.state)
  set("radius", filters.radiusMiles)
  if (filters.lat !== undefined) set("lat", filters.lat)
  if (filters.lng !== undefined) set("lng", filters.lng)
  set("category", filters.category)
  set("eventType", filters.eventType)
  set("dateFrom", filters.dateFrom)
  set("dateTo", filters.dateTo)
  set("deadlineBefore", filters.deadlineBefore)
  if (filters.feeMin !== undefined) set("feeMin", filters.feeMin)
  if (filters.feeMax !== undefined) set("feeMax", filters.feeMax)
  if (filters.attendanceMin !== undefined) set("attendanceMin", filters.attendanceMin)
  if (filters.attendanceMax !== undefined) set("attendanceMax", filters.attendanceMax)
  if (filters.indoor) set("indoor", "1")
  if (filters.outdoor) set("outdoor", "1")
  if (filters.verifiedOrganizerOnly) set("verified", "1")
  if (filters.documentsRequired) set("docsRequired", "1")
  if (filters.sort) set("sort", filters.sort)
  if (filters.page && filters.page > 1) set("page", filters.page)
  if (filters.view) set("view", filters.view)
  if (filters.opportunityId) set("opportunityId", filters.opportunityId)
  if (filters.autoApply) set("apply", "1")
  return params
}

export function searchParamsToFilters(params: URLSearchParams): MarketplaceSearchFilters {
  const num = (key: string) => {
    const v = params.get(key)
    if (!v) return undefined
    const n = parseFloat(v)
    return Number.isNaN(n) ? undefined : n
  }
  const bool = (key: string) => params.get(key) === "1" || params.get(key) === "true"
  const sort = params.get("sort") as MarketplaceSort | null
  const validSorts: MarketplaceSort[] = [
    "recommended",
    "newest",
    "closing_soon",
    "event_date",
    "fee_low",
    "attendance_high",
  ]

  return {
    query: params.get("q") ?? undefined,
    city: params.get("city") ?? undefined,
    state: params.get("state") ?? undefined,
    radiusMiles: num("radius"),
    lat: num("lat"),
    lng: num("lng"),
    category: params.get("category") ?? undefined,
    eventType: params.get("eventType") ?? undefined,
    dateFrom: params.get("dateFrom") ?? undefined,
    dateTo: params.get("dateTo") ?? undefined,
    deadlineBefore: params.get("deadlineBefore") ?? undefined,
    feeMin: num("feeMin"),
    feeMax: num("feeMax"),
    attendanceMin: num("attendanceMin"),
    attendanceMax: num("attendanceMax"),
    indoor: bool("indoor"),
    outdoor: bool("outdoor"),
    verifiedOrganizerOnly: bool("verified"),
    documentsRequired: bool("docsRequired"),
    sort: sort && validSorts.includes(sort) ? sort : undefined,
    page: num("page") ?? 1,
    view: params.get("view") === "map" ? "map" : "list",
    opportunityId: params.get("opportunityId") ?? undefined,
    autoApply: params.get("apply") === "1",
  }
}

export function hasActiveFilters(filters: MarketplaceSearchFilters): boolean {
  return Boolean(
    filters.query?.trim() ||
      filters.city ||
      filters.state ||
      filters.radiusMiles ||
      filters.lat !== undefined ||
      filters.category ||
      filters.eventType ||
      filters.dateFrom ||
      filters.dateTo ||
      filters.deadlineBefore ||
      filters.feeMin !== undefined ||
      filters.feeMax !== undefined ||
      filters.attendanceMin !== undefined ||
      filters.attendanceMax !== undefined ||
      filters.indoor ||
      filters.outdoor ||
      filters.verifiedOrganizerOnly ||
      filters.documentsRequired,
  )
}

export function filtersToChips(filters: MarketplaceSearchFilters): ActiveFilterChip[] {
  const chips: ActiveFilterChip[] = []
  if (filters.query?.trim()) chips.push({ key: "query", label: filters.query.trim(), value: "query" })
  if (filters.city) chips.push({ key: "city", label: filters.city, value: "city" })
  if (filters.state) chips.push({ key: "state", label: filters.state, value: "state" })
  if (filters.radiusMiles) chips.push({ key: "radius", label: `${filters.radiusMiles} mi`, value: "radius" })
  if (filters.category) chips.push({ key: "category", label: filters.category, value: "category" })
  if (filters.eventType) chips.push({ key: "eventType", label: filters.eventType, value: "eventType" })
  if (filters.feeMin !== undefined || filters.feeMax !== undefined) {
    chips.push({
      key: "fee",
      label: `Fee $${filters.feeMin ?? 0}–${filters.feeMax ?? "∞"}`,
      value: "fee",
    })
  }
  if (filters.verifiedOrganizerOnly) chips.push({ key: "verified", label: "Verified organizer", value: "verified" })
  if (filters.documentsRequired) chips.push({ key: "docs", label: "Docs required", value: "docsRequired" })
  if (filters.indoor) chips.push({ key: "indoor", label: "Indoor", value: "indoor" })
  if (filters.outdoor) chips.push({ key: "outdoor", label: "Outdoor", value: "outdoor" })
  return chips
}

export function clearFilterKey(
  filters: MarketplaceSearchFilters,
  key: string,
): MarketplaceSearchFilters {
  const next = { ...filters }
  switch (key) {
    case "query":
      next.query = undefined
      break
    case "city":
      next.city = undefined
      break
    case "state":
      next.state = undefined
      break
    case "radius":
      next.radiusMiles = undefined
      next.lat = undefined
      next.lng = undefined
      break
    case "category":
      next.category = undefined
      break
    case "eventType":
      next.eventType = undefined
      break
    case "fee":
      next.feeMin = undefined
      next.feeMax = undefined
      break
    case "verified":
      next.verifiedOrganizerOnly = undefined
      break
    case "docsRequired":
      next.documentsRequired = undefined
      break
    case "indoor":
      next.indoor = undefined
      break
    case "outdoor":
      next.outdoor = undefined
      break
    default:
      break
  }
  return next
}

export const EMPTY_FILTERS: MarketplaceSearchFilters = { sort: "recommended", page: 1, view: "list" }

export function feeRangeLabelToFilters(label: string | null): Pick<MarketplaceSearchFilters, "feeMin" | "feeMax"> {
  switch (label) {
    case "Under $150":
      return { feeMax: 150 }
    case "$150–$250":
      return { feeMin: 150, feeMax: 250 }
    case "$250–$400":
      return { feeMin: 250, feeMax: 400 }
    case "$400+":
      return { feeMin: 400 }
    default:
      return {}
  }
}

export function feeFiltersToRangeLabel(filters: MarketplaceSearchFilters): string | null {
  if (filters.feeMax === 150 && !filters.feeMin) return "Under $150"
  if (filters.feeMin === 150 && filters.feeMax === 250) return "$150–$250"
  if (filters.feeMin === 250 && filters.feeMax === 400) return "$250–$400"
  if (filters.feeMin === 400 && !filters.feeMax) return "$400+"
  return null
}

export function attendanceLabelToFilters(label: string | null): Pick<MarketplaceSearchFilters, "attendanceMin" | "attendanceMax"> {
  switch (label) {
    case "Under 3k":
      return { attendanceMax: 3000 }
    case "3k–6k":
      return { attendanceMin: 3000, attendanceMax: 6000 }
    case "6k–10k":
      return { attendanceMin: 6000, attendanceMax: 10000 }
    case "10k+":
      return { attendanceMin: 10000 }
    default:
      return {}
  }
}

export function attendanceFiltersToLabel(filters: MarketplaceSearchFilters): string | null {
  if (filters.attendanceMax === 3000 && !filters.attendanceMin) return "Under 3k"
  if (filters.attendanceMin === 3000 && filters.attendanceMax === 6000) return "3k–6k"
  if (filters.attendanceMin === 6000 && filters.attendanceMax === 10000) return "6k–10k"
  if (filters.attendanceMin === 10000 && !filters.attendanceMax) return "10k+"
  return null
}
