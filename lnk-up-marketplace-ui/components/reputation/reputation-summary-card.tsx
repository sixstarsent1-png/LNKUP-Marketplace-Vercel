import { Star, Calendar, MessageSquare } from "lucide-react"
import type { VendorReputationSummary } from "@/lib/api/vendor-reputation"
import { ReputationTierLabel } from "@/components/reputation/reputation-tier-label"
import { ReputationBadge } from "@/components/reputation/reputation-badge"
import { Card, CardContent } from "@/components/ui/card"

export function ReputationSummaryCard({
  summary,
  compact = false,
}: {
  summary: VendorReputationSummary
  compact?: boolean
}) {
  const earnedBadges = summary.badges.filter((b) => b.earned).slice(0, compact ? 3 : 6)

  return (
    <Card className="rounded-2xl" data-testid="reputation-summary-card">
      <CardContent className={compact ? "flex flex-col gap-2 p-4" : "flex flex-col gap-4 p-6"}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <ReputationTierLabel tier={summary.tier} />
            {!compact && summary.tierLabel && (
              <p className="mt-1 text-xs text-muted-foreground">{summary.tierLabel}</p>
            )}
          </div>
          {typeof summary.score === "number" && (
            <div className="text-right">
              <p className="font-heading text-2xl font-bold">{summary.score}</p>
              <p className="text-xs text-muted-foreground">Reputation score</p>
            </div>
          )}
        </div>

        <div className="flex flex-wrap gap-4 text-sm">
          {typeof summary.rating === "number" && (
            <span className="inline-flex items-center gap-1">
              <Star className="size-4 fill-accent text-accent" />
              {summary.rating.toFixed(1)} avg
            </span>
          )}
          <span className="inline-flex items-center gap-1 text-muted-foreground">
            <MessageSquare className="size-3.5" />
            {summary.reviewCount} reviews
          </span>
          <span className="inline-flex items-center gap-1 text-muted-foreground">
            <Calendar className="size-3.5" />
            {summary.completedEvents} completed events
          </span>
        </div>

        {earnedBadges.length > 0 && (
          <div className="flex flex-wrap gap-2" data-testid="reputation-badges">
            {earnedBadges.map((badge) => (
              <ReputationBadge key={badge.id} badge={badge} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
