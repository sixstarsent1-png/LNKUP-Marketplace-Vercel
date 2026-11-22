"use client"

import { useState } from "react"
import Image from "next/image"
import Link from "next/link"
import { Calendar, MapPin, Info } from "lucide-react"
import {
  type VendorRequest,
  vendorRequestStatusLabel,
  vendorRequestStatusVariant,
} from "@/lib/api/vendor-requests"
import { canWithdrawStatus, withdrawApplication } from "@/lib/api/apply-client"
import { getPublicWpSiteUrl } from "@/lib/api/config"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/marketplace/badges"

export function VendorRequestCard({
  request,
  onContinuePayment,
  onWithdrawn,
}: {
  request: VendorRequest
  onContinuePayment?: (request: VendorRequest) => void
  onWithdrawn?: (request: VendorRequest) => void
}) {
  const location = request.city ?? [request.city, request.state].filter(Boolean).join(", ")
  const fee = request.amountDue ?? request.vendorFee
  const showPaymentDue =
    request.paymentDueAt &&
    (request.status === "payment_required" || request.status === "approved")

  return (
    <Card className="flex-row items-center gap-3 rounded-2xl p-3" data-testid="vendor-request-card">
      <div className="relative size-16 shrink-0 overflow-hidden rounded-xl">
        <Image
          src={request.eventImage || "/placeholder.svg"}
          alt={request.eventName}
          fill
          className="object-cover"
        />
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <div className="flex items-start justify-between gap-2">
          <h3 className="truncate text-sm font-semibold">{request.eventName}</h3>
          <StatusBadge variant={vendorRequestStatusVariant(request.status)}>
            {vendorRequestStatusLabel(request.status)}
          </StatusBadge>
        </div>

        <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
          {request.eventDate && (
            <span className="inline-flex items-center gap-1">
              <Calendar className="size-3" />
              {request.eventDate}
            </span>
          )}
          {location && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="size-3" />
              {location}
            </span>
          )}
        </div>

        {request.organizerName && (
          <p className="text-xs text-muted-foreground">Organizer · {request.organizerName}</p>
        )}

        {request.category && (
          <p className="text-xs text-muted-foreground">{request.category}</p>
        )}

        {request.reason && (
          <p className="inline-flex items-start gap-1 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3 shrink-0" />
            {request.reason}
          </p>
        )}

        {showPaymentDue && (
          <p className="text-xs text-muted-foreground">Payment due · {request.paymentDueAt}</p>
        )}

        {(request.status === "payment_required" || request.status === "approved") && (
          <p className="text-xs text-muted-foreground">
            Your event packet unlocks after payment.
          </p>
        )}

        {(request.status === "paid" || request.status === "completed") && (
          <p className="text-xs text-muted-foreground">Your packet/pass is ready.</p>
        )}

        {request.submittedAt && (
          <p className="text-xs text-muted-foreground">Submitted · {request.submittedAt}</p>
        )}

        <div className="mt-1 flex items-center justify-between gap-2">
          {typeof fee === "number" && fee > 0 && (
            <span className="text-sm font-heading font-bold">${fee}</span>
          )}
          <VendorRequestCta request={request} onContinuePayment={onContinuePayment} />
        </div>
        <WithdrawRequestButton request={request} onWithdrawn={onWithdrawn} />
      </div>
    </Card>
  )
}

const PAYMENT_IN_PROGRESS = ["processing", "paid", "partially_paid"]

/** Withdraw an unpaid application (server re-checks status and payment). */
function WithdrawRequestButton({
  request,
  onWithdrawn,
}: {
  request: VendorRequest
  onWithdrawn?: (request: VendorRequest) => void
}) {
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  if (!onWithdrawn || !canWithdrawStatus(request.status, PAYMENT_IN_PROGRESS.includes(request.paymentStatus ?? ""))) {
    return null
  }

  async function handleWithdraw() {
    setBusy(true)
    setError(null)
    const result = await withdrawApplication(request.id)
    setBusy(false)
    if (!result.ok) {
      setError(result.message)
      return
    }
    setConfirming(false)
    onWithdrawn?.(request)
  }

  if (!confirming) {
    return (
      <Button
        size="sm"
        variant="ghost"
        className="self-start px-0 text-xs text-muted-foreground"
        onClick={() => setConfirming(true)}
        data-testid="vendor-request-withdraw"
      >
        Withdraw application
      </Button>
    )
  }
  return (
    <div className="flex flex-col gap-2 rounded-xl bg-muted p-2 text-xs" role="group" aria-label="Confirm withdrawal">
      <p>Withdraw this application? The organizer won&apos;t review it, and you can apply again later.</p>
      {error && (
        <p className="text-destructive" role="alert">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <Button size="sm" variant="outline" onClick={() => setConfirming(false)} disabled={busy}>
          Keep it
        </Button>
        <Button size="sm" variant="destructive" onClick={() => void handleWithdraw()} disabled={busy} data-testid="vendor-request-withdraw-confirm">
          {busy ? "Withdrawing…" : "Withdraw"}
        </Button>
      </div>
    </div>
  )
}

function VendorRequestCta({
  request,
  onContinuePayment,
}: {
  request: VendorRequest
  onContinuePayment?: (request: VendorRequest) => void
}) {
  const wpSite = getPublicWpSiteUrl()

  switch (request.status) {
    case "payment_required":
    case "approved":
      return (
        <div className="flex flex-col items-end gap-1">
          <Button
            size="sm"
            variant="outline"
            onClick={() => onContinuePayment?.(request)}
            data-testid="vendor-request-continue-payment"
          >
            Continue payment
          </Button>
          <span className="text-[10px] text-muted-foreground">Pass unlocks after payment</span>
        </div>
      )
    case "paid":
    case "completed":
      return (
        <Button
          size="sm"
          variant="outline"
          nativeButton={false}
          render={<Link href={`/vendor/pass/${request.id}`} data-testid="vendor-request-view-pass" />}
        >
          View event pass
        </Button>
      )
    case "rejected":
      return (
        <Button size="sm" variant="ghost" nativeButton={false} render={<Link href="/vendor/find" />}>
          Find more events
        </Button>
      )
    case "submitted":
    case "under_review":
    case "waitlisted":
      return (
        <div className="flex flex-col items-end gap-1">
          <Button
            size="sm"
            variant="outline"
            nativeButton={false}
            render={<Link href="/vendor/requests" />}
            data-testid="vendor-request-view-status"
          >
            View status
          </Button>
          <span className="text-[10px] text-muted-foreground">Pass unlocks after approval & payment</span>
        </div>
      )
    case "cancelled":
      return request.opportunityId ? (
        <Button
          size="sm"
          variant="outline"
          nativeButton={false}
          render={<Link href={`/vendor/find?opportunityId=${request.opportunityId}&apply=1`} data-testid="vendor-request-apply-again" />}
        >
          Apply again
        </Button>
      ) : (
        <Button size="sm" variant="ghost" nativeButton={false} render={<Link href="/vendor/find" />}>
          Find more events
        </Button>
      )
    default:
      return wpSite ? (
        <Button size="sm" variant="outline" nativeButton={false} render={<Link href={wpSite} target="_blank" />}>
          Continue in LNKup
        </Button>
      ) : (
        <Button
          size="sm"
          variant="outline"
          nativeButton={false}
          render={<Link href="/vendor/requests" />}
        >
          View status
        </Button>
      )
  }
}
