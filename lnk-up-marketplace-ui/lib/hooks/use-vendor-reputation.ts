"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorReputation,
  type VendorCompletedEvent,
  type VendorReputationSummary,
} from "@/lib/api/vendor-reputation"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorReputation(enabled = true) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<VendorReputationSummary | null>(null)
  const [completedEvents, setCompletedEvents] = useState<VendorCompletedEvent[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchReputation = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      const mock = mockVendorReputation()
      setSummary(mock.summary)
      setCompletedEvents(mock.completedEvents)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/reputation")
      const { data, error: apiError } = await parseApiResponse<{
        summary: VendorReputationSummary
        completedEvents: VendorCompletedEvent[]
      }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setSummary(null)
          setCompletedEvents([])
          return
        }
        throw new Error(apiError.message)
      }

      setSummary(data?.summary ?? null)
      setCompletedEvents(data?.completedEvents ?? [])
    } catch (err) {
      console.error("[useVendorReputation]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your reputation.")
      setSummary(null)
      setCompletedEvents([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchReputation()
  }, [fetchReputation])

  return {
    summary,
    completedEvents,
    loading,
    error,
    unauthenticated,
    retry: fetchReputation,
    isLive: live,
  }
}
