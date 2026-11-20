"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorOperationsEvents,
  type VendorOperationsEvent,
} from "@/lib/api/vendor-operations"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorOperationsDetail(requestId?: string) {
  const live = isLiveApiEnabled()
  const [event, setEvent] = useState<VendorOperationsEvent | null>(null)
  const [loading, setLoading] = useState(Boolean(requestId) && live)
  const [error, setError] = useState<string | null>(null)

  const fetchDetail = useCallback(async () => {
    if (!requestId) return

    if (!live) {
      const mock = mockVendorOperationsEvents().find((e) => e.requestId === requestId) ?? null
      setEvent(mock)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch(`/api/vendor/operations/${requestId}`)
      const { data, error: apiError } = await parseApiResponse<VendorOperationsEvent>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setEvent(data ?? null)
    } catch (err) {
      console.error("[useVendorOperationsDetail]", err)
      setError(err instanceof Error ? err.message : "We couldn't load event operations.")
      setEvent(null)
    } finally {
      setLoading(false)
    }
  }, [requestId, live])

  useEffect(() => {
    if (!requestId) {
      setEvent(null)
      setLoading(false)
      return
    }
    fetchDetail()
  }, [requestId, fetchDetail])

  return { event, loading, error, retry: fetchDetail, isLive: live }
}
