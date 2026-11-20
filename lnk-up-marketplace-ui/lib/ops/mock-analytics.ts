import type {
  LaunchOptimizationAlert,
  MarketplaceFunnel,
  MarketDemandItem,
  OpsAnalyticsMeta,
  OpsAnalyticsRange,
  OpsAnalyticsSummary,
  OpportunityPerformanceItem,
  PrelaunchStatus,
  SearchInsight,
  VendorActivationInsight,
} from "@/lib/ops/types"
import {
  buildDefaultExperiments,
  buildDefaultRecommendations,
  buildDefaultSegmentDiagnostics,
} from "@/lib/ops/experimentation-contract"

function meta(range: OpsAnalyticsRange): OpsAnalyticsMeta {
  return {
    live: false,
    mocked: true,
    partial: false,
    updatedAt: new Date().toISOString(),
    range,
  }
}

function buildFunnelSteps(): MarketplaceFunnel["steps"] {
  const counts = [100, 45, 28, 16, 12, 12, 8, 3, 2, 1, 1]
  const labels = [
    "Visitor",
    "Signup started",
    "Onboarding started",
    "Profile completed",
    "Opportunity saved",
    "Apply clicked",
    "Application submitted",
    "Approved",
    "Payment required",
    "Paid / booked",
    "Pass viewed",
  ]
  const ids = [
    "visitor",
    "signup_started",
    "onboarding_started",
    "profile_completed",
    "opportunity_saved",
    "apply_clicked",
    "application_submitted",
    "approved",
    "payment_required",
    "paid_booked",
    "pass_viewed",
  ]

  return ids.map((id, i) => {
    const count = counts[i] ?? 0
    const previousCount = i > 0 ? counts[i - 1] : undefined
    const conversionRate =
      previousCount && previousCount > 0
        ? Math.round((count / previousCount) * 1000) / 10
        : undefined
    const dropoffRate =
      previousCount && previousCount > 0
        ? Math.round(((previousCount - count) / previousCount) * 1000) / 10
        : undefined
    return {
      id,
      label: labels[i] ?? id,
      count,
      previousCount,
      conversionRate,
      dropoffRate,
      trend: "unknown" as const,
    }
  })
}

export function getMockFunnel(range: OpsAnalyticsRange = "7d") {
  return {
    meta: meta(range),
    funnel: {
      range,
      steps: buildFunnelSteps(),
      updatedAt: new Date().toISOString(),
    },
  }
}

const mockVendorActivation: VendorActivationInsight = {
  newVendors: 28,
  incompleteProfiles: 11,
  missingDocuments: 7,
  savedNoApply: 9,
  appliedUnderReview: 5,
  paymentRequired: 2,
  booked: 1,
  passViewed: 1,
  stuckVendors: [
    { id: "v-101", label: "Vendor #101", reason: "profile_incomplete", href: "/vendor/profile" },
    { id: "v-204", label: "Vendor #204", reason: "missing_documents", href: "/vendor/documents" },
    { id: "v-318", label: "Vendor #318", reason: "saved_no_apply" },
    { id: "v-422", label: "Vendor #422", reason: "payment_required", href: "/vendor/requests" },
  ],
}

const mockOpportunities: OpportunityPerformanceItem[] = [
  {
    opportunityId: "101",
    title: "Charlotte Summer Food Fest",
    city: "Charlotte",
    state: "NC",
    eventDate: "2026-08-15",
    views: 420,
    saves: 38,
    applyClicks: 22,
    applications: 8,
    approvals: 3,
    paymentRequired: 2,
    paid: 1,
    conversionRate: 1.9,
    status: "healthy",
    href: "/vendor/find",
  },
  {
    opportunityId: "102",
    title: "Atlanta Beauty Expo",
    city: "Atlanta",
    state: "GA",
    eventDate: "2026-07-20",
    views: 310,
    saves: 45,
    applyClicks: 18,
    applications: 4,
    status: "needs_attention",
    href: "/vendor/find",
  },
  {
    opportunityId: "103",
    title: "Raleigh Night Market",
    city: "Raleigh",
    state: "NC",
    eventDate: "2026-07-05",
    views: 180,
    saves: 28,
    applyClicks: 6,
    applications: 1,
    status: "closing_soon",
    href: "/vendor/find",
  },
  {
    opportunityId: "104",
    title: "Charlotte Food Truck Rally",
    city: "Charlotte",
    state: "NC",
    eventDate: "2026-09-01",
    views: 95,
    saves: 12,
    applications: 0,
    status: "low_supply",
    href: "/vendor/find",
  },
]

