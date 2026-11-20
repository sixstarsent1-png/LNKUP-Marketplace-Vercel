import type { WpOpportunityItem } from "@/lib/api/types"
import { mapWpToOpportunity, opportunityToEventItem } from "@/lib/api/marketplace-discovery"
import type { EventItem } from "@/lib/data"

/** @deprecated Use mapWpToOpportunity from marketplace-discovery */
export function mapOpportunity(item: WpOpportunityItem): EventItem | null {
  const opp = mapWpToOpportunity(item)
  if (!opp) return null
  return opportunityToEventItem(opp)
}

export function mapOpportunityList(items: WpOpportunityItem[] | undefined): EventItem[] {
  if (!items?.length) return []
  return items.map(mapOpportunity).filter((item): item is EventItem => item !== null)
}
