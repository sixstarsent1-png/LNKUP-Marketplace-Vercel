"use client"

import { useState } from "react"
import { SlidersHorizontal } from "lucide-react"
import { vendorCategories } from "@/lib/data"
import {
  type MarketplaceSearchFilters,
  feeRangeLabelToFilters,
  feeFiltersToRangeLabel,
  attendanceLabelToFilters,
  attendanceFiltersToLabel,
} from "@/lib/api/marketplace-discovery"
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
  SheetClose,
} from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { cn } from "@/lib/utils"

const feeRanges = ["Under $150", "$150–$250", "$250–$400", "$400+"]
const attendanceRanges = ["Under 3k", "3k–6k", "6k–10k", "10k+"]
const eventTypes = ["Festival", "Market", "Fair", "Expo", "Pop-up"]

type FilterDrawerProps = {
  filters: MarketplaceSearchFilters
  onApply: (patch: Partial<MarketplaceSearchFilters>) => void
  resultCount?: number
}

export function FilterDrawer({ filters, onApply, resultCount }: FilterDrawerProps) {
  const [draft, setDraft] = useState<MarketplaceSearchFilters>(filters)
  const [open, setOpen] = useState(false)

  const feeLabel = feeFiltersToRangeLabel(draft)
  const attendanceLabel = attendanceFiltersToLabel(draft)

  function syncOpen(next: boolean) {
    if (next) setDraft(filters)
    setOpen(next)
  }

  function apply() {
    onApply(draft)
    setOpen(false)
  }

  function clearDraft() {
    const cleared: Partial<MarketplaceSearchFilters> = {
      category: undefined,
      eventType: undefined,
      city: undefined,
      state: undefined,
      feeMin: undefined,
      feeMax: undefined,
      attendanceMin: undefined,
      attendanceMax: undefined,
      indoor: undefined,
      outdoor: undefined,
      verifiedOrganizerOnly: undefined,
      documentsRequired: undefined,
      dateFrom: undefined,
      dateTo: undefined,
    }
    setDraft((d) => ({ ...d, ...cleared }))
  }

  const activeCount =
    (draft.category ? 1 : 0) +
    (feeLabel ? 1 : 0) +
    (attendanceLabel ? 1 : 0) +
    (draft.eventType ? 1 : 0) +
    (draft.city ? 1 : 0) +
    (draft.verifiedOrganizerOnly ? 1 : 0) +
    (draft.documentsRequired ? 1 : 0) +
    (draft.indoor ? 1 : 0) +
    (draft.outdoor ? 1 : 0)

  return (
    <Sheet open={open} onOpenChange={syncOpen}>
      <SheetTrigger
        render={
          <Button variant="outline" size="icon" aria-label="Filters" data-testid="filter-drawer-trigger">
            <SlidersHorizontal />
          </Button>
        }
      />
      <SheetContent side="bottom" className="max-h-[85vh] rounded-t-3xl" data-testid="filter-drawer">
        <SheetHeader>
          <SheetTitle>Filters</SheetTitle>
        </SheetHeader>

        <div className="flex flex-col gap-5 overflow-y-auto px-4">
          <div className="grid gap-2 sm:grid-cols-2">
            <div>
              <Label htmlFor="filter-city">City</Label>
              <Input
                id="filter-city"
                value={draft.city ?? ""}
                onChange={(e) => setDraft((d) => ({ ...d, city: e.target.value || undefined }))}
                placeholder="Charlotte"
                data-testid="filter-city"
              />
            </div>
            <div>
              <Label htmlFor="filter-state">State</Label>
              <Input
                id="filter-state"
                value={draft.state ?? ""}
                onChange={(e) => setDraft((d) => ({ ...d, state: e.target.value || undefined }))}
                placeholder="NC"
              />
            </div>
          </div>

          <FilterGroup title="Category">
            {vendorCategories.slice(0, 9).map((c) => (
              <Chip
                key={c}
                active={draft.category === c}
                onClick={() =>
                  setDraft((d) => ({ ...d, category: d.category === c ? undefined : c }))
                }
              >
                {c}
              </Chip>
            ))}
          </FilterGroup>

          <FilterGroup title="Event type">
            {eventTypes.map((t) => (
              <Chip
                key={t}
                active={draft.eventType === t}
                onClick={() =>
                  setDraft((d) => ({ ...d, eventType: d.eventType === t ? undefined : t }))
                }
              >
                {t}
              </Chip>
            ))}
          </FilterGroup>

          <FilterGroup title="Vendor fee">
            {feeRanges.map((f) => (
              <Chip
                key={f}
                active={feeLabel === f}
                onClick={() => {
                  const next = feeLabel === f ? {} : feeRangeLabelToFilters(f)
                  setDraft((d) => ({ ...d, ...next, feeMin: next.feeMin, feeMax: next.feeMax }))
                  if (feeLabel === f) {
                    setDraft((d) => ({ ...d, feeMin: undefined, feeMax: undefined }))
                  }
                }}
              >
                {f}
              </Chip>
            ))}
          </FilterGroup>

          <FilterGroup title="Expected attendance">
            {attendanceRanges.map((a) => (
              <Chip
                key={a}
                active={attendanceLabel === a}
                onClick={() => {
                  if (attendanceLabel === a) {
                    setDraft((d) => ({ ...d, attendanceMin: undefined, attendanceMax: undefined }))
                  } else {
                    const next = attendanceLabelToFilters(a)
                    setDraft((d) => ({ ...d, ...next }))
                  }
                }}
              >
                {a}
              </Chip>
            ))}
          </FilterGroup>

          <div className="grid gap-2 sm:grid-cols-2">
            <div>
              <Label htmlFor="date-from">Event from</Label>
              <Input
                id="date-from"
                type="date"
                value={draft.dateFrom ?? ""}
                onChange={(e) => setDraft((d) => ({ ...d, dateFrom: e.target.value || undefined }))}
              />
            </div>
            <div>
              <Label htmlFor="date-to">Event to</Label>
              <Input
                id="date-to"
                type="date"
                value={draft.dateTo ?? ""}
                onChange={(e) => setDraft((d) => ({ ...d, dateTo: e.target.value || undefined }))}
              />
            </div>
          </div>

          <FilterGroup title="More">
            <Chip
              active={Boolean(draft.verifiedOrganizerOnly)}
              onClick={() =>
                setDraft((d) => ({ ...d, verifiedOrganizerOnly: !d.verifiedOrganizerOnly }))
              }
            >
              Verified organizer
            </Chip>
            <Chip
              active={Boolean(draft.documentsRequired)}
              onClick={() =>
                setDraft((d) => ({ ...d, documentsRequired: !d.documentsRequired }))
              }
            >
              Documents required
            </Chip>
            <Chip
              active={Boolean(draft.indoor)}
              onClick={() => setDraft((d) => ({ ...d, indoor: !d.indoor }))}
            >
              Indoor
            </Chip>
            <Chip
              active={Boolean(draft.outdoor)}
              onClick={() => setDraft((d) => ({ ...d, outdoor: !d.outdoor }))}
            >
              Outdoor
            </Chip>
          </FilterGroup>
        </div>

        <SheetFooter className="flex-row gap-2">
          <Button variant="outline" className="flex-1" onClick={clearDraft}>
            Clear
          </Button>
          <Button className="flex-1" onClick={apply} data-testid="filter-apply">
            Show results{resultCount !== undefined ? ` (${resultCount})` : activeCount > 0 ? ` (${activeCount})` : ""}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

function FilterGroup({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <h4 className="text-sm font-semibold">{title}</h4>
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  )
}

function Chip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
        active
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-background text-foreground hover:bg-muted",
      )}
    >
      {children}
    </button>
  )
}
