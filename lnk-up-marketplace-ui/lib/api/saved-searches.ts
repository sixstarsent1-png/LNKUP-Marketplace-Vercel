import type { WpSavedSearchItem } from "@/lib/api/types"
import { filtersToSearchParams } from "@/lib/api/marketplace-discovery"
import type { MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"

export type SavedSearchAlertFrequency = "none" | "instant" | "daily" | "weekly"

export type SavedSearchFilters = {
  q?: string
  city?: string
  state?: string
  category?: string
  categories?: string[]
  fee_min?: number
  fee_max?: number
  date_from?: string
  date_to?: string
}

export type SavedSearchAlertSettings = {
  id: string
  name: string
  alertFrequency: SavedSearchAlertFrequency
  paused?: boolean
  lastRunAt?: string
  lastMatchCount?: number
  newMatches?: number
  nextDigestAt?: string
}

export type SavedSearch = {
  id: string
  name: string
  query?: string
  city?: string
  state?: string
  category?: string
  dateFrom?: string
  dateTo?: string
  feeMin?: number
  feeMax?: number
  alertFrequency?: SavedSearchAlertFrequency | string
  createdAt?: string
  lastRunAt?: string
  lastMatchCount?: number
  newMatches?: number
  isPaused?: boolean
  nextDigestAt?: string
  filterSummary?: string
  raw?: unknown
}

export function savedSearchToFilters(search: SavedSearch): MarketplaceSearchFilters {
  return {
    query: search.query,
    city: search.city,
    state: search.state,
    category: search.category,
    feeMin: search.feeMin,
    feeMax: search.feeMax,
    dateFrom: search.dateFrom,
    dateTo: search.dateTo,
  }
}

export function savedSearchToFindUrl(search: SavedSearch): string {
  const params = filtersToSearchParams(savedSearchToFilters(search))
  const qs = params.toString()
  return qs ? `/vendor/find?${qs}` : "/vendor/find"
}

export function mapSavedSearch(item: WpSavedSearchItem): SavedSearch {
  const filters = (item.filters ?? {}) as Record<string, unknown>
  const categories = filters.categories as string[] | undefined
  const summary = item.filter_summary
    ?.map((f) => f.label)
    .filter(Boolean)
    .join(" · ")

  const freq = item.frequency as SavedSearchAlertFrequency | undefined
  const alertFrequency =
    item.alert_enabled === false ? "none" : freq ?? "weekly"

  return {
    id: item.id || "",
    name: item.name || "Saved search",
    query: typeof filters.q === "string" ? filters.q : undefined,
    city: typeof filters.city === "string" ? filters.city : undefined,
    state: typeof filters.state === "string" ? filters.state : undefined,
    category: categories?.[0] || (typeof filters.category === "string" ? filters.category : undefined),
    dateFrom: typeof filters.date_from === "string" ? filters.date_from : undefined,
    dateTo: typeof filters.date_to === "string" ? filters.date_to : undefined,
    feeMin: typeof filters.fee_min === "number" ? filters.fee_min : undefined,
    feeMax: typeof filters.fee_max === "number" ? filters.fee_max : undefined,
    alertFrequency,
    createdAt: item.created_at,
    lastRunAt: item.last_checked_at,
    lastMatchCount: item.new_match_count,
    newMatches: item.new_match_count,
    isPaused: item.is_paused ?? item.status === "paused",
    nextDigestAt: item.next_digest_at,
    filterSummary: summary,
    raw: item,
  }
}

export function toWpFilters(filters: SavedSearchFilters): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  if (filters.q) out.q = filters.q
  if (filters.city) out.city = filters.city
  if (filters.state) out.state = filters.state
  if (filters.category) out.categories = [filters.category]
  if (filters.categories?.length) out.categories = filters.categories
  if (filters.fee_min !== undefined) out.fee_min = filters.fee_min
  if (filters.fee_max !== undefined) out.fee_max = filters.fee_max
  if (filters.date_from) out.date_from = filters.date_from
  if (filters.date_to) out.date_to = filters.date_to
  return out
}

export const mockSavedSearches: SavedSearch[] = [
  {
    id: "search-1",
    name: "Charlotte food events",
    query: "night market",
    city: "Charlotte",
    state: "NC",
    category: "Food Vendor",
    alertFrequency: "weekly",
    lastMatchCount: 3,
    newMatches: 1,
    createdAt: new Date().toISOString(),
    filterSummary: "Charlotte · Food Vendor",
  },
]
