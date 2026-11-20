"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import {
  buildPaymentHandoff,
  mockPaymentHandoffReady,
  mockPaymentSummary,
  type VendorPaymentHandoff,
  type VendorPaymentSummary,
} from "@/lib/api/vendor-payment-handoff"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"

function findMockRequest(applicationId: string): VendorRequest | null {
  const item = mockVendorRequests.find((r) => r.id === applicationId)
  if (!item) return null
  return {
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
  }
}

export function useVendorPaymentHandoff(applicationId?: string) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<VendorPaymentSummary | null>(null)
  const [handoff, setHandoff] = useState<VendorPaymentHandoff | null>(null)
  const [loading, setLoading] = useState(false)
  const [linkLoading, setLinkLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchSummary = useCallback(async () => {
    if (!applicationId) return

    if (!live) {
      const mockRequest = findMockRequest(applicationId)
      if (!mockRequest) {
        setError("We couldn't find that vendor request.")
        return
      }
      setSummary(mockPaymentSummary)
      setHandoff(buildPaymentHandoff(mockRequest, mockPaymentSummary, { status: "ready", checkout_url: mockPaymentHandoffReady.checkoutUrl }))
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch(`/api/vendor/requests/${applicationId}/payment`)
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setSummary(null)
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't load payment details.")
      }

      setSummary(data.summary ?? null)
    } catch (err) {
      console.error("[useVendorPaymentHandoff]", err)
      setError(err instanceof Error ? err.message : "We couldn't load payment details.")
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [applicationId, live])

  const requestPaymentLink = useCallback(async (): Promise<VendorPaymentHandoff | null> => {
    if (!applicationId) return null

    if (!live) {
      const mockRequest = findMockRequest(applicationId)
      if (!mockRequest) return null
      const result = buildPaymentHandoff(mockRequest, mockPaymentSummary, {
        status: "ready",
        checkout_url: mockPaymentHandoffReady.checkoutUrl,
      })
      setHandoff(result)
      return result
    }

    setLinkLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch(`/api/vendor/requests/${applicationId}/payment-link`, {
        method: "POST",
      })
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        return null
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't start payment.")
      }

      const result = data as VendorPaymentHandoff
      setHandoff(result)
      if (result.summary) setSummary(result.summary)
      return result
    } catch (err) {
      console.error("[useVendorPaymentHandoff]", err)
      setError(err instanceof Error ? err.message : "We couldn't start payment.")
      return null
    } finally {
      setLinkLoading(false)
    }
  }, [applicationId, live])

  useEffect(() => {
    if (!applicationId) {
      setSummary(null)
      setHandoff(null)
      setError(null)
      return
    }
    fetchSummary()
  }, [applicationId, fetchSummary])

  return {
    summary,
    handoff,
    loading,
    linkLoading,
    error,
    unauthenticated,
    fetchSummary,
    requestPaymentLink,
    isLive: live,
  }
}
