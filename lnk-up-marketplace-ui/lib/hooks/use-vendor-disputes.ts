"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockVendorDisputes, type VendorDispute } from "@/lib/api/vendor-financial"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorDisputes(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorDispute[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const fetchDisputes = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockVendorDisputes)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)
    try {
      const res = await fetch("/api/vendor/wallet/disputes")
      const parsed = await parseApiResponse<{ items: VendorDispute[] }>(res)
      if (parsed.error) throw new Error(parsed.error.message)
      setItems(parsed.data?.items ?? [])
    } catch (err) {
      console.error("[useVendorDisputes]", err)
      setError(err instanceof Error ? err.message : "We couldn't load disputes.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchDisputes()
  }, [fetchDisputes])

  const openDispute = useCallback(
    async (paymentId: string, reason: string) => {
      setSubmitting(true)
      try {
        const res = await fetch("/api/vendor/wallet/disputes", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ paymentId, reason }),
        })
        const parsed = await parseApiResponse<{ id?: string; dispute?: VendorDispute }>(res)
        if (parsed.error) throw new Error(parsed.error.message)
        await fetchDisputes()
        return parsed.data?.dispute ?? null
      } finally {
        setSubmitting(false)
      }
    },
    [fetchDisputes],
  )

  return { items, loading, error, submitting, retry: fetchDisputes, openDispute }
}
