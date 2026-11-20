import Link from "next/link"
import type { EventItem } from "@/lib/data"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { SectionHeader } from "@/components/marketplace/page-header"
import { SkeletonCard } from "@/components/marketplace/states"

export function OpportunityRail({
  title,
  subtitle,
  items,
  href,
  loading,
  railId,
}: {
  title: string
  subtitle?: string
  items: EventItem[]
  href?: string
  loading?: boolean
  railId?: string
}) {
  if (loading) {
    return (
      <section data-testid="opportunity-rail-loading">
        <SectionHeader title={title} href={href} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SkeletonCard />
          <SkeletonCard />
        </div>
      </section>
    )
  }

  if (!items.length) return null

  return (
    <section data-testid="opportunity-rail">
      <SectionHeader title={title} href={href} linkLabel={href ? "See all" : undefined} />
      {subtitle && <p className="mb-3 text-sm text-muted-foreground">{subtitle}</p>}
      <div className="grid gap-4 sm:grid-cols-2">
        {items.map((event) => (
          <OpportunityCard
            key={event.id}
            event={event}
            onCardClick={() =>
              trackLnkupEvent({
                event: "recommendation_clicked",
                source: railId ?? "opportunity_rail",
                opportunityId: String(event.opportunityId ?? event.id),
                metadata: { rail: title },
              })
            }
          />
        ))}
      </div>
    </section>
  )
}

export function OpportunityRailList({
  rails,
  loading,
  findHref = "/vendor/find",
}: {
  rails: Array<{ id: string; title: string; subtitle?: string; items: EventItem[] }>
  loading?: boolean
  findHref?: string
}) {
  if (loading && rails.length === 0) {
    return (
      <OpportunityRail title="Loading opportunities" items={[]} loading />
    )
  }

  return (
    <>
      {rails.map((rail) => (
        <OpportunityRail
          key={rail.id}
          railId={rail.id}
          title={rail.title}
          subtitle={rail.subtitle}
          items={rail.items}
          href={findHref}
        />
      ))}
    </>
  )
}
