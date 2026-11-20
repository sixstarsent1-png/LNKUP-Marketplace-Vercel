import type {
  LaunchOptimizationAlert,
  MarketplaceFunnel,
  MarketplaceFunnelStep,
  MarketDemandItem,
  OpsAnalyticsRange,
  OpsAnalyticsSummary,
  OpportunityPerformanceItem,
  SearchInsight,
  VendorActivationInsight,
} from "@/lib/ops/types"

type AlertContext = {
  funnel?: MarketplaceFunnel
  vendorActivation?: VendorActivationInsight
  opportunities?: OpportunityPerformanceItem[]
  searchInsights?: SearchInsight[]
  markets?: MarketDemandItem[]
  prelaunchGated?: boolean
  healthFailures?: string[]
}

function pct(n: number, d: number): number {
  if (d <= 0) return 0
  return Math.round((n / d) * 1000) / 10
}

export function buildLaunchAlerts(ctx: AlertContext): LaunchOptimizationAlert[] {
  const alerts: LaunchOptimizationAlert[] = []

  const applyClicked = ctx.funnel?.steps.find((s) => s.id === "apply_clicked")?.count ?? 0
  const submitted = ctx.funnel?.steps.find((s) => s.id === "application_submitted")?.count ?? 0
  if (applyClicked >= 5 && submitted < applyClicked * 0.6) {
    alerts.push({
      id: "funnel-apply-dropoff",
      severity: "warning",
      title: "High apply clicks, low submissions",
      description: `${applyClicked} apply clicks but only ${submitted} applications submitted.`,
      suggestedAction: "Check apply-context route and prelaunch gate messaging.",
      source: "funnel",
    })
  }

  if (ctx.vendorActivation?.missingDocuments && ctx.vendorActivation.missingDocuments >= 3) {
    alerts.push({
      id: "vendor-docs-missing",
      severity: "warning",
      title: "Many vendors missing documents",
      description: `${ctx.vendorActivation.missingDocuments} vendors have incomplete document requirements.`,
      suggestedAction: "Push vendors to complete documents.",
      href: "/vendor/documents",
      source: "vendor_activation",
    })
  }

  if (ctx.vendorActivation?.paymentRequired && ctx.vendorActivation.paymentRequired >= 1) {
    alerts.push({
      id: "vendor-payment-unpaid",
      severity: "urgent",
      title: "Payment-required requests unpaid",
      description: `${ctx.vendorActivation.paymentRequired} vendors are approved but have not completed payment.`,
      suggestedAction: "Review payment-required requests.",
      href: "/vendor/requests",
      source: "vendor_activation",
    })
  }

  for (const opp of ctx.opportunities ?? []) {
    if (opp.status === "closing_soon" && (opp.applications ?? 0) < 3) {
      alerts.push({
        id: `opp-closing-${opp.opportunityId}`,
        severity: "warning",
        title: `Closing soon: ${opp.title}`,
        description: `Only ${opp.applications ?? 0} applications with event on ${opp.eventDate ?? "soon"}.`,
        suggestedAction: "Promote closing-soon opportunities.",
        href: opp.href,
        source: "opportunity_performance",
      })
      break
    }
  }

  for (const insight of ctx.searchInsights ?? []) {
    if (insight.zeroResults) {
      alerts.push({
        id: `search-zero-${insight.query ?? insight.city ?? "unknown"}`,
        severity: "info",
        title: "Zero-result searches",
        description:
          insight.suggestedAction ??
          `Vendors searched for "${insight.query ?? ""}" with no matching opportunities.`,
        suggestedAction: insight.city ? `Add more ${insight.city} opportunities.` : "Add matching opportunities.",
        source: "search",
      })
      break
    }
    if (
      insight.saves &&
      insight.applyClicks !== undefined &&
      insight.saves >= 10 &&
      insight.applyClicks < insight.saves * 0.3
    ) {
      alerts.push({
        id: `search-saves-low-apply-${insight.city ?? insight.query}`,
        severity: "info",
        title: "High saves, low apply clicks",
        description: `${insight.city ?? insight.query}: ${insight.saves} saves but only ${insight.applyClicks} apply clicks.`,
        suggestedAction: insight.suggestedAction ?? "Review opportunity relevance and apply CTA.",
        source: "search",
      })
    }
  }

  for (const market of ctx.markets ?? []) {
    if (market.supplyGap) {
      alerts.push({
        id: `market-gap-${market.type}-${market.label}`,
        severity: "warning",
        title: `${market.label} has demand but low supply`,
        description: market.suggestedAction ?? "City has searches but limited opportunities.",
        suggestedAction: market.suggestedAction ?? `Add more ${market.label} opportunities.`,
        source: "market",
      })
      break
    }
  }

  if (ctx.prelaunchGated) {
    alerts.push({
      id: "prelaunch-gate-active",
      severity: "info",
      title: "Prelaunch gate still blocking apply",
      description: "Search is live, but apply submit may be gated until launch date.",
      suggestedAction: "Use LNKup staging or admin preview to test full apply E2E.",
      source: "prelaunch",
    })
  }

  for (const fail of ctx.healthFailures ?? []) {
    alerts.push({
      id: `health-${fail}`,
      severity: "urgent",
      title: "BFF route failing",
      description: `${fail} is not responding as expected.`,
      suggestedAction: "Check BFF health section and LNKup connectivity.",
      source: "api_health",
    })
    break
  }

  return alerts
}

