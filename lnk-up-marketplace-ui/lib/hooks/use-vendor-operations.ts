"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorOperationsEvents,
  type VendorOperationsEvent,
} from "@/lib/api/vendor-operations"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorOperations(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorOperationsEvent[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchOperations = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems(mockVendorOperationsEvents())
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/operations")
      const { data, error: apiError } = await parseApiResponse<{ items?: VendorOperationsEvent[] }>(
        response,
      )

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setItems([])
          return
        }
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useVendorOperations]", err)
      setError(err instanceof Error ? err.message : "We couldn't load event operations.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchOperations()
  }, [fetchOperations])

  return {
    items,
    loading,
    error,
    unauthenticated,
    retry: fetchOperations,
    isLive: live,
  }
}
