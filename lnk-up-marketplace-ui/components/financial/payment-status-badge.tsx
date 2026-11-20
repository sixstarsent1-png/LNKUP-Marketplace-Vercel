"use client"

import { mapPaymentStatusLabel } from "@/lib/api/vendor-financial"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

export function PaymentStatusBadge({
  status,
  className,
}: {
  status?: string
  className?: string
}) {
  const label = mapPaymentStatusLabel(status)
  const normalized = (status ?? "").toLowerCase()

  let variant: "default" | "secondary" | "destructive" | "outline" = "outline"
  if (normalized.includes("paid") || normalized.includes("complete")) variant = "default"
  else if (
    normalized.includes("pending") ||
    normalized.includes("required") ||
    normalized.includes("open") ||
    normalized.includes("disput")
  ) {
    variant = "secondary"
  } else if (normalized.includes("fail") || normalized.includes("reject")) variant = "destructive"

  return (
    <Badge variant={variant} className={cn("font-semibold", className)} data-testid="payment-status-badge">
      {label}
    </Badge>
  )
}
