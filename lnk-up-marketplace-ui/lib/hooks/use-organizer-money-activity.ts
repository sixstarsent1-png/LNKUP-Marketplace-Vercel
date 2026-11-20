"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockOrganizerActivity,
  type OrganizerFinancialActivityItem,
} from "@/lib/api/organizer-financial"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useOrganizerMoneyActivity(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<OrganizerFinancialActivityItem[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchActivity = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockOrganizerActivity)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/organizer/money/activity")
      const { data, error: apiError } = await parseApiResponse<{ items: OrganizerFinancialActivityItem[] }>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useOrganizerMoneyActivity]", err)
      setError(err instanceof Error ? err.message : "We couldn't load activity.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchActivity()
  }, [fetchActivity])

  return { items, loading, error, retry: fetchActivity, isLive: live }
}
