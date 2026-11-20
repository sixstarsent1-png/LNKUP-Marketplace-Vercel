"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerEventLite } from "@/lib/api/organizer-events"
import { mockOrganizerEvents } from "@/lib/api/organizer-mock"

export function useOrganizerEvents(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<OrganizerEventLite[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [forbidden, setForbidden] = useState(false)

  const fetchEvents = useCallback(async () => {
    if (!live) {
      setItems(mockOrganizerEvents)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      setForbidden(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)
    setForbidden(false)

    try {
      const response = await fetch("/api/organizer/events")
      const { data, error: apiError } = await parseApiResponse<{ items?: OrganizerEventLite[] }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setItems([])
          return
        }
        if (response.status === 403) {
          setForbidden(true)
          setItems([])
          return
        }
        throw new Error(apiError.message)
      }

      setItems((data?.items ?? []) as OrganizerEventLite[])
    } catch (err) {
      console.error("[useOrganizerEvents]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your events.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchEvents()
  }, [enabled, fetchEvents])

  return {
    items,
    loading,
    error,
    unauthenticated,
    forbidden,
    retry: fetchEvents,
    isLive: live,
  }
}
