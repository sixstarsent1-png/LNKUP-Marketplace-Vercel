"use client"

import Link from "next/link"
import { ChevronRight } from "lucide-react"
import type { VendorOperationsEvent } from "@/lib/api/vendor-operations"
import { OPS_STATUS_LABELS } from "@/lib/api/vendor-operations"
import { StatusBadge } from "@/components/marketplace/badges"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { VendorEventReadinessChecklist } from "@/components/vendor/operations/vendor-event-readiness-checklist"

function opsStatusVariant(
  status: VendorOperationsEvent["status"],
): "due" | "paid" | "review" | "rejected" {
  switch (status) {
    case "payment_required":
      return "due"
    case "pass_ready":
    case "event_day":
    case "completed":
      return "paid"
    case "cancelled":
      return "rejected"
    default:
      return "review"
  }
}

export function VendorOperationsEventCard({ event }: { event: VendorOperationsEvent }) {
  const location = [event.city, event.state].filter(Boolean).join(", ")
  const incomplete = event.checklist.filter((i) => !i.complete).slice(0, 3)

  const ctaLabel =
    event.status === "payment_required"
      ? "Continue payment"
      : event.status === "pass_ready" || event.status === "event_day"
        ? "View pass"
        : "Review details"

  const ctaHref =
    event.status === "payment_required" && event.paymentUrl
      ? event.paymentUrl
      : event.passUrl

  return (
    <Card className="rounded-2xl" data-testid="vendor-operations-event-card">
      <CardContent className="flex flex-col gap-3 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1">
            <h3 className="truncate font-semibold">{event.eventName}</h3>
            <p className="text-xs text-muted-foreground">
              {[event.eventDate, location].filter(Boolean).join(" · ")}
            </p>
            {event.organizerName && (
              <p className="text-xs text-muted-foreground">Organizer · {event.organizerName}</p>
            )}
          </div>
          <StatusBadge variant={opsStatusVariant(event.status)}>
            {OPS_STATUS_LABELS[event.status]}
          </StatusBadge>
        </div>

        {incomplete.length > 0 && (
          <div className="rounded-xl bg-muted/50 p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Readiness</p>
            <VendorEventReadinessChecklist items={incomplete} compact />
          </div>
        )}

        {event.boothAssignment && (
          <p className="text-xs text-muted-foreground">Booth · {event.boothAssignment}</p>
        )}

        <div className="flex items-center justify-between gap-2">
          <span className="text-xs text-muted-foreground">{event.readinessPercent}% ready</span>
          <Button
            size="sm"
            variant={event.status === "payment_required" ? "default" : "outline"}
            nativeButton={false}
            render={<Link href={ctaHref} />}
            data-testid="vendor-operations-card-cta"
          >
            {ctaLabel}
            <ChevronRight data-icon="inline-end" className="size-4" />
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
