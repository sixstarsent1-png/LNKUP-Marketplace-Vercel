"use client"

import { useState } from "react"
import { Calendar, Store, Loader2 } from "lucide-react"
import {
  type OrganizerVendorRequest,
  organizerRequestStatusLabel,
  organizerRequestStatusVariant,
} from "@/lib/api/organizer-requests"
import { useOrganizerReview } from "@/lib/hooks/use-organizer-review"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/marketplace/badges"
import { OrganizerRejectDialog } from "@/components/organizer/organizer-reject-dialog"
import { OrganizerReputationPreviewRow } from "@/components/reputation/organizer-reputation-preview-row"

export function OrganizerVendorRequestCard({
  request,
  reviewDisabled,
  onReviewed,
  onViewProfile,
}: {
  request: OrganizerVendorRequest
  reviewDisabled?: boolean
  onReviewed?: () => void
  onViewProfile?: (request: OrganizerVendorRequest) => void
}) {
  const { submitReview, loadingAction } = useOrganizerReview(request.id)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [localStatus, setLocalStatus] = useState(request.status)
  const canReview =
    !reviewDisabled &&
    (localStatus === "submitted" || localStatus === "under_review")

  async function handleAction(action: "approve" | "waitlist") {
    try {
      await submitReview(action)
      setLocalStatus(action === "approve" ? "approved" : "waitlisted")
      onReviewed?.()
    } catch {
      // error surfaced via hook if needed
    }
  }

  async function handleReject(reason: string) {
    try {
      await submitReview("reject", reason)
      setLocalStatus("rejected")
      setRejectOpen(false)
      onReviewed?.()
    } catch {
      // keep dialog open on failure
    }
  }

  return (
    <>
      <Card className="rounded-2xl p-4" data-testid="organizer-vendor-request-card">
        <div className="flex flex-col gap-3">
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 flex-col gap-0.5">
              <h3 className="truncate text-sm font-semibold">{request.vendorBusinessName}</h3>
              <p className="text-xs text-muted-foreground">{request.vendorCategory ?? "Vendor"}</p>
            </div>
            <StatusBadge variant={organizerRequestStatusVariant(localStatus)}>
              {organizerRequestStatusLabel(localStatus)}
            </StatusBadge>
          </div>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <Store className="size-3" />
              {request.eventName}
            </span>
            {request.submittedAt && (
              <span className="inline-flex items-center gap-1">
                <Calendar className="size-3" />
                Applied {request.submittedAt}
              </span>
            )}
          </div>

          {(localStatus === "approved" || localStatus === "payment_required" || localStatus === "paid" || localStatus === "completed") && (
            <p className="text-xs text-muted-foreground">
              {localStatus === "paid" || localStatus === "completed" ? "Paid" : "Payment required"}
              {request.amountDue ? ` · ${new Intl.NumberFormat("en-US", { style: "currency", currency: request.currency ?? "USD" }).format(request.amountDue)}` : ""}
              {request.submittedAt ? ` · Applied ${request.submittedAt}` : ""}
            </p>
          )}

          {request.documentsStatus && request.documentsStatus !== "ready" && request.documentsStatus !== "unknown" ? (
            <p className="text-xs text-muted-foreground">
              Readiness blocker: documents {request.documentsStatus}
            </p>
          ) : null}

          {request.message && (
            <p className="text-xs text-muted-foreground line-clamp-2">{request.message}</p>
          )}

          <OrganizerReputationPreviewRow preview={request.reputationPreview} />

          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => onViewProfile?.(request)}
              data-testid="organizer-view-profile"
            >
              View profile
            </Button>
            {canReview && (
              <>
                <Button
                  size="sm"
                  onClick={() => handleAction("approve")}
                  disabled={Boolean(loadingAction)}
                  data-testid="organizer-approve"
                >
                  {loadingAction === "approve" ? <Loader2 className="size-4 animate-spin" /> : "Approve"}
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => handleAction("waitlist")}
                  disabled={Boolean(loadingAction)}
                  data-testid="organizer-waitlist"
                >
                  {loadingAction === "waitlist" ? <Loader2 className="size-4 animate-spin" /> : "Waitlist"}
                </Button>
                <Button
                  size="sm"
                  variant="destructive"
                  onClick={() => setRejectOpen(true)}
                  disabled={Boolean(loadingAction)}
                  data-testid="organizer-reject"
                >
                  Not selected
                </Button>
              </>
            )}
          </div>

          {reviewDisabled && canReview && (
            <p className="text-xs text-muted-foreground">
              Review actions are available in the LNKup web dashboard.
            </p>
          )}
        </div>
      </Card>

      <OrganizerRejectDialog
        open={rejectOpen}
        onOpenChange={setRejectOpen}
        onConfirm={handleReject}
        loading={loadingAction === "reject"}
      />
    </>
  )
}
