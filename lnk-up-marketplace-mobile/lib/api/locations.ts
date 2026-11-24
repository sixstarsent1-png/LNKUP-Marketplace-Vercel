import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-client"

/** One validated place from the WordPress Location Service (lnkup/v1/locations/*). */
export type LocationSuggestion = {
  city: string
  state_code: string
  display: string
  label?: string
}

export type LocationStatus = "" | "checking" | "verified" | "normalized" | "suggested" | "ambiguous" | "unresolved"

export type LocationResolveResult = {
  status: Exclude<LocationStatus, "" | "checking">
  city: string
  state_code: string
  message?: string
  suggestions?: LocationSuggestion[]
}

const OK: LocationStatus[] = ["verified", "normalized"]

/** Safe to save: empty (optional field), verified, or a formatting-only normalization. */
export function isLocationSubmittable(status: LocationStatus): boolean {
  return status === "" || OK.includes(status)
}

/** City/state autocomplete. Reference data only, so no auth is sent. */
export async function suggestLocations(query: string, limit = 8): Promise<LocationSuggestion[]> {
  const q = query.trim().slice(0, 80)
  if (q.length < 2 || !isLiveApiEnabled()) return []
  const data = await wpFetchJson<{ results?: LocationSuggestion[] }>("/locations/suggest", {
    query: { q, limit },
    timeoutMs: 8000,
  })
  return Array.isArray(data?.results) ? data.results : []
}

/** Validate one pair; the server decides (the app keeps no state list of its own). */
export async function resolveLocation(city: string, state: string): Promise<LocationResolveResult | null> {
  if (!isLiveApiEnabled()) return null
  return wpFetchJson<LocationResolveResult>("/locations/resolve", {
    query: { city: city.slice(0, 120), state: state.slice(0, 60) },
    timeoutMs: 8000,
  })
}
