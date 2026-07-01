"use client"

import { Suspense, useState } from "react"
import { Search, ArrowDownWideNarrow, Bookmark, TrendingUp, SearchX } from "lucide-react"
import { events, vendorCategories } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { FilterDrawer } from "@/components/marketplace/filter-drawer"
import { PanelContent, SkeletonList, EmptyState } from "@/components/marketplace/states"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

function FindEventsContent() {
  const { state, retry } = usePanelState()
  const [query, setQuery] = useState("")
  const [activeChip, setActiveChip] = useState<string | null>(null)

  const filtered = events.filter((e) => {
    const matchesQuery =
      query === "" ||
      e.name.toLowerCase().includes(query.toLowerCase()) ||
      e.city.toLowerCase().includes(query.toLowerCase())
    const matchesChip = !activeChip || e.categoriesNeeded.includes(activeChip)
    return matchesQuery && matchesChip && e.status !== "closed"
  })

  const isEmpty = state === "content" && filtered.length === 0

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="Find Events" subtitle="Apply for free. Pay only when accepted." />

      <div className="sticky top-14 z-30 -mx-4 flex items-center gap-2 bg-background/80 px-4 py-2 backdrop-blur md:top-0 md:mx-0 md:px-0">
        <div className="relative flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search events or cities…"
            className="h-11 rounded-xl pl-9"
          />
        </div>
        <FilterDrawer />
        <Button variant="outline" size="icon" aria-label="Sort">
          <ArrowDownWideNarrow />
        </Button>
      </div>

      <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:px-0">
        {vendorCategories.map((c) => (
          <button
            key={c}
            type="button"
            onClick={() => setActiveChip(activeChip === c ? null : c)}
            className={cn(
              "shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
              activeChip === c
                ? "border-primary bg-primary text-primary-foreground"
                : "border-border bg-background hover:bg-muted",
            )}
          >
            {c}
          </button>
        ))}
      </div>

      <PanelContent
        state={isEmpty ? "empty" : state}
        retry={retry}
        empty={
          <EmptyState
            icon={SearchX}
            title="No events match your search"
            description="Try clearing your filters or searching a different city."
            action={
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setQuery("")
                  setActiveChip(null)
                }}
              >
                Clear filters
              </Button>
            }
          />
        }
      >
        <section>
          <SectionHeader title={`${filtered.length} opportunities`} />
          <div className="grid gap-4 md:grid-cols-2">
            {filtered.map((e) => (
              <OpportunityCard key={e.id} event={e} />
            ))}
          </div>
        </section>

        <div className="grid grid-cols-2 gap-3">
          <Button variant="outline" className="h-auto flex-col items-start gap-1 rounded-2xl p-4">
            <Bookmark className="size-5" />
            <span className="text-sm font-semibold">Saved events</span>
            <span className="text-xs text-muted-foreground">3 saved</span>
          </Button>
          <Button variant="outline" className="h-auto flex-col items-start gap-1 rounded-2xl p-4">
            <TrendingUp className="size-5" />
            <span className="text-sm font-semibold">Trending</span>
            <span className="text-xs text-muted-foreground">Hot this week</span>
          </Button>
        </div>
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