export function buildFunnelFromPartial(
  range: OpsAnalyticsRange,
  data: {
    searches?: number
    favorites?: number
    cardClicks?: number
    activeVendors?: number
    applyClicks?: number
    applications?: number
  },
): MarketplaceFunnel {
  const searches = data.searches ?? 0
  const visitors = Math.max(searches, data.cardClicks ?? 0, 1)
  const rawSteps: Array<{ id: string; label: string; count: number }> = [
    { id: "visitor", label: "Visitor", count: visitors },
    { id: "signup_started", label: "Signup started", count: 0 },
    { id: "onboarding_started", label: "Onboarding started", count: 0 },
    { id: "profile_completed", label: "Profile completed", count: data.activeVendors ?? 0 },
    { id: "opportunity_saved", label: "Opportunity saved", count: data.favorites ?? 0 },
    { id: "apply_clicked", label: "Apply clicked", count: data.applyClicks ?? data.cardClicks ?? 0 },
    { id: "application_submitted", label: "Application submitted", count: data.applications ?? 0 },
    { id: "approved", label: "Approved", count: 0 },
    { id: "payment_required", label: "Payment required", count: 0 },
    { id: "paid_booked", label: "Paid / booked", count: 0 },
    { id: "pass_viewed", label: "Pass viewed", count: 0 },
  ]

  const steps: MarketplaceFunnelStep[] = rawSteps.map((step, i) => {
    const previousCount = i > 0 ? rawSteps[i - 1].count : undefined
    return {
      ...step,
      previousCount,
      conversionRate: previousCount ? pct(step.count, previousCount) : undefined,
      dropoffRate:
        previousCount && previousCount > 0
          ? pct(previousCount - step.count, previousCount)
          : undefined,
      trend: "unknown" as const,
    }
  })

  return {
    range,
    steps,
    updatedAt: new Date().toISOString(),
    raw: data,
  }
}

export function deriveOpportunityStatus(
  item: OpportunityPerformanceItem,
): OpportunityPerformanceItem["status"] {
  const apps = item.applications ?? 0
  if (apps === 0) return "low_supply"
  if (item.eventDate) {
    const days = (new Date(item.eventDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24)
    if (days > 0 && days <= 14 && apps < 5) return "closing_soon"
  }
  const rate = item.conversionRate ?? 0
  if (item.saves && item.saves >= 10 && rate < 5) return "needs_attention"
  if (apps >= 3) return "healthy"
  return "unknown"
}

export function buildSearchInsightsFromWp(data: {
  popular_searches?: string[] | Record<string, number>
  zero_result_searches?: Record<string, number>
  top_cities?: Record<string, number>
  popular_categories?: string[]
  filter_usage?: Record<string, number>
}): SearchInsight[] {
  const insights: SearchInsight[] = []

  const zero = data.zero_result_searches ?? {}
  for (const [query, count] of Object.entries(zero).slice(0, 5)) {
    insights.push({
      query,
      count,
      zeroResults: true,
      suggestedAction: `Vendors searched "${query}" but there are no matching opportunities.`,
    })
  }

  const popular = data.popular_searches
  if (Array.isArray(popular)) {
    for (const q of popular.slice(0, 5)) {
      insights.push({ query: q, count: 1 })
    }
  } else if (popular && typeof popular === "object") {
    for (const [query, count] of Object.entries(popular).slice(0, 5)) {
      insights.push({ query, count: Number(count) })
    }
  }

  const cities = data.top_cities ?? {}
  for (const [city, count] of Object.entries(cities).slice(0, 5)) {
    insights.push({
      city,
      count: Number(count),
      suggestedAction: count >= 20 ? `Review ${city} opportunity supply.` : undefined,
    })
  }

  for (const cat of (data.popular_categories ?? []).slice(0, 3)) {
    insights.push({ category: cat, count: 1 })
  }

  return insights
}

export function buildMarketsFromWp(data: {
  top_cities?: Record<string, number>
  popular_categories?: string[]
  opportunitiesByCity?: Record<string, number>
}): MarketDemandItem[] {
  const markets: MarketDemandItem[] = []
  const cities = data.top_cities ?? {}

  for (const [label, searches] of Object.entries(cities).slice(0, 8)) {
    const opportunities = data.opportunitiesByCity?.[label] ?? 0
    const supplyGap = searches >= 20 && opportunities < 3
    markets.push({
      type: "city",
      label,
      searches: Number(searches),
      opportunities,
      demandScore: Math.min(100, Number(searches)),
      supplyGap,
      suggestedAction: supplyGap ? `Add more ${label} opportunities.` : undefined,
    })
  }

  for (const label of (data.popular_categories ?? []).slice(0, 5)) {
    markets.push({
      type: "category",
      label,
      searches: 1,
      demandScore: 50,
    })
  }

  return markets
}

export function mergeSummaryAlerts(
  summary: OpsAnalyticsSummary,
  extra: AlertContext,
): OpsAnalyticsSummary {
  const alerts = buildLaunchAlerts({
    funnel: summary.meta.partial ? undefined : undefined,
    vendorActivation: summary.vendorActivation,
    opportunities: summary.opportunities,
    searchInsights: summary.searchInsights,
    markets: summary.markets,
    ...extra,
  })
  const existingIds = new Set(summary.alerts.map((a) => a.id))
  const merged = [...summary.alerts]
  for (const alert of alerts) {
    if (!existingIds.has(alert.id)) merged.push(alert)
  }
  return { ...summary, alerts: merged }
}
