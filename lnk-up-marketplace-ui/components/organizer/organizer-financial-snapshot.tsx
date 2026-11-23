"use client"

import Link from "next/link"
import { DollarSign, CheckCircle2, Clock } from "lucide-react"
import type { OrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { getOrganizerFinancialUrl } from "@/lib/api/config"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"

function formatMoney(amount?: number, currency = "USD"): string {
  if (amount == null) return "Unavailable"
  return new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: 0 }).format(amount)
}

function payoutLabel(status?: OrganizerFinancialSnapshot["payoutStatus"]): string {
  switch (status) {
    case "active":
      return "Payouts active"
    case "setup_required":
      return "Finish payout setup"
    case "pending":
      return "Payout setup pending"
    default:
      return "Payout status"
  }
}

export function OrganizerFinancialSnapshot({
  snapshot,
  compact,
}: {
  snapshot: OrganizerFinancialSnapshot
  compact?: boolean
}) {
  const financialUrl = snapshot.wordpressFinancialUrl || getOrganizerFinancialUrl()

  if (compact) {
    return (
      <Card className="rounded-2xl bg-primary text-primary-foreground" data-testid="organizer-financial-snapshot">
        <CardContent className="flex items-center gap-4 py-5">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent">
            <DollarSign className="size-5 text-accent-foreground" />
          </span>
          <div className="flex flex-1 flex-col">
            <span className="font-heading font-semibold">
              {formatMoney(snapshot.collectedToDate, snapshot.currency)} in vendor fees collected
            </span>
            <span className="text-sm text-primary-foreground/70">
              {payoutLabel(snapshot.payoutStatus)} · Read-only snapshot
            </span>
          </div>
          {financialUrl && (
            <Button
              variant="secondary"
              size="sm"
              nativeButton={false}
              render={<Link href={financialUrl} target="_blank" rel="noopener noreferrer" />}
            >
              More on web
            </Button>
          )}
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="rounded-2xl" data-testid="organizer-financial-snapshot">
      <CardContent className="flex flex-col gap-4 py-5">
        <div className="flex items-start justify-between gap-3">
          <div className="flex flex-col gap-1">
            <span className="text-sm font-medium text-muted-foreground">Financial snapshot</span>
            <span className="font-heading text-2xl font-bold">
              {formatMoney(snapshot.collectedToDate, snapshot.currency)}
            </span>
            <span className="text-xs text-muted-foreground">Vendor fees collected to date</span>
          </div>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-semibold">
            {snapshot.payoutStatus === "active" ? (
              <CheckCircle2 className="size-3.5 text-success" />
            ) : (
              <Clock className="size-3.5 text-warning" />
            )}
            {payoutLabel(snapshot.payoutStatus)}
          </span>
        </div>

        <div className="grid grid-cols-2 gap-3 text-sm">
          <div className="rounded-xl bg-muted/60 p-3">
            <span className="text-xs text-muted-foreground">Pending payments</span>
            <p className="font-semibold">{formatMoney(snapshot.pendingPayments, snapshot.currency)}</p>
          </div>
          <div className="rounded-xl bg-muted/60 p-3">
            <span className="text-xs text-muted-foreground">Booked vendors</span>
            <p className="font-semibold">{snapshot.paidVendorCount ?? "—"}</p>
          </div>
        </div>

        {financialUrl && (
          <Button
            variant="outline"
            className="w-full"
            nativeButton={false}
            render={<Link href={financialUrl} target="_blank" rel="noopener noreferrer" />}
          >
            More on web
          </Button>
        )}
      </CardContent>
    </Card>
  )
}
