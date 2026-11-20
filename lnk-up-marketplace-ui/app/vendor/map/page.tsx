"use client"

import { Suspense } from "react"
import dynamic from "next/dynamic"
import { PageHeader } from "@/components/marketplace/page-header"
import { useMarketplaceFilters } from "@/lib/hooks/use-marketplace-filters"
import { FindToolbar } from "@/components/marketplace/find-toolbar"
import { ActiveFilterChips } from "@/components/marketplace/active-filter-chips"
import { SkeletonList } from "@/components/marketplace/states"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

const DiscoveryMap = dynamic(
  () => import("@/components/marketplace/discovery-map").then((m) => m.DiscoveryMap),
  { ssr: false, loading: () => <SkeletonList count={1} /> },
)

function VendorMapContent() {
  const { filters, updateFilter, setFilters, clearFilters, removeChip } = useMarketplaceFilters()

  return (
    <div className="flex flex-col gap-5" data-testid="vendor-map-page">
      <PageHeader title="Map discovery" subtitle="Find event opportunities near you." />
      <FindToolbar
        filters={{ ...filters, view: "map" }}
        onQueryChange={(query) => updateFilter({ query })}
        onSortChange={(sort) => updateFilter({ sort })}
        onFiltersApply={(patch) => setFilters({ ...filters, ...patch, view: "map" })}
        onViewChange={(view) => {
          if (view === "list") {
            window.location.href = "/vendor/find"
          }
        }}
        onNearMe={(lat, lng, radiusMiles) =>
          updateFilter({ lat, lng, radiusMiles, view: "map" })
        }
        onCityFallback={(city) => updateFilter({ city, lat: undefined, lng: undefined })}
      />
      <ActiveFilterChips filters={filters} onRemove={removeChip} onClear={clearFilters} />
      <DiscoveryMap
        city={filters.city}
        state={filters.state}
        onPinClick={() => trackLnkupEvent({ event: "map_pin_clicked", source: "vendor_map" })}
      />
    </div>
  )
}

export default function VendorMapPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorMapContent />
    </Suspense>
  )
}
