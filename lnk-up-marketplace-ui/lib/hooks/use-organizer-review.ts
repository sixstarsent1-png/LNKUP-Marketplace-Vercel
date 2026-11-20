"use client"

import { useCallback, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { OrganizerReviewAction } from "@/lib/api/organizer-requests"
import { normalizeOrganizerRequestStatus } from "@/lib/api/organizer-requests"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

export function useOrganizerReview(applicationId: string) {
  const live = isLiveApiEnabled()
  const [loadingAction, setLoadingAction] = useState<OrganizerReviewAction["action"] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [unavailable, setUnavailable] = useState(false)

  const submitReview = useCallback(
    async (action: OrganizerReviewAction["action"], reason?: string) => {
      setLoadingAction(action)
      setError(null)
      setUnavailable(false)
      trackLnkupEvent({
        event: "organizer_review_action_attempted",
        source: "organizer_vendor_request",
        requestId: applicationId,
        metadata: { action },
      })

      if (!live) {
        await new Promise((r) => setTimeout(r, 400))
        setLoadingAction(null)
        return {
          success: true,
          status: normalizeOrganizerRequestStatus(
            action === "approve" ? "approved" : action === "waitlist" ? "waitlisted" : "rejected",
          ),
        }
      }

      try {
        const response = await fetch(`/api/organizer/requests/${applicationId}/review`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action, reason }),
        })
        const data = await response.json()

        if (response.status === 503 || data.code === "unavailable") {
          setUnavailable(true)
          throw new Error("Review actions are available in the LNKup web dashboard.")
        }
        if (!response.ok) {
          throw new Error(data.message || "We couldn't update this request.")
        }
        trackLnkupEvent({
          event: "organizer_review_action_succeeded",
          source: "organizer_vendor_request",
          requestId: applicationId,
          metadata: { action },
        })

        return data as { success: boolean; status?: string }
      } catch (err) {
        const message = err instanceof Error ? err.message : "We couldn't update this request."
        setError(message)
        trackLnkupEvent({
          event: "organizer_review_action_failed",
          source: "organizer_vendor_request",
          requestId: applicationId,
          metadata: { action },
        })
        throw err
      } finally {
        setLoadingAction(null)
      }
    },
    [applicationId, live],
  )

  return { submitReview, loadingAction, error, unavailable, isLive: live }
}
