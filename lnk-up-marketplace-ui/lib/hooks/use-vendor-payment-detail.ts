"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled, buildVendorPaymentReturnPath } from "@/lib/api/config"
import type { VendorPaymentDetail } from "@/lib/api/vendor-financial"
import {
  buildPaymentHandoff,
  mockPaymentSummary,
  type VendorPaymentHandoff,
  type VendorPaymentSummary,
} from "@/lib/api/vendor-payment-handoff"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import { parseApiResponse } from "@/lib/api/parse-api-error"

function findMockRequest(applicationId: string) {
  const item = mockVendorRequests.find((r) => r.id === applicationId)
  if (!item) return null
  return {
    id: item.id,
    eventName: item.eventName,
    eventDate: item.date,
    status: mockStatusToNormalized(item.status),
    vendorFee: item.vendorFee,
    amountDue: item.vendorFee,
    currency: "USD",
    paymentId: item.paymentId,
    paymentDueAt: item.paymentDueAt,
  }
}

export function useVendorPaymentDetail(requestId?: string) {
  const live = isLiveApiEnabled()
  const [detail, setDetail] = useState<VendorPaymentDetail | null>(null)
  const [summary, setSummary] = useState<VendorPaymentSummary | null>(null)
  const [loading, setLoading] = useState(false)
  const [linkLoading, setLinkLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchDetail = useCallback(async () => {
    if (!requestId) return

    if (!live) {
      const mockRequest = findMockRequest(requestId)
      if (!mockRequest) {
        setError("We couldn't find that vendor request.")
        return
      }
      setSummary(mockPaymentSummary)
      setDetail({
        requestId: mockRequest.id,
        paymentId: mockRequest.paymentId,
        eventName: mockRequest.eventName,
        status: mockRequest.status === "paid" ? "paid" : "payment_required",
        vendorFee: mockPaymentSummary.vendorFee,
        lnkupFee: mockPaymentSummary.lnkupFee,
        total: mockPaymentSummary.total,
        currency: "USD",
        dueAt: mockRequest.paymentDueAt,
        receiptUrl: mockRequest.status === "paid" ? "/vendor/wallet" : undefined,
        timeline: [
          { id: "approved", label: "Approved", status: "complete" },
          {
            id: "payment",
            label: mockRequest.status === "paid" ? "Paid" : "Payment required",
            status: mockRequest.status === "paid" ? "complete" : "current",
          },
        ],
      })
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch(`/api/vendor/requests/${requestId}/payment`)
      const { data, error: apiError } = await parseApiResponse<{
        summary?: VendorPaymentSummary | null
        detail?: VendorPaymentDetail | null
      }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setDetail(null)
          setSummary(null)
          return
        }
        throw new Error(apiError.message)
      }

      setSummary(data?.summary ?? null)
      setDetail(data?.detail ?? null)
    } catch (err) {
      console.error("[useVendorPaymentDetail]", err)
      setError(err instanceof Error ? err.message : "We couldn't load payment details.")
      setDetail(null)
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [requestId, live])

  const requestPaymentLink = useCallback(async (): Promise<VendorPaymentHandoff | null> => {
    if (!requestId) return null

    if (!live) {
      const mockRequest = findMockRequest(requestId)
      if (!mockRequest) return null
      const returnPath = buildVendorPaymentReturnPath(requestId, "success")
      const checkoutUrl =
        typeof window !== "undefined" ? `${window.location.origin}${returnPath}` : returnPath
      return buildPaymentHandoff(mockRequest, mockPaymentSummary, {
        status: "ready",
        checkout_url: checkoutUrl,
      })
    }

    setLinkLoading(true)
    setError(null)

    try {
      const response = await fetch(`/api/vendor/requests/${requestId}/payment-link`, { method: "POST" })
      const { data, error: apiError } = await parseApiResponse<VendorPaymentHandoff>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          return null
        }
        throw new Error(apiError.message)
      }

      return data ?? null
    } catch (err) {
      console.error("[useVendorPaymentDetail]", err)
      setError(err instanceof Error ? err.message : "We couldn't start payment.")
      return null
    } finally {
      setLinkLoading(false)
    }
  }, [requestId, live])

  useEffect(() => {
    if (!requestId) {
      setDetail(null)
      setSummary(null)
      return
    }
    fetchDetail()
  }, [requestId, fetchDetail])

  return {
    detail,
    summary,
    loading,
    linkLoading,
    error,
    unauthenticated,
    retry: fetchDetail,
    requestPaymentLink,
    isLive: live,
  }
}
