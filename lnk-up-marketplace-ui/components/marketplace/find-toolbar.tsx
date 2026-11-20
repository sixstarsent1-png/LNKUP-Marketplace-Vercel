"use client"

import { Search, ArrowDownWideNarrow } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import type { MarketplaceSearchFilters, MarketplaceSort } from "@/lib/api/marketplace-discovery"
import { FilterDrawer } from "@/components/marketplace/filter-drawer"
import { MapListToggle } from "@/components/marketplace/map-list-toggle"
import { NearMeButton } from "@/components/marketplace/near-me-button"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

const SORT_OPTIONS: { value: MarketplaceSort; label: string }[] = [
  { value: "recommended", label: "Recommended" },
  { value: "newest", label: "Newest" },
  { value: "closing_soon", label: "Closing soon" },
  { value: "event_date", label: "Event date" },
  { value: "fee_low", label: "Lowest vendor fee" },
  { value: "attendance_high", label: "Highest attendance" },
]

export function FindToolbar({
  filters,
  onQueryChange,
  onSortChange,
  onFiltersApply,
  onViewChange,
  onNearMe,
  onCityFallback,
  resultCount,
}: {
  filters: MarketplaceSearchFilters
  onQueryChange: (query: string) => void
  onSortChange: (sort: MarketplaceSort) => void
  onFiltersApply: (patch: Partial<MarketplaceSearchFilters>) => void
  onViewChange: (view: "list" | "map") => void
  onNearMe: (lat: number, lng: number, radius: number) => void
  onCityFallback: (city: string) => void
  resultCount?: number
}) {
  return (
    <div className="flex flex-col gap-3" data-testid="find-toolbar">
      <div className="sticky top-14 z-30 -mx-4 flex items-center gap-2 bg-background/80 px-4 py-2 backdrop-blur md:top-0 md:mx-0 md:px-0">
        <div className="relative flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={filters.query ?? ""}
            onChange={(e) => onQueryChange(e.target.value)}
            onBlur={() => {
              trackLnkupEvent({
                event: "search_performed",
                source: "vendor_find",
                metadata: {
                  city: filters.city ?? null,
                  category: filters.category ?? null,
                  resultCount: resultCount ?? null,
                },
              })
            }}
            placeholder="Search by city, category, or event type"
            className="h-11 rounded-xl pl-9"
            data-testid="find-search-input"
          />
        </div>
        <FilterDrawer
          filters={filters}
          onApply={(patch) => {
            trackLnkupEvent({ event: "filter_changed", source: "vendor_find" })
            onFiltersApply(patch)
          }}
          resultCount={resultCount}
        />
        <Select
          value={filters.sort ?? "recommended"}
          onValueChange={(v) => onSortChange(v as MarketplaceSort)}
        >
          <SelectTrigger className="h-11 w-11 shrink-0 rounded-xl px-0 [&>span]:sr-only" aria-label="Sort">
            <ArrowDownWideNarrow className="mx-auto size-4" />
          </SelectTrigger>
          <SelectContent>
            {SORT_OPTIONS.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <NearMeButton onLocation={onNearMe} onCityFallback={onCityFallback} />
        <MapListToggle view={filters.view ?? "list"} onChange={onViewChange} />
      </div>
    </div>
  )
}
