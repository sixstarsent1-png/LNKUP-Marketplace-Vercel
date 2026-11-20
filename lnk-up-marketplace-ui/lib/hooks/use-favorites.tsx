"use client"

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { EventItem } from "@/lib/data"
import { getVendorFavoriteEvents } from "@/lib/data"

type FavoritesContextValue = {
  opportunityIds: Set<number>
  items: EventItem[]
  loading: boolean
  error: string | null
  unauthenticated: boolean
  isFavorited: (opportunityId?: number, eventId?: string) => boolean
  toggleFavorite: (opportunityId?: number, eventId?: string) => Promise<void>
  retry: () => Promise<void>
  isLive: boolean
}

const FavoritesContext = createContext<FavoritesContextValue | null>(null)

function mockOpportunityIds(): Set<number> {
  return new Set(
    getVendorFavoriteEvents()
      .map((e) => e.opportunityId)
      .filter((id): id is number => typeof id === "number"),
  )
}

export function FavoritesProvider({ children }: { children: React.ReactNode }) {
  const live = isLiveApiEnabled()
  const mockItems = useMemo(() => getVendorFavoriteEvents(), [])
  const [opportunityIds, setOpportunityIds] = useState<Set<number>>(() => (live ? new Set() : mockOpportunityIds()))
  const [items, setItems] = useState<EventItem[]>(live ? [] : mockItems)
  const [loading, setLoading] = useState(live)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchFavorites = useCallback(async () => {
    if (!live) {
      setOpportunityIds(mockOpportunityIds())
      setItems(mockItems)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/marketplace/favorites")
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setOpportunityIds(new Set())
        setItems([])
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't load saved opportunities.")
      }

      setOpportunityIds(new Set((data.opportunityIds ?? []) as number[]))
      setItems((data.items ?? []) as EventItem[])
    } catch (err) {
      console.error("[useFavorites]", err)
      setError(err instanceof Error ? err.message : "We couldn't load saved opportunities.")
      setOpportunityIds(new Set())
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [live, mockItems])

  useEffect(() => {
    fetchFavorites()
  }, [fetchFavorites])

  const isFavorited = useCallback(
    (opportunityId?: number, eventId?: string) => {
      if (opportunityId) return opportunityIds.has(opportunityId)
      if (!live && eventId) {
        return mockItems.some((e) => e.id === eventId)
      }
      return false
    },
    [live, mockItems, opportunityIds],
  )

  const toggleFavorite = useCallback(
    async (opportunityId?: number, eventId?: string) => {
      if (!live) {
        if (eventId) {
          const exists = items.some((e) => e.id === eventId)
          if (exists) {
            setItems((prev) => prev.filter((e) => e.id !== eventId))
          } else {
            const event = mockItems.find((e) => e.id === eventId)
            if (event) setItems((prev) => [...prev, event])
          }
        }
        return
      }

      if (!opportunityId) return

      const response = await fetch("/api/marketplace/favorites", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ type: "opportunities", id: opportunityId }),
      })
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "Could not update saved opportunity.")
      }

      await fetchFavorites()
    },
    [fetchFavorites, items, live, mockItems],
  )

  const value = useMemo(
    () => ({
      opportunityIds,
      items,
      loading,
      error,
      unauthenticated,
      isFavorited,
      toggleFavorite,
      retry: fetchFavorites,
      isLive: live,
    }),
    [opportunityIds, items, loading, error, unauthenticated, isFavorited, toggleFavorite, fetchFavorites, live],
  )

  return <FavoritesContext.Provider value={value}>{children}</FavoritesContext.Provider>
}

export function useFavorites() {
  const ctx = useContext(FavoritesContext)
  if (!ctx) {
    throw new Error("useFavorites must be used within FavoritesProvider")
  }
  return ctx
}
