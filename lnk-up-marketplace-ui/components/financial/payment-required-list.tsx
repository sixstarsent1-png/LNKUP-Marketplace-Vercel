"use client"

import { Calendar } from "lucide-react"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { PaymentStatusBadge } from "@/components/financial/payment-status-badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

function formatMoney(amount?: number, currency = "USD") {
  if (amount == null) return "—"
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function PaymentRequiredList({
  items,
  onContinuePayment,
  testId = "payment-required-list",
}: {
  items: VendorRequest[]
  onContinuePayment: (request: VendorRequest) => void
  testId?: string
}) {
  if (items.length === 0) return null

  return (
    <div className="flex flex-col gap-3" data-testid={testId}>
      {items.map((request) => (
        <Card key={request.id} className="rounded-2xl">
          <CardContent className="flex items-center gap-3 py-4">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{request.eventName}</span>
                <PaymentStatusBadge status="payment_required" />
              </div>
              {request.eventDate && (
                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                  <Calendar className="size-3" />
                  {request.eventDate}
                </span>
              )}
              {request.paymentDueAt && (
                <span className="text-xs text-muted-foreground">Due {request.paymentDueAt}</span>
              )}
            </div>
            <div className="flex shrink-0 flex-col items-end gap-2">
              <span className="font-heading font-bold">
                {formatMoney(request.amountDue ?? request.vendorFee, request.currency)}
              </span>
              <Button size="sm" onClick={() => onContinuePayment(request)} data-testid="continue-payment-button">
                Continue payment
              </Button>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
