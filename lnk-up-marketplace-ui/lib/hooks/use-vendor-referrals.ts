"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { VendorReferralState } from "@/lib/api/vendor-referrals"
import { mockVendorReferrals } from "@/lib/api/vendor-referrals"

export function useVendorReferrals(enabled = true) {
  const live = isLiveApiEnabled()
  const [state, setState] = useState<VendorReferralState | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchReferrals = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setState(mockVendorReferrals)
      setLoading(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/referrals")
      const { data, error: apiError } = await parseApiResponse<VendorReferralState>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setState(null)
          return
        }
        throw new Error(apiError.message)
      }

      setState(data)
    } catch (err) {
      console.error("[useVendorReferrals]", err)
      setError(err instanceof Error ? err.message : "Could not load referrals.")
      setState(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  const generateInvite = useCallback(async () => {
    if (!live) {
      setState(mockVendorReferrals)
      return mockVendorReferrals
    }

    const response = await fetch("/api/vendor/referrals/invite", { method: "POST" })
    const { data, error: apiError } = await parseApiResponse<VendorReferralState>(response)
    if (apiError) throw new Error(apiError.message)
    if (data) setState(data)
    return data
  }, [live])

  useEffect(() => {
    fetchReferrals()
  }, [fetchReferrals])

  return {
    state,
    loading,
    error,
    unauthenticated,
    retry: fetchReferrals,
    generateInvite,
    isLive: live,
  }
}
