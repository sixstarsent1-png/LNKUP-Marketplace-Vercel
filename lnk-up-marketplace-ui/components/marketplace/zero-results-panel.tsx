"use client"

import Link from "next/link"
import { SearchX } from "lucide-react"
import { useEffect } from "react"
import { vendorCategories } from "@/lib/data"
import { LAUNCH_MARKETS } from "@/lib/api/markets"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"
import { ActiveFilterChips } from "@/components/marketplace/active-filter-chips"
import { EmptyState } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { OpportunityRailList } from "@/components/marketplace/opportunity-rail"
import type { MarketplaceRail } from "@/lib/api/marketplace-sections"

export function ZeroResultsPanel({
  filters,
  onClear,
  onRemoveChip,
  onSaveSearch,
  onCategorySelect,
  fallbackRails,
  railsLoading,
}: {
  filters: MarketplaceSearchFilters
  onClear: () => void
  onRemoveChip: (key: string) => void
  onSaveSearch?: () => void
  onCategorySelect?: (category: string) => void
  fallbackRails?: MarketplaceRail[]
  railsLoading?: boolean
}) {
  useEffect(() => {
    trackLnkupEvent({
      event: "zero_results_shown",
      source: "vendor_find",
      metadata: {
        city: filters.city ?? null,
        category: filters.category ?? null,
      },
    })
  }, [filters.city, filters.category])

  const nearbyMarkets = LAUNCH_MARKETS.filter(
    (m) => m.city.toLowerCase() !== (filters.city ?? "").toLowerCase(),
  ).slice(0, 4)

  const broaderCategories = vendorCategories
    .filter((c) => c !== filters.category)
    .slice(0, 4)

  return (
    <div className="flex flex-col gap-6" data-testid="zero-results-panel">
      <EmptyState
        icon={SearchX}
        title="No matching opportunities found"
        description="Try another city, remove filters, or save this search for alerts."
        action={
          <div className="flex flex-wrap justify-center gap-2">
            <Button variant="outline" size="sm" onClick={onClear} data-testid="zero-clear-filters">
              Clear filters
            </Button>
            {onSaveSearch && (
              <Button size="sm" onClick={onSaveSearch}>
                Save this search
              </Button>
            )}
          </div>
        }
      />

      <ActiveFilterChips filters={filters} onRemove={onRemoveChip} onClear={onClear} />

      {nearbyMarkets.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-semibold">Try nearby markets</h3>
          <div className="flex flex-wrap gap-2">
            {nearbyMarkets.map((m) => (
              <Button
                key={m.slug}
                variant="outline"
                size="sm"
                nativeButton={false}
                render={<Link href={`/markets/${m.slug}`} />}
              >
                {m.city}, {m.state}
              </Button>
            ))}
          </div>
        </div>
      )}

      {broaderCategories.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-semibold">Browse broader categories</h3>
          <div className="flex flex-wrap gap-2">
            {broaderCategories.map((c) => (
              <Button
                key={c}
                variant="outline"
                size="sm"
                onClick={() => (onCategorySelect ? onCategorySelect(c) : onRemoveChip("category"))}
              >
                {c}
              </Button>
            ))}
          </div>
        </div>
      )}

      <div className="rounded-2xl border border-dashed p-4 text-center text-sm text-muted-foreground">
        <p>Join early and get alerts when new opportunities match your search.</p>
        <Button className="mt-3" size="sm" nativeButton={false} render={<Link href="/vendors" />}>
          Join as a Vendor
        </Button>
      </div>

      <p className="text-center text-xs text-muted-foreground">
        Know an organizer? Invite them to post opportunities on LNKup.
      </p>

      {fallbackRails && fallbackRails.length > 0 && (
        <OpportunityRailList rails={fallbackRails} loading={railsLoading} />
      )}
    </div>
  )
}
