"use client"

import type { VendorFinancialActivityItem } from "@/lib/api/vendor-financial"
import type { OrganizerFinancialActivityItem } from "@/lib/api/organizer-financial"
import { PaymentStatusBadge } from "@/components/financial/payment-status-badge"
import { Card, CardContent } from "@/components/ui/card"

type ActivityItem = VendorFinancialActivityItem | OrganizerFinancialActivityItem

function formatMoney(amount?: number, currency = "USD") {
  if (amount == null) return null
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function PaymentActivityList({
  items,
  emptyMessage = "No recent activity yet.",
  testId = "payment-activity-list",
  onItemClick,
}: {
  items: ActivityItem[]
  emptyMessage?: string
  testId?: string
  onItemClick?: (item: ActivityItem) => void
}) {
  if (items.length === 0) {
    return (
      <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground" data-testid={testId}>
        {emptyMessage}
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-2" data-testid={testId}>
      {items.map((item) => (
        <Card
          key={item.id}
          className="rounded-2xl"
          data-testid={onItemClick ? "payment-activity-item-clickable" : "payment-activity-item"}
        >
          <CardContent
            className="flex items-start justify-between gap-3 py-4"
            role={onItemClick ? "button" : undefined}
            onClick={onItemClick ? () => onItemClick(item) : undefined}
          >
            <div className="flex min-w-0 flex-1 flex-col gap-1">
              <span className="text-sm font-semibold">{item.label}</span>
              {(item.description || item.eventName) && (
                <span className="text-xs text-muted-foreground">{item.description ?? item.eventName}</span>
              )}
              {item.createdAt && (
                <span className="text-xs text-muted-foreground">{item.createdAt}</span>
              )}
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1">
              {item.amount != null && (
                <span className="text-sm font-semibold">{formatMoney(item.amount, item.currency)}</span>
              )}
              {item.status && <PaymentStatusBadge status={item.status} />}
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
