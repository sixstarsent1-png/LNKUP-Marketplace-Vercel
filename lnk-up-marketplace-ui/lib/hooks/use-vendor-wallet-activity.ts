"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorActivity,
  type VendorFinancialActivityItem,
} from "@/lib/api/vendor-financial"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorWalletActivity(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorFinancialActivityItem[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchActivity = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockVendorActivity)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/vendor/wallet/activity")
      const { data, error: apiError } = await parseApiResponse<{ items: VendorFinancialActivityItem[] }>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useVendorWalletActivity]", err)
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
