"use client"

import { useCallback, useEffect, useState } from "react"
import type { EventItem } from "@/lib/data"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"
import {
  type MarketplaceRail,
  type MarketplaceRailId,
  RAIL_LABELS,
} from "@/lib/api/marketplace-sections"
import { buildMockRails } from "@/lib/api/marketplace-sections-mock"
import { useAuth } from "@/lib/hooks/use-auth"
import { useSavedSearches } from "@/lib/hooks/use-saved-searches"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

const HOME_RAILS: MarketplaceRailId[] = [
  "recommended",
  "near_you",
  "closing_soon",
  "trending",
  "saved_matches",
  "recently_added",
  "most_saved",
]

const FIND_RAILS: MarketplaceRailId[] = [
  "trending",
  "closing_soon",
  "recently_added",
  "most_saved",
]

async function fetchSection(path: string, limit = 6): Promise<EventItem[]> {
  const response = await fetch(`${path}?limit=${limit}`)
  const { data, error } = await parseApiResponse<{ items?: EventItem[]; unavailable?: boolean }>(
    response,
  )
  if (error || data?.unavailable) return []
  return data?.items ?? []
}

async function fetchTrendingSection(section: string, limit = 6): Promise<EventItem[]> {
  const response = await fetch(
    `/api/marketplace/trending?section=${encodeURIComponent(section)}&limit=${limit}`,
  )
  const { data, error } = await parseApiResponse<{ items?: EventItem[] }>(response)
  if (error) return []
  return data?.items ?? []
}

async function fetchRecommended(limit = 6): Promise<EventItem[]> {
  const response = await fetch(`/api/marketplace/recommended?limit=${limit}`)
  const { data, error } = await parseApiResponse<{ items?: EventItem[] }>(response)
  if (error) return []
  return data?.items ?? []
}

export function useMarketplaceRails(
  surface: "home" | "find" = "home",
  enabled = true,
  filters?: MarketplaceSearchFilters,
) {
  const live = isLiveApiEnabled()
  const { isAuthenticated } = useAuth()
  const { searches } = useSavedSearches(enabled && live && isAuthenticated)
  const [rails, setRails] = useState<MarketplaceRail[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const railIds = surface === "home" ? HOME_RAILS : FIND_RAILS
  const hasGeo = filters?.lat !== undefined && filters?.lng !== undefined

  const fetchRails = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setRails(buildMockRails(railIds))
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const built: MarketplaceRail[] = []

      if (isAuthenticated && surface === "home") {
        const recommended = await fetchRecommended(6)
        if (recommended.length > 0) {
          built.push({ id: "recommended", ...RAIL_LABELS.recommended, items: recommended })
        }
      }

      if (hasGeo && surface === "home") {
        const params = new URLSearchParams({
          lat: String(filters!.lat),
          lng: String(filters!.lng),
          radius: String(filters!.radiusMiles ?? 50),
          per_page: "6",
        })
        const response = await fetch(`/api/marketplace/search?${params}`)
        const { data } = await parseApiResponse<{ items?: EventItem[] }>(response)
        const items = data?.items ?? []
        if (items.length > 0) {
          built.push({ id: "near_you", ...RAIL_LABELS.near_you, items })
        }
      }

      const closingSoon = await fetchSection("/api/marketplace/sections/closing-soon", 6)
      if (closingSoon.length > 0) {
        built.push({ id: "closing_soon", ...RAIL_LABELS.closing_soon, items: closingSoon })
      }

      const trending = await fetchTrendingSection("trending", 6)
      if (trending.length > 0) {
        built.push({ id: "trending", ...RAIL_LABELS.trending, items: trending })
      }

      const recentlyAdded = await fetchSection("/api/marketplace/sections/new", 6)
      if (recentlyAdded.length > 0) {
        built.push({ id: "recently_added", ...RAIL_LABELS.recently_added, items: recentlyAdded })
      }

      const mostSaved = await fetchSection("/api/marketplace/sections/most-saved", 6)
      if (mostSaved.length > 0) {
        built.push({ id: "most_saved", ...RAIL_LABELS.most_saved, items: mostSaved })
      }

      if (isAuthenticated && searches.length > 0 && surface === "home") {
        const items = recentlyAdded.slice(0, 4)
        if (items.length > 0) {
          built.push({ id: "saved_matches", ...RAIL_LABELS.saved_matches, items })
        }
      }

      setRails(built)
    } catch (err) {
      console.error("[useMarketplaceRails]", err)
      setError(err instanceof Error ? err.message : "Could not load recommendations")
      setRails([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live, isAuthenticated, searches.length, surface, hasGeo, filters?.lat, filters?.lng, filters?.radiusMiles, railIds])

  useEffect(() => {
    fetchRails()
  }, [fetchRails])

  return { rails, loading, error, retry: fetchRails, isLive: live }
}

export function trackRecommendationClick(railId: string, opportunityId?: number) {
  trackLnkupEvent({
    event: "recommendation_clicked",
    source: "discovery_rail",
    opportunityId,
    metadata: { rail: railId },
  })
}
