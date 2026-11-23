"use client"

import { Suspense, useCallback, useEffect, useMemo, useState } from "react"
import { Gift, Wallet, CheckCircle2, Receipt, AlertTriangle, ExternalLink } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useVendorWallet } from "@/lib/hooks/use-vendor-wallet"
import { useVendorWalletActivity } from "@/lib/hooks/use-vendor-wallet-activity"
import { useVendorWalletInvoices } from "@/lib/hooks/use-vendor-wallet-invoices"
import { useVendorDisputes } from "@/lib/hooks/use-vendor-disputes"
import { useVendorRequests } from "@/lib/hooks/use-vendor-requests"
import { usePaymentReturn } from "@/lib/hooks/use-payment-return"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { ExecutiveHero } from "@/components/vendor/executive-hero"
import { Surface } from "@/components/vendor/surface"
import { MoneyStatCard } from "@/components/financial/money-stat-card"
import { PaymentRequiredList } from "@/components/financial/payment-required-list"
import { PaymentActivityList } from "@/components/financial/payment-activity-list"
import { InvoiceCard } from "@/components/financial/invoice-card"
import { FinancialHandoffCard } from "@/components/financial/financial-handoff-card"
import { VendorPaymentDetailDrawer } from "@/components/financial/vendor-payment-detail-drawer"
import { PaymentReturnBanner } from "@/components/financial/payment-return-banner"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"

function VendorWalletContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { summary, paymentRequired, loading, error, retry } = useVendorWallet()
  const activity = useVendorWalletActivity()
  const documents = useVendorWalletInvoices()
  const disputes = useVendorDisputes()
  const { items: requests, retry: retryRequests } = useVendorRequests()
  const refetchWallet = useCallback(() => {
    retry()
    activity.retry()
    documents.retry()
    disputes.retry()
    retryRequests()
  }, [retry, activity.retry, documents.retry, disputes.retry, retryRequests])
  const paymentReturn = usePaymentReturn({
    requests,
    onRefetch: refetchWallet,
    source: "vendor_wallet",
  })
  const [selectedRequest, setSelectedRequest] = useState<VendorRequest | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (!summary && paymentRequired.length === 0) return "empty"
    return "content"
  }, [qaState, live, loading, error, summary, paymentRequired.length])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
    activity.retry()
    documents.retry()
    retryRequests()
  }

  const isEmptyWallet =
    panelState === "content" &&
    (summary?.paymentRequiredCount ?? paymentRequired.length) === 0 &&
    (summary?.paidBookingsCount ?? 0) === 0 &&
    activity.items.length === 0

  useEffect(() => {
    trackLnkupEvent({ event: "vendor_wallet_viewed", source: "vendor_wallet_page" })
  }, [])

  function handleContinuePayment(request: VendorRequest) {
    setSelectedRequest(request)
    setDrawerOpen(true)
    trackLnkupEvent({
      event: "vendor_payment_detail_viewed",
      source: "vendor_wallet",
      requestId: request.id,
      metadata: { status: request.status },
    })
  }

  function handleActivityClick(activityItem: { requestId?: string; eventName?: string }) {
    const requestId = activityItem.requestId
    if (!requestId) return
    const fromList = requests.find((request) => request.id === requestId)
    const fallback =
      fromList ??
      paymentRequired.find((request) => request.id === requestId) ??
      ({
        id: requestId,
        eventId: requestId,
        eventName: activityItem.eventName ?? "Event",
        status: "paid",
        currency: summary?.currency ?? "USD",
      } as VendorRequest)
    setSelectedRequest(fallback)
    setDrawerOpen(true)
  }

  function handleCreditsView() {
    trackLnkupEvent({ event: "vendor_credits_viewed", source: "vendor_wallet_page" })
  }

  return (
    <>
      <PanelContent state={panelState} retry={handleRetry}>
        <div className="flex flex-col gap-5" data-testid="vendor-wallet">
          <ExecutiveHero
            title="Wallet"
            subtitle="Your bookings, receipts, and credits in one place."
            primaryCta={
              (summary?.paymentRequiredCount ?? paymentRequired.length) > 0
                ? { label: "Pay now", href: "/vendor/requests?tab=approved" }
                : { label: "Find events", href: "/vendor/find" }
            }
          />

          <PaymentReturnBanner
            showSuccess={paymentReturn.showSuccess}
            showProcessing={paymentReturn.showProcessing}
            showCancelled={paymentReturn.showCancelled}
            timedOut={paymentReturn.timedOut}
            lastCheckedAt={paymentReturn.lastCheckedAt}
            nextRetryAt={paymentReturn.nextRetryAt}
            request={paymentReturn.matchedRequest}
            onDismiss={() => {
              paymentReturn.clearReturnParams()
              refetchWallet()
            }}
            onRetryReconcile={() => void paymentReturn.retryReconcile()}
          />

          {isEmptyWallet ? (
            <EmptyState
              icon={Wallet}
              title="No payment history yet"
              description="Payment history will appear here once you're approved and booked for events."
            />
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3" data-testid="vendor-wallet-summary">
                <MoneyStatCard
                  label="Payments due"
                  amount={summary?.amountDue}
                  currency={summary?.currency}
                  status={loading ? "loading" : error ? "unavailable" : "ready"}
                  sublabel={`${summary?.paymentRequiredCount ?? paymentRequired.length} to pay`}
                  icon={Wallet}
                  tone="primary"
                  testId="vendor-wallet-amount-due"
                />
                <MoneyStatCard
                  label="Paid bookings"
                  amount={summary?.totalPaid}
                  currency={summary?.currency}
                  status={loading ? "loading" : error ? "unavailable" : "ready"}
                  sublabel={(summary?.paidBookingsCount ?? 0) === 1 ? "1 booking" : `${summary?.paidBookingsCount ?? 0} bookings`}
                  icon={CheckCircle2}
                  tone="success"
                  testId="vendor-wallet-total-paid"
                />
              </div>

              {paymentRequired.length > 0 && (
                <Surface variant="primary" data-testid="vendor-wallet-payment-required">
                  <h2 className="mb-3 font-heading text-sm font-semibold">Needs payment</h2>
                  <PaymentRequiredList
                    items={paymentRequired}
                    onContinuePayment={handleContinuePayment}
                  />
                </Surface>
              )}

              <Surface variant="secondary" data-testid="vendor-wallet-activity">
                <h2 className="mb-3 font-heading text-sm font-semibold">Recent activity</h2>
                {activity.loading ? (
                  <SkeletonList count={2} />
                ) : (
                  <PaymentActivityList
                    items={activity.items}
                    emptyMessage="Activity will appear here after your first payment."
                    onItemClick={handleActivityClick}
                  />
                )}
              </Surface>

              {(disputes.items.length > 0 || disputes.loading) && (
                <Surface variant="secondary" data-testid="vendor-wallet-disputes">
                  <h2 className="mb-3 font-heading text-sm font-semibold">Your disputes</h2>
                  {disputes.loading ? (
                    <SkeletonList count={1} />
                  ) : (
                    <ul className="flex flex-col gap-2">
                      {disputes.items.map((d) => (
                        <li
                          key={d.id}
                          className="flex items-start justify-between gap-3 rounded-xl border p-3 text-sm"
                        >
                          <div className="min-w-0">
                            <p className="font-medium truncate">
                              {d.eventName ?? `Payment #${d.paymentId ?? d.id}`}
                            </p>
                            <p className="text-xs text-muted-foreground capitalize">
                              {d.status.replace(/_/g, " ")}
                              {d.amount != null
                                ? ` · ${new Intl.NumberFormat("en-US", {
                                    style: "currency",
                                    currency: d.currency ?? "USD",
                                  }).format(d.amount)}`
                                : ""}
                            </p>
                          </div>
                          {d.wordpressUrl && (
                            <a
                              href={d.wordpressUrl}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary"
                              onClick={() =>
                                trackLnkupEvent({
                                  event: "vendor_dispute_handoff_clicked",
                                  source: "vendor_wallet_disputes",
                                  metadata: { disputeId: d.id },
                                })
                              }
                            >
                              <AlertTriangle className="size-3.5" />
                              Manage
                              <ExternalLink className="size-3" />
                            </a>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </Surface>
              )}

              <Tabs defaultValue="documents">
                <TabsList variant="line" className="w-max">
                  <TabsTrigger value="documents">Invoices & receipts</TabsTrigger>
                  <TabsTrigger value="credits" onClick={handleCreditsView}>
                    Credits
                  </TabsTrigger>
                </TabsList>

                <TabsContent
                  value="documents"
                  className="mt-4 flex flex-col gap-3"
                  data-testid="vendor-wallet-documents"
                >
                  {documents.loading ? (
                    <SkeletonList count={2} />
                  ) : documents.invoices.length + documents.receipts.length === 0 ? (
                    <EmptyState
                      icon={Receipt}
                      title="No invoices or receipts yet"
                      description="Documents appear here after payment is complete."
                    />
                  ) : (
                    <>
                      {documents.invoices.map((item) => (
                        <InvoiceCard key={item.id} item={item} />
                      ))}
                      {documents.receipts.map((item) => (
                        <InvoiceCard key={item.id} item={item} />
                      ))}
                    </>
                  )}
                </TabsContent>

                <TabsContent value="credits" className="mt-4" data-testid="vendor-wallet-credits">
                  <MoneyStatCard
                    label="Available credits"
                    amount={summary?.availableCredits}
                    currency={summary?.currency}
                    sublabel={
                      summary?.pendingCredits
                        ? `${summary.pendingCredits} pending`
                        : "Referral and bonus credits"
                    }
                    icon={Gift}
                    tone="success"
                  />
                  {(summary?.pendingRefunds ?? 0) > 0 && (
                    <Surface variant="quiet" className="mt-3 text-sm text-muted-foreground">
                      Pending refunds: check LNKup web for status updates.
                    </Surface>
                  )}
                </TabsContent>
              </Tabs>

              <FinancialHandoffCard role="vendor" url={summary?.wordpressFinancialUrl} />
            </>
          )}
        </div>
      </PanelContent>

      <VendorPaymentDetailDrawer
        request={selectedRequest}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        source="vendor_wallet"
      />
    </>
  )
}

export default function VendorWalletPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorWalletContent />
    </Suspense>
  )
}
