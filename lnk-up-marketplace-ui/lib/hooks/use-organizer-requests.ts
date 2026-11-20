"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerVendorRequest, OrganizerVendorRequestStatus } from "@/lib/api/organizer-requests"
import { buildOrganizerQueueBucketsFromRequests, type OrganizerQueueBuckets } from "@/lib/api/organizer-crm"
import { mockOrganizerRequests } from "@/lib/api/organizer-mock"

export function filterOrganizerRequestsByStatus(
  items: OrganizerVendorRequest[],
  status: OrganizerVendorRequestStatus | "all" | "under_review" | "approved" | "paid",
): OrganizerVendorRequest[] {
  switch (status) {
    case "all":
      return items
    case "under_review":
      return items.filter((r) => r.status === "submitted" || r.status === "under_review")
    case "approved":
      return items.filter((r) => r.status === "approved" || r.status === "payment_required")
    case "paid":
      return items.filter((r) => r.status === "paid" || r.status === "completed")
    default:
      return items.filter((r) => r.status === status)
  }
}

export function useOrganizerRequests(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<OrganizerVendorRequest[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [forbidden, setForbidden] = useState(false)
  const [reviewUnavailable, setReviewUnavailable] = useState(false)
  const [queueBuckets, setQueueBuckets] = useState<OrganizerQueueBuckets>(
    buildOrganizerQueueBucketsFromRequests([]),
  )

  const fetchRequests = useCallback(async () => {
    if (!live) {
      setItems(mockOrganizerRequests)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      setForbidden(false)
      setReviewUnavailable(false)
      setQueueBuckets(buildOrganizerQueueBucketsFromRequests(mockOrganizerRequests))
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)
    setForbidden(false)

    try {
      const response = await fetch("/api/organizer/requests")
      const { data, error: apiError } = await parseApiResponse<{
        items?: OrganizerVendorRequest[]
        reviewUnavailable?: boolean
        queueBuckets?: OrganizerQueueBuckets
      }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setItems([])
          return
        }
        if (response.status === 403) {
          setForbidden(true)
          setItems([])
          return
        }
        throw new Error(apiError.message)
      }

      const nextItems = (data?.items ?? []) as OrganizerVendorRequest[]
      setItems(nextItems)
      setReviewUnavailable(Boolean(data?.reviewUnavailable))
      setQueueBuckets(data?.queueBuckets ?? buildOrganizerQueueBucketsFromRequests(nextItems))
    } catch (err) {
      console.error("[useOrganizerRequests]", err)
      setError(err instanceof Error ? err.message : "We couldn't load vendor requests.")
      setItems([])
      setQueueBuckets(buildOrganizerQueueBucketsFromRequests([]))
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
    forbidden,
    reviewUnavailable,
    queueBuckets,
    retry: fetchRequests,
    isLive: live,
  }
}
