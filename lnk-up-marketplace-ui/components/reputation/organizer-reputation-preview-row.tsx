import { Star } from "lucide-react"
import type { OrganizerVendorReputationPreview } from "@/lib/api/vendor-reputation"
import { ReputationTierLabel } from "@/components/reputation/reputation-tier-label"
import { ReputationBadge } from "@/components/reputation/reputation-badge"

export function OrganizerReputationPreviewRow({
  preview,
}: {
  preview?: OrganizerVendorReputationPreview
}) {
  if (!preview) return null

  const earnedBadges = preview.badges?.filter((b) => b.earned).slice(0, 2) ?? []

  return (
    <div
      className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
      data-testid="organizer-reputation-preview"
    >
      <ReputationTierLabel tier={preview.tier} className="text-xs" />
      {typeof preview.rating === "number" && (
        <span className="inline-flex items-center gap-0.5">
          <Star className="size-3 fill-accent text-accent" />
          {preview.rating.toFixed(1)}
        </span>
      )}
      {typeof preview.completedEvents === "number" && (
        <span>{preview.completedEvents} events</span>
      )}
      {earnedBadges.map((badge) => (
        <ReputationBadge key={badge.id} badge={badge} />
      ))}
    </div>
  )
}
