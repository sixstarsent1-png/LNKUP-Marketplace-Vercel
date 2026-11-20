"use client"

import type { VendorEventDayChecklistItem } from "@/lib/api/vendor-operations"
import { CheckCircle2, Circle } from "lucide-react"
import { cn } from "@/lib/utils"

export function VendorEventReadinessChecklist({
  items,
  limit,
  compact = false,
}: {
  items: VendorEventDayChecklistItem[]
  limit?: number
  compact?: boolean
}) {
  const visible = limit ? items.filter((i) => !i.complete).slice(0, limit) : items

  if (visible.length === 0) return null

  return (
    <ul
      className={cn("flex flex-col gap-2", compact && "gap-1.5")}
      data-testid="vendor-event-readiness-checklist"
    >
      {visible.map((item) => (
        <li
          key={item.id}
          className={cn(
            "flex items-start gap-2 text-sm",
            item.complete && "text-muted-foreground",
          )}
        >
          {item.complete ? (
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-accent" />
          ) : (
            <Circle className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          )}
          <span className={cn(compact && "text-xs")}>{item.label}</span>
        </li>
      ))}
    </ul>
  )
}
