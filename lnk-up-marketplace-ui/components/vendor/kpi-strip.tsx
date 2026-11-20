import type { LucideIcon } from "lucide-react"
import { cn } from "@/lib/utils"
import { StatCard } from "@/components/marketplace/stat-card"

export type KpiStripItem = {
  label: string
  value: string | number
  icon?: LucideIcon
  accent?: boolean
  hint?: string
}

export function KpiStrip({
  items,
  className,
  testId = "kpi-strip",
}: {
  items: KpiStripItem[]
  className?: string
  testId?: string
}) {
  return (
    <div
      className={cn(
        "grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5",
        className,
      )}
      data-testid={testId}
    >
      {items.map((item) => (
        <StatCard
          key={item.label}
          label={item.label}
          value={item.value}
          icon={item.icon}
          accent={item.accent}
          hint={item.hint}
        />
      ))}
    </div>
  )
}
