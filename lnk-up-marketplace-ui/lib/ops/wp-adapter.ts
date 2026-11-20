import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpOpportunityItem, WpSearchResponse } from "@/lib/api/types"
import {
  buildFunnelFromPartial,
  buildLaunchAlerts,
  buildMarketsFromWp,
  buildSearchInsightsFromWp,
  deriveOpportunityStatus,
} from "@/lib/ops/build-alerts"
import {
  buildDefaultExperiments,
  buildDefaultRecommendations,
  buildDefaultSegmentDiagnostics,
  mapExperimentRecommendations,
  mapExperimentSnapshots,
  mapFunnelSegmentDiagnostics,
  prioritizeRecommendations,
} from "@/lib/ops/experimentation-contract"
import { getMockFunnel, getMockSummary } from "@/lib/ops/mock-analytics"
import { getOpsWpAuth } from "@/lib/ops/ops-auth"
import type {
  MarketplaceFunnel,
  OpsAnalyticsMeta,
  OpsAnalyticsRange,
  OpsAnalyticsSummary,
  OpportunityPerformanceItem,
  VendorActivationInsight,
} from "@/lib/ops/types"

type WpMarketplaceAnalytics = {
  searches_30d?: number
  card_clicks_30d?: number
  favorites_30d?: number
  recommendation_ctr?: number
  top_cities?: Record<string, number>
  zero_result_searches?: Record<string, number>
  popular_searches?: string[] | Record<string, number>
  popular_categories?: string[]
  filter_usage?: Record<string, number>
  experiment_snapshots?: unknown[]
  funnel_diagnostics?: unknown[]
  recommendations?: unknown[]
}

type WpGrowthAdminSummary = {
  active_vendors?: number
  avg_profile_score?: number
  goals_in_progress?: number
  recommendations?: unknown[]
}

function buildMeta(
  range: OpsAnalyticsRange,
  partial: boolean,
  unavailableReasons?: string[],
): OpsAnalyticsMeta {
  return {
    live: true,
    mocked: false,
    partial,
    updatedAt: new Date().toISOString(),
    range,
    unavailableReasons,
  }
}

function mapOpportunity(item: WpOpportunityItem): OpportunityPerformanceItem {
  const opportunityId = String(item.opportunity_id ?? item.id ?? "")
  const applications = item.application_count ?? 0
  const paid = item.paid_vendors ?? 0
  const mapped: OpportunityPerformanceItem = {
    opportunityId,
    title: item.name ?? item.event_name ?? "Opportunity",
    city: item.city,
    state: item.state,
    eventDate: item.event_date ?? item.date,
    applications,
    paid,
    approvals: item.approved_vendors,
    conversionRate:
      applications > 0 && item.trending_score
        ? Math.round((paid / Math.max(applications, 1)) * 1000) / 10
        : undefined,
    href: "/vendor/find",
    raw: item,
  }
  mapped.status = deriveOpportunityStatus(mapped)
  return mapped
}

async function fetchWpAnalytics(): Promise<WpMarketplaceAnalytics | null> {
  const auth = getOpsWpAuth()
  if (!auth) return null
  try {
    return await wpFetchJson<WpMarketplaceAnalytics>("/marketplace/analytics", { auth })
  } catch {
    return null
  }
}

async function fetchGrowthSummary(): Promise<WpGrowthAdminSummary | null> {
  const auth = getOpsWpAuth()
  if (!auth) return null
  try {
    return await wpFetchJson<WpGrowthAdminSummary>("/growth/admin/summary", { auth })
  } catch {
    return null
  }
}

async function fetchOpportunities(
  city?: string,
  category?: string,
): Promise<WpOpportunityItem[]> {
  try {
    const data = await wpFetchJson<WpSearchResponse>("/marketplace/search", {
      query: {
        per_page: 50,
        sort: "popular",
        q: city || undefined,
        categories: category || undefined,
      },
    })
    return data.items ?? []
  } catch {
    return []
  }
}

function countOpportunitiesByCity(items: WpOpportunityItem[]): Record<string, number> {
  const map: Record<string, number> = {}
  for (const item of items) {
    const city = item.city?.trim()
    if (!city) continue
    const key = item.state ? `${city}, ${item.state}` : city
    map[key] = (map[key] ?? 0) + 1
  }
  return map
}

function buildVendorActivation(
  growth: WpGrowthAdminSummary | null,
  opportunities: OpportunityPerformanceItem[],
): VendorActivationInsight {
  const paymentRequired = opportunities.reduce((sum, o) => sum + (o.paymentRequired ?? 0), 0)
  const booked = opportunities.reduce((sum, o) => sum + (o.paid ?? 0), 0)
  return {
    newVendors: growth?.active_vendors ?? 0,
    incompleteProfiles: 0,
    missingDocuments: 0,
    savedNoApply: 0,
    appliedUnderReview: opportunities.reduce((sum, o) => sum + (o.applications ?? 0), 0),
    paymentRequired,
    booked,
    passViewed: 0,
    raw: growth,
  }
}

