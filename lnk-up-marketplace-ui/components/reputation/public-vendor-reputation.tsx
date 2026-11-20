import { Star } from "lucide-react"
import type { PublicVendorReputation } from "@/lib/api/vendor-reputation"
import { ReputationTierLabel } from "@/components/reputation/reputation-tier-label"
import { ReputationBadge } from "@/components/reputation/reputation-badge"
import { ReviewCard } from "@/components/reputation/review-card"
import { Card, CardContent } from "@/components/ui/card"

export function PublicVendorReputationModule({
  reputation,
}: {
  reputation: PublicVendorReputation
}) {
  return (
    <div className="flex flex-col gap-4" data-testid="public-vendor-reputation">
      <Card className="rounded-2xl">
        <CardContent className="flex flex-col gap-3 p-6">
          {reputation.businessName && (
            <h3 className="font-heading text-lg font-semibold">{reputation.businessName}</h3>
          )}
          <ReputationTierLabel tier={reputation.tier} />
          <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
            {typeof reputation.rating === "number" && (
              <span className="inline-flex items-center gap-1 text-foreground">
                <Star className="size-4 fill-accent text-accent" />
                {reputation.rating.toFixed(1)}
              </span>
            )}
            <span>{reputation.reviewCount} reviews</span>
            <span>{reputation.completedEvents} completed events</span>
            {typeof reputation.score === "number" && (
              <span>Score {reputation.score}</span>
            )}
          </div>
          {reputation.badges.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {reputation.badges
                .filter((b) => b.earned)
                .map((badge) => (
                  <ReputationBadge key={badge.id} badge={badge} />
                ))}
            </div>
          )}
        </CardContent>
      </Card>

      {reputation.highlights && reputation.highlights.length > 0 && (
        <div className="flex flex-col gap-3">
          <h4 className="text-sm font-medium">Recent reviews</h4>
          {reputation.highlights.map((review) => (
            <ReviewCard key={review.id} review={review} />
          ))}
        </div>
      )}
    </div>
  )
}
