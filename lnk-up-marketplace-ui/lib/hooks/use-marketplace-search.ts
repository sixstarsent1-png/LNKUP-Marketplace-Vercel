"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import type { EventItem } from "@/lib/data"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  type MarketplaceSearchFilters,
  filtersToSearchParams,
  hasActiveFilters,
  opportunityToEventItem,
} from "@/lib/api/marketplace-discovery"
import { mockSearchResults } from "@/lib/api/marketplace-discovery-mock"
import { events } from "@/lib/data"

export type MarketplaceSearchState = {
  items: EventItem[]
  total: number
  loading: boolean
  error: string | null
  emptyState: boolean
}

function filterMockEvents(filters: MarketplaceSearchFilters): EventItem[] {
  const { items } = mockSearchResults({
    query: filters.query,
    city: filters.city,
    category: filters.category,
  })
  return items.map(opportunityToEventItem)
}

/** @deprecated Use useMarketplaceSearch(filters) */
export function useMarketplaceSearchLegacy(query: string, category: string | null) {
  return useMarketplaceSearch({ query, category: category ?? undefined })
}

export function useMarketplaceSearch(filters: MarketplaceSearchFilters) {
  const live = isLiveApiEnabled()
  const [state, setState] = useState<MarketplaceSearchState>({
    items: [],
    total: 0,
    loading: live,
    error: null,
    emptyState: false,
  })
  const abortRef = useRef<AbortController | null>(null)

  const fetchResults = useCallback(async () => {
    if (!live) {
      const items = hasActiveFilters(filters)
        ? filterMockEvents(filters)
        : events.filter((e) => e.status !== "closed")
      setState({
        items,
        total: items.length,
        loading: false,
        error: null,
        emptyState: items.length === 0,
      })
      return
    }

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setState((prev) => ({ ...prev, loading: true, error: null }))

    const params = filtersToSearchParams(filters)

    try {
      const response = await fetch(`/api/marketplace/search?${params.toString()}`, {
        signal: controller.signal,
      })
      const { data, error: apiError } = await parseApiResponse<{
        items?: EventItem[]
        total?: number
        empty_state?: boolean
      }>(response)
      if (apiError) {
        throw new Error(apiError.message)
      }
      setState({
        items: data?.items ?? [],
        total: data?.total ?? 0,
        loading: false,
        error: null,
        emptyState: data?.empty_state ?? (data?.items?.length === 0),
      })
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") {
        return
      }
      setState((prev) => ({
        ...prev,
        loading: false,
        error: error instanceof Error ? error.message : "Search failed",
        emptyState: false,
      }))
    }
  }, [live, filters])

  useEffect(() => {
    const timer = setTimeout(() => {
      void fetchResults()
    }, 300)
    return () => clearTimeout(timer)
  }, [fetchResults])

  return { ...state, live, retry: fetchResults }
}
