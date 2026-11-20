import type { VendorReputationTier } from "@/lib/api/vendor-reputation"
import { TIER_LABELS } from "@/lib/api/vendor-reputation"
import { cn } from "@/lib/utils"

export function ReputationTierLabel({
  tier,
  className,
}: {
  tier: VendorReputationTier
  className?: string
}) {
  return (
    <span className={cn("text-sm font-medium", className)} data-testid="reputation-tier-label">
      {TIER_LABELS[tier]}
    </span>
  )
}
