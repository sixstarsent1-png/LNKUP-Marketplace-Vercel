"use client"

import Link from "next/link"
import { CheckCircle2, Loader2, XCircle } from "lucide-react"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

export function PaymentReturnBanner({
  showSuccess,
  showProcessing,
  showCancelled,
  timedOut = false,
  lastCheckedAt,
  nextRetryAt,
  request,
  onDismiss,
  onRetryPayment,
  onRetryReconcile,
}: {
  showSuccess: boolean
  showProcessing: boolean
  showCancelled: boolean
  timedOut?: boolean
  lastCheckedAt?: number | null
  nextRetryAt?: number | null
  request?: VendorRequest | null
  onDismiss?: () => void
  onRetryPayment?: () => void
  onRetryReconcile?: () => void
}) {
  if (!showSuccess && !showProcessing && !showCancelled) return null

  if (showSuccess) {
    const eventName = request?.eventName ?? "your event"
    return (
      <Alert
        className="border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-50"
        data-testid="payment-return-success"
      >
        <CheckCircle2 className="size-4" />
        <AlertTitle>You&apos;re booked for {eventName}</AlertTitle>
        <AlertDescription className="flex flex-col gap-3">
          <span>Payment received — your event pass is ready.</span>
          <div className="flex flex-wrap gap-2">
            {request?.id && (
              <Button size="sm" nativeButton={false} render={<Link href={`/vendor/pass/${request.id}`} />} onClick={onDismiss}>
                View pass
              </Button>
            )}
            <Button
              size="sm"
              variant="outline"
              nativeButton={false}
              render={<Link href={request?.id ? `/vendor/wallet?applicationId=${request.id}&payment=success` : "/vendor/wallet"} />}
              onClick={onDismiss}
            >
              View receipt
            </Button>
            {onDismiss && (
              <Button size="sm" variant="ghost" onClick={onDismiss}>
                Dismiss
              </Button>
            )}
          </div>
        </AlertDescription>
      </Alert>
    )
  }

  if (showProcessing) {
    return (
      <Alert data-testid="payment-return-processing">
        <Loader2 className="size-4 animate-spin" />
        <AlertTitle>Payment processing</AlertTitle>
        <AlertDescription className="flex flex-col gap-2">
          <span>
            {timedOut
              ? "We're still waiting for payment confirmation. You can retry now."
              : "We're confirming your payment. This usually takes a few seconds."}
          </span>
          {lastCheckedAt ? (
            <span className="text-xs text-muted-foreground">
              Last checked: {new Date(lastCheckedAt).toLocaleTimeString()}
              {nextRetryAt && !timedOut ? ` · Next retry: ${new Date(nextRetryAt).toLocaleTimeString()}` : ""}
            </span>
          ) : null}
          <div className="flex flex-wrap gap-2">
            {onRetryReconcile && (
              <Button size="sm" variant="outline" className="w-fit" onClick={onRetryReconcile}>
                Retry now
              </Button>
            )}
            {onDismiss && (
              <Button size="sm" variant="ghost" className="w-fit" onClick={onDismiss}>
                Dismiss
              </Button>
            )}
          </div>
        </AlertDescription>
      </Alert>
    )
  }

  return (
    <Alert variant="destructive" data-testid="payment-return-cancelled">
      <XCircle className="size-4" />
      <AlertTitle>Payment wasn&apos;t completed</AlertTitle>
      <AlertDescription className="flex flex-col gap-2">
        <span>You can try again anytime from your requests.</span>
        {onRetryPayment && (
          <Button size="sm" variant="outline" className="w-fit" onClick={onRetryPayment}>
            Try payment again
          </Button>
        )}
      </AlertDescription>
    </Alert>
  )
}
