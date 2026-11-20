"use client"

import { Star } from "lucide-react"
import type { OrganizerReview } from "@/lib/api/vendor-reputation"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Card, CardContent } from "@/components/ui/card"

const CATEGORY_LABELS: Record<string, string> = {
  professionalism: "Professionalism",
  setupReadiness: "Setup readiness",
  productQuality: "Product quality",
  communication: "Communication",
  cleanliness: "Cleanliness",
  arrivalTime: "Arrival time",
  customerEngagement: "Customer engagement",
}

export function ReviewCard({ review }: { review: OrganizerReview }) {
  const categories = review.categories
    ? Object.entries(review.categories).filter(([, v]) => typeof v === "number")
    : []

  return (
    <Card
      className="rounded-2xl"
      data-testid="vendor-review-card"
      onClick={() =>
        trackLnkupEvent({
          event: "vendor_review_card_viewed",
          source: "vendor_reviews",
          requestId: review.id,
        })
      }
    >
      <CardContent className="flex flex-col gap-2 pt-6">
        <div className="flex items-center justify-between gap-2">
          <span className="font-medium">{review.eventName ?? "Event"}</span>
          {typeof review.rating === "number" && (
            <span className="inline-flex items-center gap-1 text-sm font-medium">
              <Star className="size-4 fill-accent text-accent" />
              {review.rating}
            </span>
          )}
        </div>
        <span className="text-xs text-muted-foreground">
          {[review.organizerName, review.eventDate, review.createdAt].filter(Boolean).join(" · ")}
        </span>
        {review.text && (
          <p className="text-sm text-muted-foreground">&ldquo;{review.text}&rdquo;</p>
        )}
        {review.wouldBookAgain && (
          <p className="text-xs font-medium text-accent">Would book again</p>
        )}
        {categories.length > 0 && (
          <div className="flex flex-wrap gap-2 pt-1">
            {categories.map(([key, value]) => (
              <span
                key={key}
                className="rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground"
              >
                {CATEGORY_LABELS[key] ?? key}: {value}/5
              </span>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
