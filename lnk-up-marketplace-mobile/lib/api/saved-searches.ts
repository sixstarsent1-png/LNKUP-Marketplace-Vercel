import { isLiveApiEnabled } from "@/lib/api/config"
import type { SearchParams } from "@/lib/api/marketplace"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import type { WpSavedSearchItem, WpSavedSearchesResponse } from "@/lib/api/types"

export type SavedSearch = {
  id: string
  name: string
  query?: string
  city?: string
  state?: string
  category?: string
  feeMin?: number
  feeMax?: number
  alertFrequency?: string
  createdAt?: string
  lastRunAt?: string
  lastMatchCount?: number
  newMatches?: number
  isPaused?: boolean
  filterSummary?: string
}

const MOCK_SAVED_SEARCHES: SavedSearch[] = [
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
    createdAt: "2026-07-01T12:00:00.000Z",
    filterSummary: "Charlotte · Food Vendor",
  },
  {
    id: "search-2",
    name: "Atlanta under $200",
    city: "Atlanta",
    state: "GA",
    feeMax: 200,
    alertFrequency: "daily",
    lastMatchCount: 2,
    newMatches: 0,
    createdAt: "2026-07-10T12:00:00.000Z",
    filterSummary: "Atlanta · Under $200",
  },
]

let mockStore: SavedSearch[] = MOCK_SAVED_SEARCHES.map((s) => ({ ...s }))

export function mapSavedSearch(item: WpSavedSearchItem): SavedSearch {
  const filters = (item.filters ?? {}) as Record<string, unknown>
  const categories = filters.categories as string[] | undefined
  const summary = item.filter_summary
    ?.map((f) => f.label)
    .filter(Boolean)
    .join(" · ")

  return {
    id: item.id || "",
    name: item.name || "Saved search",
    query: typeof filters.q === "string" ? filters.q : undefined,
    city: typeof filters.city === "string" ? filters.city : undefined,
    state: typeof filters.state === "string" ? filters.state : undefined,
    category:
      categories?.[0] ||
      (typeof filters.category === "string" ? filters.category : undefined),
    feeMin: typeof filters.fee_min === "number" ? filters.fee_min : undefined,
    feeMax: typeof filters.fee_max === "number" ? filters.fee_max : undefined,
    alertFrequency:
      item.alert_enabled === false ? "none" : (item.frequency ?? "weekly"),
    createdAt: item.created_at,
    lastRunAt: item.last_checked_at,
    lastMatchCount: item.new_match_count,
    newMatches: item.new_match_count,
    isPaused: item.is_paused ?? item.status === "paused",
    filterSummary: summary,
  }
}

export function savedSearchToSearchParams(search: SavedSearch): SearchParams {
  return {
    query: search.query,
    city: search.city,
    state: search.state,
    category: search.category,
    feeMin: search.feeMin,
    feeMax: search.feeMax,
    sort: "recommended",
    page: 1,
  }
}

export function searchParamsToWpFilters(params: SearchParams): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  if (params.query?.trim()) out.q = params.query.trim()
  if (params.city?.trim()) out.city = params.city.trim()
  if (params.state?.trim()) out.state = params.state.trim()
  if (params.category) out.categories = [params.category]
  if (params.feeMin !== undefined) out.fee_min = params.feeMin
  if (params.feeMax !== undefined) out.fee_max = params.feeMax
  return out
}

function defaultName(params: SearchParams): string {
  const parts = [params.city, params.category, params.query].filter(Boolean)
  return parts.length > 0 ? parts.join(" · ") : "Saved search"
}

export async function listSavedSearches(auth?: WpAuth): Promise<SavedSearch[]> {
  if (!isLiveApiEnabled()) {
    return mockStore.map((s) => ({ ...s }))
  }
  const data = await wpFetchJson<WpSavedSearchesResponse>("/marketplace/saved-searches", {
    auth,
  })
  return (data.searches ?? []).map(mapSavedSearch)
}

export async function createSavedSearch(
  params: SearchParams,
  options: { name?: string; auth?: WpAuth } = {},
): Promise<SavedSearch> {
  const name = options.name?.trim() || defaultName(params)
  const filters = searchParamsToWpFilters(params)

  if (!isLiveApiEnabled()) {
    const created: SavedSearch = {
      id: `search-${Date.now()}`,
      name,
      query: params.query,
      city: params.city,
      state: params.state,
      category: params.category,
      feeMin: params.feeMin,
      feeMax: params.feeMax,
      alertFrequency: "weekly",
      createdAt: new Date().toISOString(),
      filterSummary: [params.city, params.category, params.query].filter(Boolean).join(" · "),
      lastMatchCount: 0,
      newMatches: 0,
    }
    mockStore = [created, ...mockStore]
    return { ...created }
  }

  const created = await wpFetchJson<WpSavedSearchItem>("/marketplace/saved-searches", {
    method: "POST",
    auth: options.auth,
    body: {
      name,
      filters,
      frequency: "weekly",
      alert_enabled: true,
    },
  })
  return mapSavedSearch(created)
}

export async function deleteSavedSearch(id: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled()) {
    mockStore = mockStore.filter((s) => s.id !== id)
    return
  }
  await wpFetchJson(`/marketplace/saved-searches/${encodeURIComponent(id)}`, {
    method: "DELETE",
    auth,
  })
}

export async function runSavedSearch(id: string, auth?: WpAuth): Promise<SavedSearch> {
  if (!isLiveApiEnabled()) {
    const found = mockStore.find((s) => s.id === id)
    if (!found) throw new Error("Saved search not found")
    return { ...found }
  }
  const result = await wpFetchJson<WpSavedSearchItem>(
    `/marketplace/saved-searches/${encodeURIComponent(id)}/run`,
    { method: "POST", auth },
  )
  return mapSavedSearch(result)
}
