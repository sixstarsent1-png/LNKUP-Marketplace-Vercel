"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockSupportHistory,
  type VendorSupportRequest,
  type VendorSupportSubmitResult,
  type VendorSupportTopic,
} from "@/lib/api/vendor-support"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorSupport(requestId?: string) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorSupportRequest[]>([])
  const [loading, setLoading] = useState(Boolean(requestId) && live)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const fetchSupport = useCallback(async () => {
    if (!requestId) return

    if (!live) {
      setItems(mockSupportHistory(requestId))
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch(`/api/vendor/operations/${requestId}/support`)
      const { data, error: apiError } = await parseApiResponse<{ items?: VendorSupportRequest[] }>(
        response,
      )

      if (apiError) {
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useVendorSupport]", err)
      setError(err instanceof Error ? err.message : "We couldn't load support history.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [requestId, live])

  const submitSupport = useCallback(
    async (input: { topic: VendorSupportTopic; message: string }) => {
      if (!requestId) return null

      setSubmitting(true)
      try {
        const response = await fetch(`/api/vendor/operations/${requestId}/support`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(input),
        })

        const { data, error: apiError } = await parseApiResponse<VendorSupportSubmitResult>(response)

        if (apiError) {
          throw new Error(apiError.message)
        }

        if (data?.status === "submitted") {
          setItems((prev) => [data.item, ...prev])
        }

        return data ?? null
      } catch (err) {
        console.error("[useVendorSupport] submit", err)
        throw err
      } finally {
        setSubmitting(false)
      }
    },
    [requestId],
  )

  useEffect(() => {
    if (!requestId) {
      setItems([])
      setLoading(false)
      return
    }
    fetchSupport()
  }, [requestId, fetchSupport])

  return {
    items,
    loading,
    error,
    submitting,
    retry: fetchSupport,
    submitSupport,
    isLive: live,
  }
}
