"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"
import type { VendorRequest as LiveVendorRequest } from "@/lib/api/vendor-requests"

export { filterBooked, filterPaymentRequired } from "@/lib/api/vendor-requests"

function mapMockRequests(): LiveVendorRequest[] {
  return mockVendorRequests.map((item) => ({
    id: item.id,
    eventId: item.eventId,
    eventName: item.eventName,
    eventImage: item.eventImage,
    eventDate: item.date,
    city: item.city,
    organizerName: item.organizer,
    category: item.category,
    status: mockStatusToNormalized(item.status),
    submittedAt: item.submittedAt,
    vendorFee: item.vendorFee,
    amountDue: item.vendorFee,
    currency: "USD",
    paymentId: item.paymentId,
    paymentDueAt: item.paymentDueAt,
    reason: item.reason,
  }))
}

export function useVendorRequests(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorRequest[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchRequests = useCallback(async () => {
    if (!live) {
      setItems(mapMockRequests())
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/requests")
      const { data, error: apiError } = await parseApiResponse<{ items?: VendorRequest[] }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setItems([])
          return
        }
        throw new Error(apiError.message)
      }

      setItems((data?.items ?? []) as VendorRequest[])
    } catch (err) {
      console.error("[useVendorRequests]", err)
      setError(err instanceof Error ? err.message : "Could not load vendor requests")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchRequests()
  }, [enabled, fetchRequests])

  return {
    items,
    loading,
    error,
    unauthenticated,
    retry: fetchRequests,
    isLive: live,
  }
}
