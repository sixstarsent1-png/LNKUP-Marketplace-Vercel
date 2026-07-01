import type { LucideIcon } from "lucide-react"
import { cn } from "@/lib/utils"
import { Card } from "@/components/ui/card"

export function WalletCard({
  label,
  amount,
  icon: Icon,
  sublabel,
  tone = "default",
}: {
  label: string
  amount: string
  icon?: LucideIcon
  sublabel?: string
  tone?: "default" | "primary" | "warning" | "success"
}) {
  return (
    <Card
      className={cn(
        "gap-1 rounded-2xl p-4",
        tone === "primary" && "bg-primary text-primary-foreground",
      )}
    >
      <div className="flex items-center gap-2">
        {Icon && (
          <span
            className={cn(
              "flex size-8 items-center justify-center rounded-full",
              tone === "primary" ? "bg-primary-foreground/15" : "bg-accent",
            )}
          >
            <Icon
              className={cn(
                "size-4",
                tone === "primary" ? "text-primary-foreground" : "text-accent-foreground",
              )}
            />
          </span>
        )}
        <span
          className={cn(
            "text-sm font-medium",
            tone === "primary" ? "text-primary-foreground/70" : "text-muted-foreground",
          )}
        >
          {label}
        </span>
      </div>
      <div className="mt-1 font-heading text-2xl font-bold">{amount}</div>
      {sublabel && (
        <div
          className={cn(
            "text-xs",
            tone === "warning" && "text-warning",
            tone === "success" && "text-success",
            tone === "primary" ? "text-primary-foreground/70" : "text-muted-foreground",
          )}
        >
          {sublabel}
        </div>
      )}
    </Card>
  )
}
