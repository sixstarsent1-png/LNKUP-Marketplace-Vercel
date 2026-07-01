import type { LucideIcon } from "lucide-react"
import { cn } from "@/lib/utils"
import { Card } from "@/components/ui/card"

export function StatCard({
  label,
  value,
  icon: Icon,
  hint,
  accent,
  className,
}: {
  label: string
  value: string | number
  icon?: LucideIcon
  hint?: string
  accent?: boolean
  className?: string
}) {
  return (
    <Card
      className={cn(
        "gap-0 rounded-2xl p-4",
        accent && "bg-primary text-primary-foreground",
        className,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span
          className={cn(
            "text-xs font-medium",
            accent ? "text-primary-foreground/70" : "text-muted-foreground",
          )}
        >
          {label}
        </span>
        {Icon && (
          <span
            className={cn(
              "flex size-7 items-center justify-center rounded-full",
              accent ? "bg-primary-foreground/15" : "bg-accent",
            )}
          >
            <Icon
              className={cn(
                "size-4",
                accent ? "text-primary-foreground" : "text-accent-foreground",
              )}
            />
          </span>
        )}
      </div>
      <div className="mt-2 font-heading text-2xl font-bold tracking-tight">
        {value}
      </div>
      {hint && (
        <div
          className={cn(
            "mt-1 text-xs",
            accent ? "text-primary-foreground/70" : "text-muted-foreground",
          )}
        >
          {hint}
        </div>
      )}
    </Card>
  )
}
