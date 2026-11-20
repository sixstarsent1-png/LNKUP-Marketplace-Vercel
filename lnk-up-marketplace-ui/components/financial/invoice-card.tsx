"use client"

import Link from "next/link"
import { FileText, Receipt } from "lucide-react"
import type { VendorInvoiceOrReceipt } from "@/lib/api/vendor-financial"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PaymentStatusBadge } from "@/components/financial/payment-status-badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

function formatMoney(amount?: number, currency = "USD") {
  if (amount == null) return "—"
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function InvoiceCard({
  item,
  onOpen,
}: {
  item: VendorInvoiceOrReceipt
  onOpen?: () => void
}) {
  const Icon = item.type === "receipt" ? Receipt : FileText
  const href = item.pdfUrl ?? item.wordpressUrl
  const label = item.type === "receipt" ? "Receipt" : "Invoice"
  const eventLabel = item.eventName ?? item.number ?? item.id

  function handleClick() {
    trackLnkupEvent({
      event: item.type === "receipt" ? "vendor_receipt_clicked" : "vendor_invoice_clicked",
      source: "invoice_card",
      metadata: { type: item.type, status: item.status ?? "unknown" },
    })
    onOpen?.()
  }

  return (
    <Card className="rounded-2xl" data-testid={`${item.type}-card-${item.id}`}>
      <CardContent className="flex items-center gap-3 py-4">
        <Icon className="size-5 shrink-0 text-muted-foreground" />
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <span className="text-sm font-semibold">
            {label} · {eventLabel}
          </span>
          <span className="text-xs text-muted-foreground">
            {item.number ? `#${item.number} · ` : ""}
            {formatMoney(item.amount, item.currency)}
            {item.issuedAt ? ` · ${item.issuedAt}` : ""}
          </span>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-2">
          {item.status && <PaymentStatusBadge status={item.status} />}
          {href ? (
            <Button
              size="sm"
              variant="outline"
              nativeButton={false}
              render={
                href.startsWith("/") ? (
                  <Link href={href} onClick={handleClick} />
                ) : (
                  <a href={href} target="_blank" rel="noopener noreferrer" onClick={handleClick} />
                )
              }
            >
              View
            </Button>
          ) : (
            <Button size="sm" variant="outline" disabled>
              Unavailable
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
