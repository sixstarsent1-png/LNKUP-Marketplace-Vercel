import { isLiveApiEnabled } from "@/lib/api/config"
import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { mapWpToOpportunity } from "@/lib/api/marketplace"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpOpportunityItem } from "@/lib/api/types"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_FAVORITES } from "@/lib/data/fixtures"

export type VendorFavoritesResult = {
  items: MarketplaceOpportunity[]
  opportunityIds: number[]
}

type WpFavoritesResponse = {
  favorites?: {
    opportunities?: number[]
    events?: number[]
    organizers?: number[]
  }
  items?: WpOpportunityItem[]
}

const mockFavoriteIds = new Set<number>(
  MOCK_FAVORITES.map((i) => i.opportunityId ?? 0).filter(Boolean),
)

const FAVORITES_CACHE_PREFIX = "vendor.favorites:"

export async function fetchVendorFavorites(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<VendorFavoritesResult> {
  if (!isLiveApiEnabled()) return loadVendorFavorites(auth)
  return cachedRequest(
    `${FAVORITES_CACHE_PREFIX}${auth?.username ?? "anon"}`,
    () => loadVendorFavorites(auth),
    { ttlMs: 30_000, ...options },
  )
}

async function loadVendorFavorites(auth?: WpAuth): Promise<VendorFavoritesResult> {
  if (!isLiveApiEnabled()) {
    const items = MOCK_FAVORITES.filter(
      (i) => i.opportunityId && mockFavoriteIds.has(i.opportunityId),
    ).map((i) => ({ ...i }))
    return {
      items,
      opportunityIds: [...mockFavoriteIds],
    }
  }

  const data = await wpFetchJson<WpFavoritesResponse>("/marketplace/favorites", { auth })
  const items = (data.items ?? [])
    .map(mapWpToOpportunity)
    .filter((item): item is MarketplaceOpportunity => Boolean(item))

  return {
    items,
    opportunityIds: data.favorites?.opportunities ?? items.map((i) => i.opportunityId ?? 0).filter(Boolean),
  }
}

/** Toggle favorite. Returns true if now favorited. */
export async function toggleVendorFavorite(
  opportunityId: number,
  auth?: WpAuth,
): Promise<boolean> {
  if (!isLiveApiEnabled()) {
    if (mockFavoriteIds.has(opportunityId)) {
      mockFavoriteIds.delete(opportunityId)
      return false
    }
    mockFavoriteIds.add(opportunityId)
    return true
  }

  try {
    const result = await wpFetchJson<{ favorited?: boolean }>("/marketplace/favorites", {
      method: "POST",
      auth,
      body: { type: "opportunities", id: opportunityId },
    })
    return Boolean(result.favorited)
  } finally {
    invalidateCache(FAVORITES_CACHE_PREFIX)
  }
}

/**
 * Put a favorite into the wanted state. The endpoint only toggles, so if the server's answer
 * differs (the list was stale, or another device changed it) toggle once more. Returns the
 * server's final state.
 */
export async function setVendorFavorite(
  opportunityId: number,
  wanted: boolean,
  auth?: WpAuth,
): Promise<boolean> {
  const first = await toggleVendorFavorite(opportunityId, auth)
  if (first === wanted) return first
  return toggleVendorFavorite(opportunityId, auth)
}
