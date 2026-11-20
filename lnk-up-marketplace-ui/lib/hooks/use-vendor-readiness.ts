"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockVendorReadiness, type VendorReadiness } from "@/lib/api/vendor-readiness"

export function useVendorReadiness(enabled = true) {
  const live = isLiveApiEnabled()
  const [data, setData] = useState<VendorReadiness | null>(live ? null : mockVendorReadiness)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchReadiness = useCallback(async () => {
    if (!live) {
      setData(mockVendorReadiness)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/readiness")
      const payload = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setData(null)
        return
      }

      if (!response.ok) {
        throw new Error(payload.message || "We couldn't load your readiness details.")
      }

      setData(payload as VendorReadiness)
    } catch (err) {
      console.error("[useVendorReadiness]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your readiness details.")
      setData(null)
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchReadiness()
  }, [enabled, fetchReadiness])

  return {
    readiness: data,
    loading,
    error,
    unauthenticated,
    retry: fetchReadiness,
    isLive: live,
  }
}
