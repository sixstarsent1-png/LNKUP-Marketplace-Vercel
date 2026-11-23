"use client"

import Link from "next/link"
import { MapPin, Route, Sparkles } from "lucide-react"
import {
  VENDOR_MATCH_SECTION_LABELS,
  type MatchBand,
  type MatchedOpportunity,
  type MatchedTour,
  type VendorMatchSection,
  type VendorMatchSectionResult,
} from "@/lib/api/match"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { SectionHeader } from "@/components/marketplace/page-header"
import { SkeletonCard } from "@/components/marketplace/states"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"

const BAND_STYLES: Record<MatchBand, string> = {
  strong: "bg-primary text-primary-foreground",
  good: "bg-secondary text-secondary-foreground",
  possible: "bg-muted text-muted-foreground",
}

interface MatchRailsProps {
  sections: readonly VendorMatchSection[]
  results: Partial<Record<VendorMatchSection, VendorMatchSectionResult>>
  loading: boolean
}

/**
 * Personalized discovery sections. Empty or disabled sections are hidden;
 * a profile prompt shows once when matching needs more vendor data.
 */
export function MatchRails({ sections, results, loading }: MatchRailsProps) {
  if (loading) {
    return (
      <section data-testid="match-rails-loading" className="grid gap-4 sm:grid-cols-2">
        <SkeletonCard />
        <SkeletonCard />
      </section>
    )
  }

  const needsProfile = Object.values(results).some((r) => r?.status === "needs_profile")
  if (needsProfile) {
    return (
      <Card className="rounded-2xl border-dashed" data-testid="match-needs-profile">
        <CardContent className="flex flex-col gap-2 py-4">
          <p className="text-sm font-semibold">Get matched to the right events</p>
          <p className="text-sm text-muted-foreground">
            Add your categories and the cities you serve so LNKup can recommend events for you.
          </p>
          <Link className="text-sm font-medium underline" href="/vendor/profile">
            Complete matching preferences
          </Link>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-6" data-testid="match-rails">
      {sections.map((section) => {
        const result = results[section]
        if (!result || result.status !== "ready" || result.items.length === 0) return null
        const label = VENDOR_MATCH_SECTION_LABELS[section]
        return (
          <section key={section} data-testid={`match-rail-${section}`}>
            <SectionHeader title={label.title} />
            <p className="mb-3 text-sm text-muted-foreground">{label.subtitle}</p>
            <div className="grid gap-4 sm:grid-cols-2">
              {result.items.map((item, index) =>
                item.kind === "tour" ? (
                  <TourMatchCard key={`tour-${item.id}`} tour={item} />
                ) : (
                  <OpportunityMatchCard key={item.event.id} item={item} section={section} position={index + 1} />
                ),
              )}
            </div>
          </section>
        )
      })}
    </div>
  )
}

function OpportunityMatchCard({
  item,
  section,
  position,
}: {
  item: MatchedOpportunity
  section: VendorMatchSection
  position: number
}) {
  return (
    <div className="flex flex-col gap-2">
      {item.match && (
        <div className="flex flex-wrap items-center gap-2 text-xs" data-testid="match-reasons">
          <Badge className={BAND_STYLES[item.match.band]}>
            <Sparkles data-icon="inline-start" />
            {item.match.bandLabel}
          </Badge>
          {item.match.reasons.slice(0, 2).map((reason) => (
            <span key={reason} className="text-muted-foreground">
              {reason}
            </span>
          ))}
          {item.moreDates > 0 && (
            <span className="text-muted-foreground">
              +{item.moreDates} more date{item.moreDates === 1 ? "" : "s"}
            </span>
          )}
        </div>
      )}
      <OpportunityCard
        event={item.event}
        onCardClick={() =>
          trackLnkupEvent({
            event: "recommendation_clicked",
            source: `match_${section}`,
            opportunityId: String(item.event.opportunityId ?? item.event.id),
            metadata: { band: item.match?.band ?? "none", section, position },
          })
        }
      />
    </div>
  )
}

function TourMatchCard({ tour }: { tour: MatchedTour }) {
  const fee =
    tour.feeMax > 0
      ? tour.feeMin === tour.feeMax
        ? `$${tour.feeMin}`
        : `$${tour.feeMin}–$${tour.feeMax}`
      : null
  return (
    <Card className="rounded-2xl" data-testid="match-tour-card">
      <CardContent className="flex flex-col gap-2 py-4">
        <Badge variant="outline" className="w-fit">
          <Route data-icon="inline-start" />
          {tour.type === "series" ? "Recurring market" : "Tour"}
        </Badge>
        <p className="text-base font-semibold">{tour.title}</p>
        {tour.reasons[0] && <p className="text-sm">{tour.reasons[0]}</p>}
        {tour.places.length > 0 && (
          <p className="flex items-center gap-1 text-sm text-muted-foreground">
            <MapPin className="size-4" />
            {tour.places.slice(0, 3).join(" · ")}
          </p>
        )}
        <p className="text-xs text-muted-foreground">
          {tour.nextDate ? `Next: ${tour.nextDate}` : "Dates TBA"}
          {fee ? ` · ${fee}` : ""}
        </p>
        <p className="text-xs text-muted-foreground">
          Each stop has its own application, booth inventory and payment.
        </p>
        <Link className="text-sm font-medium underline" href={`/vendor/tours/${tour.id}`}>
          View stops
        </Link>
      </CardContent>
    </Card>
  )
}
