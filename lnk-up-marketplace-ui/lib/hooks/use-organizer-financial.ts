"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { OrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { mockOrganizerFinancial } from "@/lib/api/organizer-mock"

export function useOrganizerFinancial(enabled = true) {
  const live = isLiveApiEnabled()
  const [snapshot, setSnapshot] = useState<OrganizerFinancialSnapshot | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [forbidden, setForbidden] = useState(false)

  const fetchFinancial = useCallback(async () => {
    if (!live) {
      setSnapshot(mockOrganizerFinancial)
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
      const response = await fetch("/api/organizer/financial")
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setSnapshot(null)
        return
      }
      if (response.status === 403) {
        setForbidden(true)
        setSnapshot(null)
        return
      }
      if (!response.ok) {
        throw new Error(data.message || "We couldn't load your financial snapshot.")
      }

      setSnapshot(data as OrganizerFinancialSnapshot)
    } catch (err) {
      console.error("[useOrganizerFinancial]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your financial snapshot.")
      setSnapshot(null)
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchFinancial()
  }, [enabled, fetchFinancial])

  return {
    snapshot,
    loading,
    error,
    unauthenticated,
    forbidden,
    retry: fetchFinancial,
    isLive: live,
  }
}
