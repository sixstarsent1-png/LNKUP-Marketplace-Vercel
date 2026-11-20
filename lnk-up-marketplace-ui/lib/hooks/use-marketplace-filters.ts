"use client"

import { useCallback, useMemo } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import {
  type MarketplaceSearchFilters,
  EMPTY_FILTERS,
  filtersToSearchParams,
  searchParamsToFilters,
  hasActiveFilters,
  clearFilterKey,
} from "@/lib/api/marketplace-discovery"

export function useMarketplaceFilters() {
  const router = useRouter()
  const searchParams = useSearchParams()

  const filters = useMemo(
    () => ({ ...EMPTY_FILTERS, ...searchParamsToFilters(searchParams) }),
    [searchParams],
  )

  const setFilters = useCallback(
    (next: MarketplaceSearchFilters | ((prev: MarketplaceSearchFilters) => MarketplaceSearchFilters)) => {
      const resolved = typeof next === "function" ? next(filters) : next
      const params = filtersToSearchParams(resolved)
      const qs = params.toString()
      router.replace(qs ? `/vendor/find?${qs}` : "/vendor/find", { scroll: false })
    },
    [filters, router],
  )

  const updateFilter = useCallback(
    (patch: Partial<MarketplaceSearchFilters>) => {
      setFilters({ ...filters, ...patch, page: 1 })
    },
    [filters, setFilters],
  )

  const clearFilters = useCallback(() => {
    setFilters({ ...EMPTY_FILTERS })
  }, [setFilters])

  const removeChip = useCallback(
    (key: string) => {
      setFilters(clearFilterKey(filters, key))
    },
    [filters, setFilters],
  )

  const setView = useCallback(
    (view: "list" | "map") => {
      updateFilter({ view })
    },
    [updateFilter],
  )

  return {
    filters,
    setFilters,
    updateFilter,
    clearFilters,
    removeChip,
    setView,
    hasFilters: hasActiveFilters(filters),
  }
}
