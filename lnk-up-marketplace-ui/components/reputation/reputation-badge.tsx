import { Lock, CheckCircle2, Clock } from "lucide-react"
import type { VendorReputationBadge } from "@/lib/api/vendor-reputation"
import { cn } from "@/lib/utils"
import { Badge } from "@/components/ui/badge"

export function ReputationBadge({
  badge,
  onView,
}: {
  badge: VendorReputationBadge
  onView?: () => void
}) {
  const state = badge.state ?? (badge.earned ? "earned" : "locked")

  return (
    <Badge
      variant={state === "earned" ? "default" : "secondary"}
      className={cn(
        "gap-1 rounded-full px-2.5 py-1 text-xs font-medium",
        state === "locked" && "opacity-60",
        state === "in_progress" && "border-accent/40 bg-accent/10",
      )}
      data-testid={`reputation-badge-${badge.id}`}
      onClick={onView}
    >
      {state === "earned" && <CheckCircle2 className="size-3" />}
      {state === "locked" && <Lock className="size-3" />}
      {state === "in_progress" && <Clock className="size-3" />}
      <span>{badge.label}</span>
    </Badge>
  )
}
