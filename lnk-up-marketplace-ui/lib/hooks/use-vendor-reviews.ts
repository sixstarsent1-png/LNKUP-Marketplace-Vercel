"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockOrganizerReviews, type OrganizerReview } from "@/lib/api/vendor-reputation"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorReviews(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<OrganizerReview[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchReviews = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockOrganizerReviews)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/vendor/reviews")
      const { data, error: apiError } = await parseApiResponse<{ items?: OrganizerReview[] }>(
        response,
      )

      if (apiError) throw new Error(apiError.message)
      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useVendorReviews]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your reviews.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchReviews()
  }, [fetchReviews])

  return { items, loading, error, retry: fetchReviews, isLive: live }
}
