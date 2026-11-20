import type { EventItem } from "@/lib/data"
import { mapOpportunityList } from "@/lib/api/map-opportunity"
import type { WpOpportunityItem } from "@/lib/api/types"

export type MarketplaceRailId =
  | "recommended"
  | "near_you"
  | "closing_soon"
  | "trending"
  | "saved_matches"
  | "recently_added"
  | "most_saved"

export type MarketplaceRail = {
  id: MarketplaceRailId
  title: string
  subtitle?: string
  items: EventItem[]
}

export type WpSectionResponse = {
  section?: string
  items?: WpOpportunityItem[]
  html?: string
}

export function mapSectionItems(data: WpSectionResponse | undefined): EventItem[] {
  return mapOpportunityList(data?.items)
}

export const RAIL_LABELS: Record<MarketplaceRailId, { title: string; subtitle?: string }> = {
  recommended: { title: "Recommended for you", subtitle: "Based on your profile and activity" },
  near_you: { title: "Near you", subtitle: "Opportunities in your service area" },
  closing_soon: { title: "Closing soon", subtitle: "Deadlines approaching" },
  trending: { title: "Trending", subtitle: "Popular with vendors this week" },
  saved_matches: { title: "Saved search matches", subtitle: "New results for your alerts" },
  recently_added: { title: "New this week", subtitle: "Fresh opportunities" },
  most_saved: { title: "Most saved", subtitle: "Popular with vendors" },
}
