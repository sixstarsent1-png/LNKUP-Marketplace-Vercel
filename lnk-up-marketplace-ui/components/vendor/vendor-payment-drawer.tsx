"use client"

import Link from "next/link"
import { Loader2, LogIn } from "lucide-react"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { useVendorPaymentHandoff } from "@/lib/hooks/use-vendor-payment-handoff"
import { getVendorLoginUrl } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Alert, AlertDescription } from "@/components/ui/alert"

function formatMoney(amount?: number, currency = "USD") {
  if (typeof amount !== "number") return null
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function VendorPaymentDrawer({
  request,
  open,
  onOpenChange,
}: {
  request: VendorRequest | null
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const applicationId = request?.id
  const {
    summary,
    loading,
    linkLoading,
    error,
    unauthenticated,
    fetchSummary,
    requestPaymentLink,
  } = useVendorPaymentHandoff(open ? applicationId : undefined)

  const vendorFee = summary?.vendorFee ?? request?.vendorFee ?? request?.amountDue
  const lnkupFee = summary?.lnkupFee
  const total = summary?.total ?? request?.amountDue ?? request?.vendorFee
  const dueAt = request?.paymentDueAt

  async function handleContinuePayment() {
    trackLnkupEvent({
      event: "payment_handoff_clicked",
      source: "vendor_payment_drawer",
      requestId: applicationId,
    })
    const result = await requestPaymentLink()
    if (result?.checkoutUrl) {
      window.location.href = result.checkoutUrl
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="flex max-h-[85dvh] flex-col rounded-t-3xl" data-testid="vendor-payment-drawer">
        <SheetHeader className="shrink-0">
          <SheetTitle>Complete payment</SheetTitle>
          <SheetDescription>
            Vendors apply for free. Payment is only required after an organizer approves you.
          </SheetDescription>
        </SheetHeader>

        {request && (
          <div className="flex flex-col gap-4 overflow-y-auto px-4 pb-2">
            <div className="rounded-2xl border p-4">
              <p className="text-sm font-semibold">{request.eventName}</p>
              {request.eventDate && (
                <p className="text-xs text-muted-foreground">{request.eventDate}</p>
              )}
            </div>

            {unauthenticated && (
              <Alert>
                <LogIn />
                <AlertDescription className="flex flex-col gap-3">
                  <span>Sign in to continue payment.</span>
                  <Button
                    size="sm"
                    nativeButton={false}
                    render={<Link href={getVendorLoginUrl({ intent: "vendor", redirect: "/vendor/requests" })} />}
                  >
                    Sign in
                  </Button>
                </AlertDescription>
              </Alert>
            )}

            {loading && (
              <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading payment details…
              </div>
            )}

            {!loading && !unauthenticated && error && (
              <Alert variant="destructive">
                <AlertDescription className="flex flex-col gap-3">
                  <span>{error}</span>
                  <Button size="sm" variant="outline" onClick={() => fetchSummary()}>
                    Try again
                  </Button>
                </AlertDescription>
              </Alert>
            )}

            {!loading && !unauthenticated && !error && (
              <div className="flex flex-col gap-2 text-sm">
                {typeof vendorFee === "number" && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Vendor fee</span>
                    <span>{formatMoney(vendorFee)}</span>
                  </div>
                )}
                {typeof lnkupFee === "number" && lnkupFee > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">LNKup fee</span>
                    <span>{formatMoney(lnkupFee)}</span>
                  </div>
                )}
                {typeof total === "number" && (
                  <div className="flex justify-between border-t pt-2 font-semibold">
                    <span>Total due</span>
                    <span>{formatMoney(total)}</span>
                  </div>
                )}
                {dueAt && (
                  <p className="text-xs text-muted-foreground">Payment due · {dueAt}</p>
                )}
                <p className="text-xs text-muted-foreground">
                  Your event packet unlocks after payment.
                </p>
              </div>
            )}
          </div>
        )}

        <SheetFooter className="flex-row gap-2 border-t pt-4">
          <Button variant="outline" className="flex-1" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            className="flex-1"
            disabled={loading || linkLoading || unauthenticated || !request}
            onClick={handleContinuePayment}
            data-testid="vendor-payment-continue"
          >
            {linkLoading && <Loader2 className="animate-spin" data-icon="inline-start" />}
            Continue payment
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
