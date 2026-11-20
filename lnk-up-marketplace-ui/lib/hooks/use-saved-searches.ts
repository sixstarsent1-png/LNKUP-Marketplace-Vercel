"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockSavedSearches, type SavedSearch, type SavedSearchFilters, type SavedSearchAlertFrequency } from "@/lib/api/saved-searches"

export function useSavedSearches(enabled = true) {
  const live = isLiveApiEnabled()
  const [searches, setSearches] = useState<SavedSearch[]>(live ? [] : mockSavedSearches)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [saving, setSaving] = useState(false)

  const fetchSearches = useCallback(async () => {
    if (!live) {
      setSearches(mockSavedSearches)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/marketplace/saved-searches")
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setSearches([])
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't load your saved searches.")
      }

      setSearches((data.searches ?? []) as SavedSearch[])
    } catch (err) {
      console.error("[useSavedSearches]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your saved searches.")
      setSearches([])
    } finally {
      setLoading(false)
    }
  }, [live])

  const createSearch = useCallback(
    async (
      name: string,
      filters: SavedSearchFilters,
      frequency: SavedSearchAlertFrequency = "weekly",
    ) => {
      if (!live) {
        const created: SavedSearch = {
          id: `mock-${Date.now()}`,
          name,
          query: filters.q,
          city: filters.city,
          state: filters.state,
          category: filters.category,
          alertFrequency: frequency,
          createdAt: new Date().toISOString(),
        }
        setSearches((prev) => [created, ...prev])
        return created
      }

      setSaving(true)
      try {
        const response = await fetch("/api/marketplace/saved-searches", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            name,
            filters,
            frequency: frequency === "none" ? "weekly" : frequency,
            alert_enabled: frequency !== "none",
          }),
        })
        const data = await response.json()

        if (response.status === 401) {
          setUnauthenticated(true)
          throw new Error("Please sign in to save searches.")
        }

        if (!response.ok) {
          throw new Error(data.message || "Could not save search.")
        }

        await fetchSearches()
        return data as SavedSearch
      } finally {
        setSaving(false)
      }
    },
    [fetchSearches, live],
  )

  const updateSearch = useCallback(
    async (
      id: string,
      patch: { frequency?: SavedSearchAlertFrequency; name?: string; paused?: boolean },
    ) => {
      if (!live) {
        setSearches((prev) =>
          prev.map((s) =>
            s.id === id
              ? {
                  ...s,
                  alertFrequency: patch.frequency ?? s.alertFrequency,
                  name: patch.name ?? s.name,
                  isPaused: patch.paused ?? s.isPaused,
                }
              : s,
          ),
        )
        return
      }

      const response = await fetch(`/api/marketplace/saved-searches/${encodeURIComponent(id)}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          frequency: patch.frequency,
          alert_enabled: patch.frequency !== "none",
          name: patch.name,
          paused: patch.paused,
        }),
      })
      const data = await response.json()
      if (!response.ok) {
        throw new Error(data.message || "Could not update saved search.")
      }
      await fetchSearches()
    },
    [fetchSearches, live],
  )

  const pauseSearch = useCallback(
    async (id: string) => updateSearch(id, { paused: true }),
    [updateSearch],
  )

  const resumeSearch = useCallback(
    async (id: string) => updateSearch(id, { paused: false }),
    [updateSearch],
  )

  const runSearch = useCallback(
    async (id: string) => {
      if (!live) {
        setSearches((prev) =>
          prev.map((s) =>
            s.id === id ? { ...s, lastRunAt: new Date().toISOString(), newMatches: 0 } : s,
          ),
        )
        return
      }

      const response = await fetch(`/api/marketplace/saved-searches/${encodeURIComponent(id)}/run`, {
        method: "POST",
      })
      const data = await response.json()
      if (!response.ok) {
        throw new Error(data.message || "Could not run saved search.")
      }
      await fetchSearches()
      return data
    },
    [fetchSearches, live],
  )

  const deleteSearch = useCallback(
    async (id: string) => {
      if (!live) {
        setSearches((prev) => prev.filter((s) => s.id !== id))
        return
      }

      const response = await fetch(`/api/marketplace/saved-searches/${encodeURIComponent(id)}`, {
        method: "DELETE",
      })
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        throw new Error("Please sign in to manage saved searches.")
      }

      if (!response.ok) {
        throw new Error(data.message || "Could not delete saved search.")
      }

      await fetchSearches()
    },
    [fetchSearches, live],
  )

  useEffect(() => {
    if (!enabled) return
    fetchSearches()
  }, [enabled, fetchSearches])

  return {
    searches,
    loading,
    saving,
    error,
    unauthenticated,
    retry: fetchSearches,
    createSearch,
    updateSearch,
    pauseSearch,
    resumeSearch,
    runSearch,
    deleteSearch,
    isLive: live,
  }
}
