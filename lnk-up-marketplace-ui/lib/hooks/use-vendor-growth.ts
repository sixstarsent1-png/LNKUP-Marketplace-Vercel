"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  mockVendorGrowth,
  type VendorGrowthSnapshot,
} from "@/lib/api/vendor-growth"

export function useVendorGrowth(enabled = true) {
  const live = isLiveApiEnabled()
  const [growth, setGrowth] = useState<VendorGrowthSnapshot | null>(live ? null : mockVendorGrowth())
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchGrowth = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setGrowth(mockVendorGrowth())
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/growth")
      const { data, error: apiError } = await parseApiResponse<VendorGrowthSnapshot>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setGrowth(null)
          return
        }
        throw new Error(apiError.message)
      }

      setGrowth(data ?? null)
    } catch (err) {
      console.error("[useVendorGrowth]", err)
      setError(err instanceof Error ? err.message : "We couldn't load growth metrics.")
      setGrowth(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchGrowth()
  }, [fetchGrowth])

  return {
    growth,
    loading,
    error,
    unauthenticated,
    retry: fetchGrowth,
    isLive: live,
  }
}
