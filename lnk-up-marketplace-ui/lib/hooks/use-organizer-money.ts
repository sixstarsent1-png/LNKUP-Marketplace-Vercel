"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { OrganizerMoneySummary } from "@/lib/api/organizer-financial"
import { mockOrganizerFinancial } from "@/lib/api/organizer-mock"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useOrganizerMoney(enabled = true) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<OrganizerMoneySummary | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [forbidden, setForbidden] = useState(false)

  const fetchMoney = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setSummary(mockOrganizerFinancial)
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
      const response = await fetch("/api/organizer/money")
      const { data, error: apiError } = await parseApiResponse<{ summary: OrganizerMoneySummary }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setSummary(null)
          return
        }
        if (response.status === 403) {
          setForbidden(true)
          setSummary(null)
          return
        }
        throw new Error(apiError.message)
      }

      setSummary(data?.summary ?? null)
    } catch (err) {
      console.error("[useOrganizerMoney]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your financial snapshot.")
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchMoney()
  }, [fetchMoney])

  return {
    summary,
    loading,
    error,
    unauthenticated,
    forbidden,
    retry: fetchMoney,
    isLive: live,
  }
}
