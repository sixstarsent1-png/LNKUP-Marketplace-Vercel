"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockReviewEligibility, type VendorReviewEligibility } from "@/lib/api/vendor-reputation"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorReviewEligibility(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorReviewEligibility[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchEligibility = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockReviewEligibility)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/vendor/reviews/eligible")
      const { data, error: apiError } = await parseApiResponse<{
        items?: VendorReviewEligibility[]
      }>(response)

      if (apiError) throw new Error(apiError.message)
      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useVendorReviewEligibility]", err)
      setError(err instanceof Error ? err.message : "We couldn't load review status.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchEligibility()
  }, [fetchEligibility])

  return { items, loading, error, retry: fetchEligibility, isLive: live }
}
