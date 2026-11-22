"use client"

import { Suspense, useEffect, useMemo } from "react"
import Link from "next/link"
import { Star } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { useVendorReputation } from "@/lib/hooks/use-vendor-reputation"
import { useVendorReviews } from "@/lib/hooks/use-vendor-reviews"
import { useVendorReviewEligibility } from "@/lib/hooks/use-vendor-review-eligibility"
import { useVendorReadiness } from "@/lib/hooks/use-vendor-readiness"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { ReputationSummaryCard } from "@/components/reputation/reputation-summary-card"
import { ReputationBadge } from "@/components/reputation/reputation-badge"
import { ReviewCard } from "@/components/reputation/review-card"
import { CompletedEventCard } from "@/components/reputation/completed-event-card"
import { ReviewEligibilityBanner } from "@/components/reputation/review-eligibility-banner"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

function ReviewsContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { summary, completedEvents, loading, error, unauthenticated, retry } = useVendorReputation()
  const { items: reviews, loading: reviewsLoading } = useVendorReviews()
  const { items: eligibility } = useVendorReviewEligibility()
  const { readiness } = useVendorReadiness()

  const isNewVendor = summary?.tier === "new" || (summary?.reviewCount ?? 0) === 0

  const panelState = useMemo(() => {
    if (qaState === "error") return "error" as const
    if (qaState === "loading") return "loading" as const
    if (live && loading) return "loading" as const
    if (live && error) return "error" as const
    if (!summary) return "empty" as const
    if (isNewVendor && reviews.length === 0) return "empty" as const
    return "content" as const
  }, [qaState, live, loading, error, summary, isNewVendor, reviews.length])

  useEffect(() => {
    if (panelState === "content" || panelState === "empty") {
      trackLnkupEvent({ event: "vendor_reputation_viewed", source: "vendor_reviews_page" })
    }
  }, [panelState])

  const handleRetry = () => {
    if (qaState === "error") qaRetry()
    else retry()
  }

  const docsGap = readiness?.missingItems.some(
    (m) =>
      m.severity === "required" &&
      (m.label.toLowerCase().includes("document") ||
        m.label.toLowerCase().includes("insurance")),
  )

  return (
    <div className="flex flex-col gap-6" data-testid="vendor-reviews">
      <PageHeader
        title="Reputation"
        subtitle="Build your reputation with every completed event."
      />

      {!live && (
        <Alert>
          <AlertDescription>Demo mode — reputation uses sample data.</AlertDescription>
        </Alert>
      )}

      {live && unauthenticated && (
        <Alert>
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>Sign in to view your reputation.</span>
            <Button size="sm" nativeButton={false} render={<Link href="/login" />}>
              Sign in
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {docsGap && (
        <Alert data-testid="vendor-reviews-docs-cta">
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>Verified vendors get reviewed faster. Upload documents to strengthen your profile.</span>
            <Button size="sm" nativeButton={false} render={<Link href="/vendor/documents" />}>
              Upload documents
            </Button>
          </AlertDescription>
        </Alert>
      )}

      <PanelContent
        state={panelState}
        retry={handleRetry}
        empty={
          <EmptyState
            icon={Star}
            title="Your reputation is just getting started"
            description="Your reputation will build as you complete events and receive organizer reviews."
            action={
              <Button nativeButton={false} render={<Link href="/vendor/find" />}>
                Find events
              </Button>
            }
          />
        }
      >
        {summary && <ReputationSummaryCard summary={summary} />}

        {summary && summary.badges.length > 0 && (
          <section data-testid="vendor-reviews-badges">
            <SectionHeader title="Trust badges" />
            <div className="flex flex-wrap gap-2">
              {summary.badges.map((badge) => (
                <ReputationBadge
                  key={badge.id}
                  badge={badge}
                  onView={() =>
                    trackLnkupEvent({
                      event: "vendor_badge_viewed",
                      source: "vendor_reviews",
                      metadata: { badgeId: badge.id },
                    })
                  }
                />
              ))}
            </div>
          </section>
        )}

        {eligibility.length > 0 && (
          <section data-testid="vendor-reviews-eligibility" className="flex flex-col gap-3">
            <SectionHeader title="Review status" />
            {eligibility.map((item) => (
              <ReviewEligibilityBanner key={item.applicationId} item={item} />
            ))}
          </section>
        )}

        {!reviewsLoading && reviews.length > 0 && (
          <section data-testid="vendor-reviews-list">
            <SectionHeader title="Organizer reviews" />
            <div className="flex flex-col gap-3">
              {reviews.map((review) => (
                <ReviewCard key={review.id} review={review} canRespond />
              ))}
            </div>
          </section>
        )}

        {completedEvents.length > 0 && (
          <section data-testid="vendor-reviews-completed-events">
            <SectionHeader title="Completed events" />
            <div className="flex flex-col gap-3">
              {completedEvents.map((event) => (
                <CompletedEventCard key={event.id} event={event} />
              ))}
            </div>
          </section>
        )}

        {summary && (summary.improvements?.length ?? 0) > 0 && (
          <section data-testid="vendor-reviews-improvements">
            <SectionHeader title="Areas to improve" />
            <Card className="rounded-2xl">
              <CardContent className="p-4">
                <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                  {summary.improvements!.map((tip) => (
                    <li key={tip}>{tip}</li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </section>
        )}

        {summary && (summary.strengths?.length ?? 0) > 0 && (
          <section>
            <SectionHeader title="Strengths" />
            <Card className="rounded-2xl">
              <CardContent className="p-4">
                <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                  {summary.strengths!.map((tip) => (
                    <li key={tip}>{tip}</li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </section>
        )}
      </PanelContent>
    </div>
  )
}

export default function VendorReviewsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <ReviewsContent />
    </Suspense>
  )
}