const mockSearchInsights: SearchInsight[] = [
  {
    query: "food truck",
    city: "Charlotte",
    count: 84,
    saves: 18,
    applyClicks: 9,
    suggestedAction: "Promote Charlotte food opportunities — high search volume.",
  },
  {
    query: "beauty vendor",
    city: "Atlanta",
    count: 62,
    saves: 14,
    applyClicks: 3,
    suggestedAction: "Atlanta beauty vendors are searching but not applying.",
  },
  {
    query: "night market",
    city: "Raleigh",
    count: 41,
    saves: 22,
    applyClicks: 4,
    suggestedAction: "Raleigh searches have high saves but low applications.",
  },
  {
    query: "vegan festival",
    city: "Charlotte",
    count: 19,
    zeroResults: true,
    suggestedAction: "Vendors are searching Charlotte vegan festivals but there are no matching opportunities.",
  },
]

const mockMarkets: MarketDemandItem[] = [
  {
    type: "city",
    label: "Charlotte, NC",
    searches: 142,
    opportunities: 8,
    applications: 12,
    saves: 34,
    paidBookings: 2,
    demandScore: 88,
    supplyGap: false,
  },
  {
    type: "city",
    label: "Atlanta, GA",
    searches: 98,
    opportunities: 5,
    applications: 6,
    saves: 28,
    demandScore: 72,
    supplyGap: false,
    suggestedAction: "Review Atlanta conversion — searches outpace applications.",
  },
  {
    type: "city",
    label: "Raleigh, NC",
    searches: 67,
    opportunities: 2,
    applications: 3,
    saves: 19,
    demandScore: 65,
    supplyGap: true,
    suggestedAction: "Add more Raleigh opportunities.",
  },
  {
    type: "category",
    label: "Food & Beverage",
    searches: 210,
    opportunities: 14,
    applications: 18,
    demandScore: 90,
  },
  {
    type: "category",
    label: "Beauty & Wellness",
    searches: 88,
    opportunities: 3,
    applications: 4,
    demandScore: 58,
    supplyGap: true,
    suggestedAction: "Needs events — beauty category demand exceeds supply.",
  },
]

const mockAlerts: LaunchOptimizationAlert[] = [
  {
    id: "apply-dropoff",
    severity: "warning",
    title: "High apply clicks, low submissions",
    description: "12 apply clicks but only 8 applications submitted in the last 7 days.",
    suggestedAction: "Check apply-context route and prelaunch gate messaging.",
    source: "funnel",
  },
  {
    id: "docs-missing",
    severity: "warning",
    title: "Vendors missing documents",
    description: "7 vendors have incomplete document requirements.",
    suggestedAction: "Push vendors to complete documents.",
    href: "/vendor/documents",
    source: "vendor_activation",
  },
  {
    id: "payment-unpaid",
    severity: "urgent",
    title: "Payment-required requests unpaid",
    description: "2 vendors are approved but have not completed payment.",
    suggestedAction: "Review payment-required requests.",
    href: "/vendor/requests",
    source: "vendor_activation",
  },
  {
    id: "charlotte-zero",
    severity: "info",
    title: "Zero-result searches in Charlotte",
    description: "Vendors searched for vegan festival opportunities with no matches.",
    suggestedAction: "Add more Charlotte opportunities.",
    source: "search",
  },
  {
    id: "prelaunch-gate",
    severity: "info",
    title: "Prelaunch gate active",
    description: "Search is live, but apply submit may be gated until August 1, 2026.",
    suggestedAction: "Use LNKup staging or admin preview to test full apply E2E.",
    source: "prelaunch",
  },
]

export function getMockSummary(range: OpsAnalyticsRange = "7d"): OpsAnalyticsSummary {
  return {
    meta: meta(range),
    vendorActivation: mockVendorActivation,
    opportunities: mockOpportunities,
    searchInsights: mockSearchInsights,
    markets: mockMarkets,
    alerts: mockAlerts,
    diagnostics: buildDefaultSegmentDiagnostics(),
    experiments: buildDefaultExperiments(),
    recommendations: buildDefaultRecommendations(),
  }
}

export function getMockPrelaunch(): PrelaunchStatus {
  return {
    launchDate: "2026-08-01",
    launchLabel: "August 1, 2026",
    isPrelaunchMode: true,
    canSearch: true,
    canApplySubmit: false,
    isApplyContextGated: true,
    recommendedAction:
      "Search is live, but apply submit may be gated until August 1, 2026. Use LNKup staging or admin preview to test full apply E2E.",
  }
}
