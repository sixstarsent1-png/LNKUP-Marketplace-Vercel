import { mapOpportunity } from "@/lib/api/map-opportunity"
import type { WpFavoritesResponse } from "@/lib/api/types"
import type { EventItem } from "@/lib/data"

export type FavoritesState = {
  opportunityIds: number[]
  items: EventItem[]
}

export function mapFavoritesResponse(data: WpFavoritesResponse): FavoritesState {
  const opportunityIds = (data.favorites?.opportunities ?? []).map((id) => Number(id)).filter(Boolean)
  const items = (data.items ?? [])
    .map((row) => mapOpportunity(row))
    .filter((item): item is EventItem => item !== null)
  return { opportunityIds, items }
}
