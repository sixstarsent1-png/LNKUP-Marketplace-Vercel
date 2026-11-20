"use client"

import { X } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { filtersToChips, type MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"

export function ActiveFilterChips({
  filters,
  onRemove,
  onClear,
}: {
  filters: MarketplaceSearchFilters
  onRemove: (key: string) => void
  onClear: () => void
}) {
  const chips = filtersToChips(filters)
  if (chips.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="active-filter-chips">
      {chips.map((chip) => (
        <Badge
          key={chip.key}
          variant="secondary"
          className="gap-1 rounded-full py-1 pr-1 pl-2.5"
        >
          {chip.label}
          <button
            type="button"
            aria-label={`Remove ${chip.label}`}
            onClick={() => onRemove(chip.value)}
            className="rounded-full p-0.5 hover:bg-muted"
          >
            <X className="size-3" />
          </button>
        </Badge>
      ))}
      <Button variant="ghost" size="sm" onClick={onClear} data-testid="clear-filters">
        Clear filters
      </Button>
    </div>
  )
}
