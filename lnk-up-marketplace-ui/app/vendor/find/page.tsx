"use client"

import { Suspense, useEffect, useMemo, useRef, useState } from "react"
import Link from "next/link"
import dynamic from "next/dynamic"
import { Bookmark } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"
import { opportunityMatchesId } from "@/lib/api/marketplace-discovery"
import { useMarketplaceFilters } from "@/lib/hooks/use-marketplace-filters"
import { useMarketplaceSearch } from "@/lib/hooks/use-marketplace-search"
import { useMarketplaceRails } from "@/lib/hooks/use-marketplace-rails"
import { useAuth } from "@/lib/hooks/use-auth"
import { useFavorites } from "@/lib/hooks/use-favorites"
import { useSavedSearches } from "@/lib/hooks/use-saved-searches"
import { usePanelState } from "@/lib/use-panel-state"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { SectionHeader } from "@/components/marketplace/page-header"
import { ExecutiveHero } from "@/components/vendor/executive-hero"
import { Surface } from "@/components/vendor/surface"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { OpportunityRailList } from "@/components/marketplace/opportunity-rail"
import { FindToolbar } from "@/components/marketplace/find-toolbar"
import { ActiveFilterChips } from "@/components/marketplace/active-filter-chips"
import { ZeroResultsPanel } from "@/components/marketplace/zero-results-panel"
import { SaveSearchButton } from "@/components/vendor/save-search-dialog"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import type { PanelState } from "@/lib/use-panel-state"
import type { SavedSearchFilters } from "@/lib/api/saved-searches"

const DiscoveryMap = dynamic(
  () => import("@/components/marketplace/discovery-map").then((m) => m.DiscoveryMap),
  { ssr: false, loading: () => <SkeletonList count={1} /> },
)

function FindEventsContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { isAuthenticated } = useAuth()
  const { items: favorites } = useFavorites()
  const { createSearch, saving } = useSavedSearches()
  const { filters, updateFilter, setFilters, clearFilters, removeChip, hasFilters } =
    useMarketplaceFilters()
  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const deepLinkTracked = useRef(false)

  const search = useMarketplaceSearch(filters)
  const { rails, loading: railsLoading } = useMarketplaceRails("find", !hasFilters, filters)

  const savedSearchFilters: SavedSearchFilters = useMemo(
    () => ({
      q: filters.query,
      city: filters.city,
      state: filters.state,
      category: filters.category,
      fee_min: filters.feeMin,
      fee_max: filters.feeMax,
      date_from: filters.dateFrom,
      date_to: filters.dateTo,
    }),
    [filters],
  )

  const results = search.items
  const total = search.total
  const isMapView = filters.view === "map"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live || hasFilters) {
      if (search.loading) return "loading"
      if (search.error) return "error"
      if (results.length === 0) return "empty"
    }
    return "content"
  }, [qaState, live, hasFilters, search.loading, search.error, results.length])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    search.retry()
  }

  const showExploreRails = !hasFilters && panelState === "content"
  const showZeroResults = panelState === "empty" && hasFilters
  const deepOpportunityId = filters.opportunityId

  useEffect(() => {
    if (!deepOpportunityId || deepLinkTracked.current) return
    deepLinkTracked.current = true
    trackLnkupEvent({
      event: "deep_opportunity_opened",
      source: "vendor_find",
      opportunityId: deepOpportunityId,
      metadata: { autoApply: filters.autoApply ? "1" : "0" },
    })
  }, [deepOpportunityId, filters.autoApply])

  function handleViewChange(view: "list" | "map") {
    if (view === "map") {
      trackLnkupEvent({ event: "map_view_opened", source: "vendor_find" })
    }
    updateFilter({ view })
  }

  function handleClearFilters() {
    trackLnkupEvent({ event: "filter_cleared", source: "vendor_find" })
    clearFilters()
  }

  return (
    <div className="flex flex-col gap-5">
      <ExecutiveHero
        title="Find Events"
        subtitle="Apply for free. Pay only when accepted."
        metrics={
          hasFilters || live
            ? [{ label: "Results", value: String(total) }]
            : favorites.length > 0
              ? [{ label: "Saved", value: String(favorites.length) }]
              : undefined
        }
        secondaryCta={
          favorites.length > 0
            ? { label: "Saved events", href: "/vendor/favorites" }
            : undefined
        }
      />

      <Surface
        variant="secondary"
        className="sticky top-[3.25rem] z-30 flex flex-col gap-3 bg-card/95 backdrop-blur md:top-0"
      >
        <FindToolbar
          filters={filters}
          resultCount={total}
          onQueryChange={(query) => updateFilter({ query })}
          onSortChange={(sort) => updateFilter({ sort })}
          onFiltersApply={(patch) => setFilters({ ...filters, ...patch, page: 1 })}
          onViewChange={handleViewChange}
          onNearMe={(lat, lng, radiusMiles) =>
            updateFilter({ lat, lng, radiusMiles, sort: "event_date" })
          }
          onCityFallback={(city) =>
            updateFilter({ city, lat: undefined, lng: undefined, radiusMiles: undefined })
          }
        />

        <div className="flex flex-wrap items-center gap-2">
          <SaveSearchButton
            filters={savedSearchFilters}
            isAuthenticated={isAuthenticated}
            saving={saving}
            open={saveDialogOpen}
            onOpenChange={setSaveDialogOpen}
            onSave={(name, frequency) => createSearch(name, savedSearchFilters, frequency)}
          />
          <Button
            variant="ghost"
            size="sm"
            className="min-h-11"
            nativeButton={false}
            render={<Link href="/notifications/preferences" />}
          >
            Manage alerts
          </Button>
        </div>

        <ActiveFilterChips filters={filters} onRemove={removeChip} onClear={handleClearFilters} />
      </Surface>

      <PanelContent state={panelState} retry={handleRetry}>
        {showZeroResults ? (
          <ZeroResultsPanel
            filters={filters}
            onClear={handleClearFilters}
            onRemoveChip={removeChip}
            onSaveSearch={() => setSaveDialogOpen(true)}
            onCategorySelect={(category) => updateFilter({ category, page: 1 })}
            fallbackRails={rails}
            railsLoading={railsLoading}
          />
        ) : (
          <>
            {isMapView ? (
              <DiscoveryMap city={filters.city} state={filters.state} />
            ) : (
              <Surface variant="primary" className="flex flex-col gap-4">
                <SectionHeader title={`${total} opportunities`} />
                <div className="grid gap-4 md:grid-cols-2">
                  {results.map((e) => (
                    <OpportunityCard
                      key={e.id}
                      event={e}
                      highlighted={opportunityMatchesId(e, deepOpportunityId)}
                      autoOpenApply={Boolean(
                        filters.autoApply && opportunityMatchesId(e, deepOpportunityId),
                      )}
                    />
                  ))}
                </div>
              </Surface>
            )}

            {showExploreRails && (
              <div className="flex flex-col gap-6">
                <Button
                  variant="outline"
                  className="h-auto w-full flex-row items-center justify-start gap-3 rounded-2xl p-4 sm:w-auto"
                  nativeButton={false}
                  render={<Link href="/vendor/favorites" />}
                >
                  <Bookmark className="size-5" />
                  <span className="flex flex-col items-start gap-0.5">
                    <span className="text-sm font-semibold">Saved events</span>
                    <span className="text-xs text-muted-foreground">
                      {favorites.length} saved
                    </span>
                  </span>
                </Button>
                <OpportunityRailList rails={rails} loading={railsLoading} />
              </div>
            )}
          </>
        )}
      </PanelContent>
    </div>
  )
}

export default function FindEventsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <FindEventsContent />
    </Suspense>
  )
}
