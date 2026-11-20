import type { EventItem } from "@/lib/data"
import type { MarketDefinition } from "@/lib/api/markets"
import { events } from "@/lib/data"

export type MarketPageData = {
  market: MarketDefinition
  opportunities: EventItem[]
  total: number
  categories: string[]
  prelaunchMessage?: string
}

export function buildMockMarketPage(market: MarketDefinition): MarketPageData {
  const opportunities = events.filter(
    (e) =>
      e.city.toLowerCase() === market.city.toLowerCase() ||
      e.state === market.state,
  )
  const categories = Array.from(
    new Set(opportunities.flatMap((e) => e.categoriesNeeded)),
  ).slice(0, 8)

  return {
    market,
    opportunities: opportunities.slice(0, 6),
    total: opportunities.length,
    categories,
    prelaunchMessage:
      opportunities.length === 0
        ? "Browse opportunities now. Apply when the marketplace opens."
        : undefined,
  }
}
