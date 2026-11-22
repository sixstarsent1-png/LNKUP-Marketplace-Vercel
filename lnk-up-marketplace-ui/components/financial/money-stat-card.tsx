"use client"

import type { LucideIcon } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { cn } from "@/lib/utils"

function formatMoney(amount?: number | null, currency = "USD", status?: "ready" | "loading" | "unavailable") {
  if (status === "loading") return "Loading"
  if (status === "unavailable" || amount == null) return "Unavailable"
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount)
}

export function MoneyStatCard({
  label,
  amount,
  currency = "USD",
  sublabel,
  icon: Icon,
  tone = "default",
  status = "ready",
  testId,
}: {
  label: string
  amount?: number | null
  currency?: string
  sublabel?: string
  icon?: LucideIcon
  tone?: "default" | "primary" | "success" | "warning"
  status?: "ready" | "loading" | "unavailable"
  testId?: string
}) {
  return (
    <Card
      className={cn(
        "rounded-2xl",
        tone === "primary" && "border-primary/30 bg-primary/5",
        tone === "success" && "border-success/30 bg-success/5",
        tone === "warning" && "border-warning/30 bg-warning/5",
      )}
      data-testid={testId}
    >
      <CardContent className="flex flex-col gap-2 py-4">
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs font-medium text-muted-foreground">{label}</span>
          {Icon && <Icon className="size-4 text-muted-foreground" />}
        </div>
        <span className="font-heading text-2xl font-bold">{formatMoney(amount, currency, status)}</span>
        {sublabel && <span className="text-xs text-muted-foreground">{sublabel}</span>}
      </CardContent>
    </Card>
  )
}
