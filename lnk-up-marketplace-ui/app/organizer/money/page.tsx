"use client"

import { Suspense, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { ExternalLink, Loader2, Wallet } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { getOrganizerFinancialUrl } from "@/lib/api/config"
import { useOrganizerMoney } from "@/lib/hooks/use-organizer-money"
import { useOrganizerMoneyActivity } from "@/lib/hooks/use-organizer-money-activity"
import { useOrganizerMoneyEvents } from "@/lib/hooks/use-organizer-event-money"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { PageHeader } from "@/components/marketplace/page-header"
import { OrganizerFinancialSnapshot } from "@/components/organizer/organizer-financial-snapshot"
import { PaymentActivityList } from "@/components/financial/payment-activity-list"
import { MoneyStatCard } from "@/components/financial/money-stat-card"
import { PanelContent, SkeletonList, EmptyState } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

function payoutCopy(status?: string) {
  switch (status) {
    case "active":
      return "Payouts active"
    case "setup_required":
      return "Finish payout setup"
    case "pending":
      return "Payout setup pending"
    default:
      return "Payout status unavailable"
  }
}

function OrganizerPayoutActions({
  payoutStatus,
  availableBalance,
  currency,
  onCashoutSuccess,
}: {
  payoutStatus?: string
  availableBalance?: number
  currency?: string
  onCashoutSuccess: () => void
}) {
  const [amount, setAmount] = useState(
    availableBalance && availableBalance > 0 ? String(availableBalance) : "",
  )
  const [cashoutType, setCashoutType] = useState<"standard" | "instant">("standard")
  const [busy, setBusy] = useState<"cashout" | "login" | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (availableBalance != null && availableBalance > 0) {
      setAmount(String(availableBalance))
    }
  }, [availableBalance])

  async function handleCashout() {
    setBusy("cashout")
    setError(null)
    setMessage(null)
    try {
      const response = await fetch("/api/organizer/financial/cashout", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ amount: Number(amount), type: cashoutType }),
      })
      const { data, error: apiError } = await parseApiResponse<{ message?: string }>(response)
      if (apiError || !response.ok) {
        setError(apiError?.message ?? "Cashout could not be completed.")
        return
      }
      setMessage(data?.message ?? "Cashout requested.")
      trackLnkupEvent({
        event: "wordpress_financial_center_opened",
        source: "organizer_money_cashout",
        metadata: { type: cashoutType },
      })
      onCashoutSuccess()
    } finally {
      setBusy(null)
    }
  }

  async function handleLoginLink() {
    setBusy("login")
    setError(null)
    setMessage(null)
    try {
      const response = await fetch("/api/organizer/financial/login-link", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({}),
      })
      const { data, error: apiError } = await parseApiResponse<{ url?: string; message?: string }>(response)
      if (apiError || !response.ok || !data?.url) {
        setError(apiError?.message ?? data?.message ?? "Could not open payout account.")
        return
      }
      trackLnkupEvent({
        event: "financial_handoff_clicked",
        source: "organizer_money_login_link",
      })
      window.open(data.url, "_blank", "noopener,noreferrer")
      setMessage(data.message ?? "Opened Stripe Express.")
    } finally {
      setBusy(null)
    }
  }

  const canCashout = payoutStatus === "active"

  return (
    <Card className="rounded-2xl" data-testid="organizer-payout-actions">
      <CardHeader>
        <CardTitle className="text-base">Payouts & cashout</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm font-semibold">{payoutCopy(payoutStatus)}</p>
        <p className="text-sm text-muted-foreground">
          Available balance:{" "}
          {availableBalance != null
            ? new Intl.NumberFormat("en-US", {
                style: "currency",
                currency: currency ?? "USD",
              }).format(availableBalance)
            : "—"}
        </p>

        <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
          <label className="grid gap-1 text-sm">
            <span className="text-muted-foreground">Cashout amount</span>
            <Input
              type="number"
              min={0}
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              disabled={!canCashout || busy !== null}
              data-testid="organizer-cashout-amount"
            />
          </label>
          <select
            className="h-9 rounded-md border bg-background px-2 text-sm"
            value={cashoutType}
            onChange={(e) => setCashoutType(e.target.value === "instant" ? "instant" : "standard")}
            disabled={!canCashout || busy !== null}
          >
            <option value="standard">Standard</option>
            <option value="instant">Instant</option>
          </select>
          <Button
            type="button"
            onClick={handleCashout}
            disabled={!canCashout || busy !== null || !(Number(amount) > 0)}
            data-testid="organizer-cashout-submit"
          >
            {busy === "cashout" && <Loader2 className="animate-spin" data-icon="inline-start" />}
            Request cashout
          </Button>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleLoginLink}
            disabled={busy !== null}
            data-testid="organizer-connect-login-link"
          >
            {busy === "login" && <Loader2 className="animate-spin" data-icon="inline-start" />}
            Open payout account
          </Button>
        </div>

        {!canCashout && (
          <p className="text-sm text-muted-foreground">
            Finish payout setup in LNKup to request cashouts. You can still open Stripe Express if your
            account is already connected.
          </p>
        )}
        {message && <p className="text-sm text-emerald-700">{message}</p>}
        {error && <p className="text-sm text-destructive">{error}</p>}
      </CardContent>
    </Card>
  )
}

function OrganizerMoneyContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { summary, loading, error, retry } = useOrganizerMoney()
  const activity = useOrganizerMoneyActivity()
  const events = useOrganizerMoneyEvents()
  const financialUrl = getOrganizerFinancialUrl()

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (!summary) return "empty"
    return "content"
  }, [qaState, live, loading, error, summary])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
    activity.retry()
    events.retry()
  }

  function handleActivityClick(item: { href?: string; eventId?: string }) {
    if (typeof window === "undefined") return
    if (item.href) {
      window.location.href = item.href
      return
    }
    if (item.eventId) {
      window.location.href = `/organizer/events/${item.eventId}`
    }
  }

  useEffect(() => {
    trackLnkupEvent({ event: "organizer_money_viewed", source: "organizer_money_page" })
  }, [])

  return (
    <PanelContent state={panelState} retry={handleRetry}>
      <div className="flex flex-col gap-6" data-testid="organizer-money">
        <PageHeader
          title="Money"
          subtitle="Vendor fees, cashouts, and payout account access."
          action={
            financialUrl ? (
              <Button
                variant="outline"
                nativeButton={false}
                render={<Link href={financialUrl} target="_blank" rel="noopener noreferrer" />}
                onClick={() =>
                  trackLnkupEvent({
                    event: "wordpress_financial_center_opened",
                    source: "organizer_money_header",
                  })
                }
              >
                <ExternalLink data-icon="inline-start" />
                More on web
              </Button>
            ) : undefined
          }
        />

        <Tabs defaultValue="overview">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="activity">Activity</TabsTrigger>
            <TabsTrigger value="events">Events</TabsTrigger>
            <TabsTrigger value="payouts">Payouts</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-4 flex flex-col gap-4">
            {summary && <OrganizerFinancialSnapshot snapshot={summary} />}
            <OrganizerPayoutActions
              payoutStatus={summary?.payoutStatus}
              availableBalance={summary?.availableBalance}
              currency={summary?.currency}
              onCashoutSuccess={() => {
                retry()
                activity.retry()
              }}
            />
          </TabsContent>

          <TabsContent value="activity" className="mt-4" data-testid="organizer-money-activity">
            {activity.loading ? (
              <SkeletonList count={3} />
            ) : activity.error ? (
              <EmptyState icon={Wallet} title="Couldn't load activity" description={activity.error} />
            ) : (
              <PaymentActivityList
                items={activity.items}
                emptyMessage="Financial activity will appear here."
                onItemClick={handleActivityClick}
              />
            )}
          </TabsContent>

          <TabsContent value="events" className="mt-4 flex flex-col gap-3" data-testid="organizer-money-events">
            {events.loading ? (
              <SkeletonList count={2} />
            ) : events.items.length === 0 ? (
              <EmptyState icon={Wallet} title="No event finances yet" description="Event payment summaries appear here." />
            ) : (
              events.items.map((event) => (
                <Card key={event.eventId} className="rounded-2xl">
                  <CardContent className="flex flex-col gap-3 py-4">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold">{event.eventName ?? "Event"}</p>
                        <p className="text-xs text-muted-foreground">
                          {event.paidVendorCount ?? 0} paid · {event.paymentRequiredCount ?? 0} payment required
                        </p>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        nativeButton={false}
                        render={<Link href={`/organizer/events/${event.eventId}`} />}
                      >
                        View event
                      </Button>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <MoneyStatCard label="Collected" amount={event.collected} currency={event.currency} />
                      <MoneyStatCard label="Pending" amount={event.pending} currency={event.currency} tone="warning" />
                    </div>
                  </CardContent>
                </Card>
              ))
            )}
          </TabsContent>

          <TabsContent value="payouts" className="mt-4">
            <OrganizerPayoutActions
              payoutStatus={summary?.payoutStatus}
              availableBalance={summary?.availableBalance}
              currency={summary?.currency}
              onCashoutSuccess={() => {
                retry()
                activity.retry()
              }}
            />
            {financialUrl && (
              <div className="mt-4">
                <Button
                  variant="outline"
                  nativeButton={false}
                  render={<Link href={financialUrl} target="_blank" rel="noopener noreferrer" />}
                >
                  Advanced settings on LNKup web
                </Button>
              </div>
            )}
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

export default function OrganizerMoneyPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerMoneyContent />
    </Suspense>
  )
}
