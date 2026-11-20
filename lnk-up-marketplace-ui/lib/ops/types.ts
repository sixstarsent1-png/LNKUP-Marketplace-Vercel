import type {
  ExperimentRecommendation,
  ExperimentSnapshot,
  FunnelSegmentDiagnostic,
} from "@/lib/ops/experimentation-contract"

export type OpsAnalyticsRange = "24h" | "7d" | "30d" | "all"

export type MarketplaceFunnelStep = {
  id: string
  label: string
  count: number
  previousCount?: number
  conversionRate?: number
  dropoffRate?: number
  trend?: "up" | "down" | "flat" | "unknown"
}

export type MarketplaceFunnel = {
  range: OpsAnalyticsRange
  steps: MarketplaceFunnelStep[]
  updatedAt?: string
  raw?: unknown
}

export type VendorActivationInsight = {
  newVendors: number
  incompleteProfiles: number
  missingDocuments: number
  savedNoApply: number
  appliedUnderReview: number
  paymentRequired: number
  booked: number
  passViewed: number
  stuckVendors?: Array<{
    id: string
    label: string
    reason:
      | "profile_incomplete"
      | "missing_documents"
      | "saved_no_apply"
      | "payment_required"
      | "unknown"
    href?: string
  }>
  raw?: unknown
}

export type OpportunityPerformanceItem = {
  opportunityId: string
  eventId?: string
  title: string
  city?: string
  state?: string
  eventDate?: string
  views?: number
  saves?: number
  applyClicks?: number
  applications?: number
  approvals?: number
  paymentRequired?: number
  paid?: number
  conversionRate?: number
  status?: "healthy" | "needs_attention" | "closing_soon" | "low_supply" | "unknown"
  href?: string
  raw?: unknown
}

export type SearchInsight = {
  query?: string
  city?: string
  category?: string
  count: number
  zeroResults?: boolean
  saves?: number
  applyClicks?: number
  suggestedAction?: string
}

export type MarketDemandItem = {
  type: "city" | "category"
  label: string
  searches?: number
  opportunities?: number
  applications?: number
  saves?: number
  paidBookings?: number
  demandScore?: number
  supplyGap?: boolean
  suggestedAction?: string
}

export type LaunchOptimizationAlert = {
  id: string
  severity: "info" | "warning" | "urgent"
  title: string
  description: string
  suggestedAction?: string
  href?: string
  source:
    | "funnel"
    | "vendor_activation"
    | "opportunity_performance"
    | "search"
    | "market"
    | "api_health"
    | "prelaunch"
}

export type BffHealthCheck = {
  id: string
  label: string
  status: "pass" | "warning" | "fail" | "not_configured" | "auth_required"
  latencyMs?: number
  message?: string
  checkedAt?: string
}

export type PrelaunchStatus = {
  launchDate: string
  launchLabel: string
  isPrelaunchMode: boolean
  canSearch: boolean
  canApplySubmit: boolean
  isApplyContextGated: boolean
  recommendedAction: string
}

export type OpsAnalyticsMeta = {
  live: boolean
  mocked: boolean
  partial: boolean
  updatedAt: string
  unavailableReasons?: string[]
  range: OpsAnalyticsRange
}

export type OpsAnalyticsSummary = {
  meta: OpsAnalyticsMeta
  vendorActivation: VendorActivationInsight
  opportunities: OpportunityPerformanceItem[]
  searchInsights: SearchInsight[]
  markets: MarketDemandItem[]
  alerts: LaunchOptimizationAlert[]
  diagnostics: FunnelSegmentDiagnostic[]
  experiments: ExperimentSnapshot[]
  recommendations: ExperimentRecommendation[]
}

export type OpsFunnelResponse = {
  meta: OpsAnalyticsMeta
  funnel: MarketplaceFunnel
}

export type OpsHealthResponse = {
  checks: BffHealthCheck[]
  updatedAt: string
}