export async function fetchLiveFunnel(range: OpsAnalyticsRange): Promise<{
  meta: OpsAnalyticsMeta
  funnel: MarketplaceFunnel
}> {
  if (!isLiveApiEnabled()) {
    return getMockFunnel(range)
  }

  const reasons: string[] = []
  const analytics = await fetchWpAnalytics()
  const growth = await fetchGrowthSummary()

  if (!getOpsWpAuth()) {
    reasons.push("OPS_WP_USERNAME and OPS_WP_APP_PASSWORD required for full funnel metrics.")
  }
  if (!analytics) {
    reasons.push("LNKup /marketplace/analytics unavailable.")
  }

  const partial = reasons.length > 0 || !analytics
  const funnel = buildFunnelFromPartial(range, {
    searches: analytics?.searches_30d,
    favorites: analytics?.favorites_30d,
    cardClicks: analytics?.card_clicks_30d,
    activeVendors: growth?.active_vendors,
    applyClicks: analytics?.card_clicks_30d,
    applications: 0,
  })

  return {
    meta: buildMeta(range, partial, reasons.length ? reasons : undefined),
    funnel,
  }
}

export async function fetchLiveSummary(
  range: OpsAnalyticsRange,
  filters: { city?: string; category?: string; status?: string },
): Promise<OpsAnalyticsSummary> {
  if (!isLiveApiEnabled()) {
    return getMockSummary(range)
  }

  const reasons: string[] = []
  const analytics = await fetchWpAnalytics()
  const growth = await fetchGrowthSummary()
  const wpItems = await fetchOpportunities(filters.city, filters.category)

  if (!getOpsWpAuth()) {
    reasons.push("Server ops credentials not configured — some metrics unavailable.")
  }
  if (!analytics) {
    reasons.push("LNKup analytics endpoint unavailable.")
  }

  let opportunities = wpItems.map(mapOpportunity)
  if (filters.status) {
    opportunities = opportunities.filter((o) => o.status === filters.status)
  }

  const opportunitiesByCity = countOpportunitiesByCity(wpItems)
  const searchInsights =
    analytics ? buildSearchInsightsFromWp(analytics) : []
  const markets = analytics
    ? buildMarketsFromWp({
        top_cities: analytics.top_cities,
        popular_categories: analytics.popular_categories,
        opportunitiesByCity,
      })
    : []

  const vendorActivation = buildVendorActivation(growth, opportunities)
  const partial = reasons.length > 0 || !analytics

  const funnelData = buildFunnelFromPartial(range, {
    searches: analytics?.searches_30d,
    favorites: analytics?.favorites_30d,
    cardClicks: analytics?.card_clicks_30d,
    activeVendors: growth?.active_vendors,
  })

  const alerts = buildLaunchAlerts({
    funnel: funnelData,
    vendorActivation,
    opportunities,
    searchInsights,
    markets,
  })
  const diagnostics = mapFunnelSegmentDiagnostics(analytics?.funnel_diagnostics)
  const experiments = mapExperimentSnapshots(analytics?.experiment_snapshots)
  const recommendations = prioritizeRecommendations(
    mapExperimentRecommendations(analytics?.recommendations),
  )

  return {
    meta: buildMeta(range, partial, reasons.length ? reasons : undefined),
    vendorActivation,
    opportunities,
    searchInsights,
    markets,
    alerts,
    diagnostics: diagnostics.length > 0 ? diagnostics : buildDefaultSegmentDiagnostics(),
    experiments: experiments.length > 0 ? experiments : buildDefaultExperiments(),
    recommendations: recommendations.length > 0 ? recommendations : buildDefaultRecommendations(),
  }
}

export async function probePrelaunchGate(): Promise<{
  isPrelaunchMode: boolean
  isApplyContextGated: boolean
  canApplySubmit: boolean
}> {
  if (!isLiveApiEnabled()) {
    return {
      isPrelaunchMode: true,
      isApplyContextGated: true,
      canApplySubmit: false,
    }
  }

  try {
    await wpFetchJson("/applications/apply-context/1")
    return {
      isPrelaunchMode: false,
      isApplyContextGated: false,
      canApplySubmit: true,
    }
  } catch (error) {
    if (error instanceof WpFetchError && error.code === "marketplace_closed") {
      return {
        isPrelaunchMode: true,
        isApplyContextGated: true,
        canApplySubmit: false,
      }
    }
    if (error instanceof WpFetchError && (error.status === 403 || error.status === 400)) {
      return {
        isPrelaunchMode: false,
        isApplyContextGated: true,
        canApplySubmit: false,
      }
    }
    return {
      isPrelaunchMode: false,
      isApplyContextGated: false,
      canApplySubmit: false,
    }
  }
}

export function parseOpsRange(value: string | null): OpsAnalyticsRange {
  if (value === "24h" || value === "7d" || value === "30d" || value === "all") return value
  return "7d"
}
