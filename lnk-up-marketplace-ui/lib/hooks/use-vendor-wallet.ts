"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorWallet,
  type VendorWalletSummary,
} from "@/lib/api/vendor-financial"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorWallet(enabled = true) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<VendorWalletSummary | null>(null)
  const [paymentRequired, setPaymentRequired] = useState<VendorRequest[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchWallet = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      const mock = mockVendorWallet()
      setSummary(mock.summary)
      setPaymentRequired(mock.paymentRequired)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/wallet")
      const { data, error: apiError } = await parseApiResponse<{
        summary: VendorWalletSummary
        paymentRequired: VendorRequest[]
      }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setSummary(null)
          setPaymentRequired([])
          return
        }
        throw new Error(apiError.message)
      }

      setSummary(data?.summary ?? null)
      setPaymentRequired(data?.paymentRequired ?? [])
    } catch (err) {
      console.error("[useVendorWallet]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your wallet.")
      setSummary(null)
      setPaymentRequired([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchWallet()
  }, [fetchWallet])

  return {
    summary,
    paymentRequired,
    loading,
    error,
    unauthenticated,
    retry: fetchWallet,
    isLive: live,
  }
}
