import { events } from "@/lib/data"
import type { MarketplaceRail, MarketplaceRailId } from "@/lib/api/marketplace-sections"
import { RAIL_LABELS } from "@/lib/api/marketplace-sections"

function liveEvents() {
  return events.filter((e) => e.status === "live")
}

export function buildMockRails(ids: MarketplaceRailId[]): MarketplaceRail[] {
  const all = liveEvents()
  const rails: MarketplaceRail[] = []

  for (const id of ids) {
    let items = all
    switch (id) {
      case "recommended":
        items = all.slice(0, 4)
        break
      case "near_you":
        items = all.filter((e) => e.city === "Charlotte" || e.city === "Atlanta").slice(0, 4)
        break
      case "closing_soon":
        items = all.filter((e) => e.deadline !== "Rolling").slice(0, 3)
        break
      case "trending":
        items = all.filter((e) => e.trending).slice(0, 4)
        break
      case "recently_added":
        items = all.slice(-3)
        break
      case "saved_matches":
        items = all.slice(1, 4)
        break
      case "most_saved":
        items = all.filter((e) => e.saved).slice(0, 4)
        if (items.length === 0) items = all.slice(2, 5)
        break
    }
    if (items.length === 0) continue
    const labels = RAIL_LABELS[id]
    rails.push({ id, title: labels.title, subtitle: labels.subtitle, items })
  }

  return rails
}
