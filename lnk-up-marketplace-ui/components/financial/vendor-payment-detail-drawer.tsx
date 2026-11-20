"use client"

import { useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { CheckCircle2, ExternalLink, Loader2, LogIn } from "lucide-react"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { getVendorDashboardPaymentsUrl, getVendorLoginUrl } from "@/lib/api/config"
import { useVendorPaymentDetail } from "@/lib/hooks/use-vendor-payment-detail"
import { useVendorDisputes } from "@/lib/hooks/use-vendor-disputes"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PaymentStatusBadge } from "@/components/financial/payment-status-badge"
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
import { Separator } from "@/components/ui/separator"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"

function formatMoney(amount?: number, currency = "USD") {
  if (typeof amount !== "number") return null
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function VendorPaymentDetailDrawer({
  request,
  open,
  onOpenChange,
  source = "vendor_payment_detail_drawer",
}: {
  request: VendorRequest | null
  open: boolean
  onOpenChange: (open: boolean) => void
  source?: string
}) {
  const requestId = open ? request?.id : undefined
  const {
    detail,
    summary,
    loading,
    linkLoading,
    error,
    unauthenticated,
    retry,
    requestPaymentLink,
  } = useVendorPaymentDetail(requestId)
  const { items: disputes, openDispute, submitting, retry: retryDisputes } = useVendorDisputes(open)
  const [reason, setReason] = useState("")
  const [disputeError, setDisputeError] = useState<string | null>(null)
  const [disputeSuccess, setDisputeSuccess] = useState(false)

  useEffect(() => {
    if (!open) {
      setReason("")
      setDisputeError(null)
      setDisputeSuccess(false)
    }
  }, [open])

  const vendorFee = detail?.vendorFee ?? summary?.vendorFee ?? request?.vendorFee ?? request?.amountDue
  const lnkupFee = detail?.lnkupFee ?? summary?.lnkupFee
  const processingFee = detail?.processingFee ?? summary?.processingFee
  const total = detail?.total ?? summary?.total ?? request?.amountDue ?? request?.vendorFee
  const currency = detail?.currency ?? request?.currency ?? "USD"
  const isPaid = detail?.status === "paid" || request?.status === "paid" || request?.status === "completed"
  const needsPayment =
    detail?.status === "payment_required" ||
    request?.status === "payment_required" ||
    request?.status === "approved"
  const paymentId = detail?.paymentId ?? request?.paymentId
  const openDisputeForPayment = useMemo(
    () => disputes.find((d) => d.isOpen && d.paymentId && paymentId && d.paymentId === paymentId),
    [disputes, paymentId],
  )
  const wpUrl =
    detail?.wordpressUrl ??
    getVendorDashboardPaymentsUrl(
      openDisputeForPayment || isPaid
        ? { finTab: "disputes", disputePayment: paymentId }
        : undefined,
    )

  async function handleContinuePayment() {
    trackLnkupEvent({
      event: "vendor_continue_payment_clicked",
      source,
      requestId: request?.id,
      metadata: { status: detail?.status ?? request?.status ?? "unknown" },
    })
    trackLnkupEvent({
      event: "payment_handoff_clicked",
      source,
      requestId: request?.id,
    })
    const result = await requestPaymentLink()
    if (result?.checkoutUrl) {
      window.location.href = result.checkoutUrl
    }
  }

  function handleReceiptClick() {
    trackLnkupEvent({
      event: "vendor_receipt_clicked",
      source,
      requestId: request?.id,
      metadata: { status: "paid" },
    })
  }

  async function handleOpenDispute() {
    if (!paymentId) {
      setDisputeError("Payment reference is missing. Open LNKup dashboard to dispute.")
      return
    }
    setDisputeError(null)
    try {
      await openDispute(paymentId, reason.trim())
      setDisputeSuccess(true)
      setReason("")
      trackLnkupEvent({
        event: "vendor_dispute_opened",
        source,
        requestId: request?.id,
        metadata: { paymentId },
      })
      retryDisputes()
    } catch (err) {
      setDisputeError(err instanceof Error ? err.message : "Could not open dispute.")
    }
  }

  function handleHandoffClick() {
      trackLnkupEvent({
        event: "vendor_dispute_handoff_clicked",
        source,
        requestId: request?.id,
        metadata: { paymentId: paymentId ?? null },
      })
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="bottom"
        className="flex max-h-[85dvh] flex-col rounded-t-3xl"
        data-testid="vendor-payment-detail-drawer"
      >
        <SheetHeader className="shrink-0">
          <SheetTitle>Payment details</SheetTitle>
          <SheetDescription>
            Pay in secure checkout, then return here for your pass. We never collect card details in this app.
          </SheetDescription>
        </SheetHeader>

        {request && (
          <div className="flex flex-col gap-4 overflow-y-auto px-4 pb-2">
            <div className="rounded-2xl border p-4">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold">{request.eventName}</p>
                  {request.eventDate && (
                    <p className="text-xs text-muted-foreground">{request.eventDate}</p>
                  )}
                </div>
                <PaymentStatusBadge
                  status={openDisputeForPayment ? "disputed" : (detail?.status ?? request.status)}
                />
              </div>
            </div>

            {loading && (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="size-6 animate-spin text-muted-foreground" />
              </div>
            )}

            {unauthenticated && (
              <Alert>
                <LogIn className="size-4" />
                <AlertDescription className="flex flex-col gap-3">
                  Sign in to view payment details.
                  <Button
                    size="sm"
                    nativeButton={false}
                    render={<Link href={getVendorLoginUrl({ intent: "vendor" })} />}
                  >
                    Sign in
                  </Button>
                </AlertDescription>
              </Alert>
            )}

            {error && !loading && (
              <Alert variant="destructive">
                <AlertDescription className="flex flex-col gap-3">
                  {error}
                  <Button size="sm" variant="outline" onClick={retry}>
                    Try again
                  </Button>
                </AlertDescription>
              </Alert>
            )}

            {!loading && !error && !unauthenticated && (
              <>
                <div className="rounded-2xl bg-muted/60 p-4 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Vendor spot fee</span>
                    <span>{formatMoney(vendorFee, currency) ?? "—"}</span>
                  </div>
                  {lnkupFee != null && (
                    <div className="mt-1 flex justify-between">
                      <span className="text-muted-foreground">LNKup fee</span>
                      <span>{formatMoney(lnkupFee, currency)}</span>
                    </div>
                  )}
                  {processingFee != null && processingFee > 0 && (
                    <div className="mt-1 flex justify-between">
                      <span className="text-muted-foreground">Processing fee</span>
                      <span>{formatMoney(processingFee, currency)}</span>
                    </div>
                  )}
                  <Separator className="my-2" />
                  <div className="flex justify-between font-semibold">
                    <span>Total</span>
                    <span>{formatMoney(total, currency) ?? "—"}</span>
                  </div>
                  {request.paymentDueAt && (
                    <p className="mt-2 text-xs text-muted-foreground">Due {request.paymentDueAt}</p>
                  )}
                </div>

                {detail?.timeline && detail.timeline.length > 0 && (
                  <div className="flex flex-col gap-2" data-testid="payment-timeline">
                    <p className="text-sm font-semibold">Timeline</p>
                    {detail.timeline.map((step) => (
                      <div key={step.id} className="flex items-center gap-2 text-sm">
                        <CheckCircle2
                          className={
                            step.status === "complete"
                              ? "size-4 text-success"
                              : step.status === "current"
                                ? "size-4 text-primary"
                                : "size-4 text-muted-foreground"
                          }
                        />
                        <span>{step.label}</span>
                        {step.timestamp && (
                          <span className="text-xs text-muted-foreground">{step.timestamp}</span>
                        )}
                      </div>
                    ))}
                  </div>
                )}

                {isPaid && openDisputeForPayment && (
                  <Alert data-testid="dispute-status-banner">
                    <AlertDescription className="flex flex-col gap-2">
                      <span>
                        Dispute status:{" "}
                        <strong className="capitalize">
                          {openDisputeForPayment.status.replace(/_/g, " ")}
                        </strong>
                      </span>
                      <span className="text-muted-foreground text-xs">
                        Add evidence or track resolution in LNKup Payments.
                      </span>
                      {wpUrl && (
                        <Button
                          size="sm"
                          variant="outline"
                          nativeButton={false}
                          render={
                            <a
                              href={wpUrl}
                              target="_blank"
                              rel="noopener noreferrer"
                              onClick={handleHandoffClick}
                            />
                          }
                        >
                          <ExternalLink data-icon="inline-start" />
                          Manage on LNKup
                        </Button>
                      )}
                    </AlertDescription>
                  </Alert>
                )}

                {isPaid && !openDisputeForPayment && !disputeSuccess && (
                  <div className="flex flex-col gap-2 rounded-2xl border p-4" data-testid="dispute-charge-form">
                    <Label htmlFor="dispute-reason">Dispute this charge</Label>
                    <Textarea
                      id="dispute-reason"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder="Explain why this charge is incorrect (min 10 characters)"
                      rows={3}
                    />
                    {disputeError && <p className="text-sm text-destructive">{disputeError}</p>}
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={submitting || reason.trim().length < 10 || !paymentId}
                      onClick={handleOpenDispute}
                      data-testid="dispute-charge-submit"
                    >
                      {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
                      Dispute charge
                    </Button>
                  </div>
                )}

                {disputeSuccess && (
                  <Alert data-testid="dispute-opened-success">
                    <AlertDescription>
                      Dispute opened. The organizer will review it. Use LNKup for evidence and updates.
                    </AlertDescription>
                  </Alert>
                )}
              </>
            )}
          </div>
        )}

        <SheetFooter className="shrink-0 flex-col gap-2 sm:flex-col">
          {needsPayment && !isPaid && (
            <Button
              className="w-full"
              disabled={linkLoading || loading}
              onClick={handleContinuePayment}
              data-testid="detail-continue-payment"
            >
              {linkLoading ? <Loader2 className="size-4 animate-spin" /> : null}
              Continue payment
            </Button>
          )}
          {isPaid && detail?.receiptUrl && (
            <Button
              className="w-full"
              variant="secondary"
              nativeButton={false}
              render={
                detail.receiptUrl.startsWith("/") ? (
                  <Link href={detail.receiptUrl} onClick={handleReceiptClick} />
                ) : (
                  <a
                    href={detail.receiptUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={handleReceiptClick}
                  />
                )
              }
              data-testid="detail-view-receipt"
            >
              View receipt
            </Button>
          )}
          {wpUrl && (
            <Button
              className="w-full"
              variant="outline"
              nativeButton={false}
              render={<a href={wpUrl} target="_blank" rel="noopener noreferrer" onClick={handleHandoffClick} />}
            >
              <ExternalLink data-icon="inline-start" />
              Open LNKup dashboard
            </Button>
          )}
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
