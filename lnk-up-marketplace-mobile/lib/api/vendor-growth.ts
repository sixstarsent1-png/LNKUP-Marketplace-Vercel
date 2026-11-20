import { withFixtureFallback } from "@/lib/api/fixture-honesty"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_VENDOR_GROWTH } from "@/lib/data/fixtures"

export type VendorGrowthRecommendation = {
  id: string
  actionKey: string
  title: string
  reason?: string
}

export type VendorGrowthMilestone = {
  id: string
  label: string
  done: boolean
}

export type VendorGrowthSnapshot = {
  emptyState: boolean
  completedEvents: number
  applicationSuccessRate: number
  vendorScore: number
  profileStrength: number
  applicationsSubmitted: number
  applicationsApproved: number
  citiesServed: number
  repeatOrganizerPct: number
  insights: string[]
  profileIncomplete: string[]
  recommendations: VendorGrowthRecommendation[]
  milestones: VendorGrowthMilestone[]
}

type WpProfileStrength = {
  score?: number
  incomplete?: Array<{ label?: string; key?: string; tip?: string } | string>
  missing_items?: string[]
}

type WpRecommendation =
  | {
      id?: number | string
      action_key?: string
      title?: string
      label?: string
      message?: string
      reason?: string
    }
  | string

type WpGrowthDashboard = {
  success?: boolean
  data?: {
    empty_state?: boolean
    kpis?: Record<string, number | string | undefined>
    profile_strength?: WpProfileStrength | number
    recommendations?: WpRecommendation[]
    insights?: Array<string | { text?: string; label?: string; message?: string }>
  }
}

function asNumber(value: unknown): number {
  if (typeof value === "number" && Number.isFinite(value)) return value
  if (typeof value === "string") {
    const n = Number.parseFloat(value)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}

export function deriveGrowthMilestones(input: {
  applicationsSubmitted: number
  completedEvents: number
  profileStrength: number
}): VendorGrowthMilestone[] {
  return [
    {
      id: "first-application",
      label: "Applied to first event",
      done: input.applicationsSubmitted >= 1,
    },
    {
      id: "first-booking",
      label: "First booking completed",
      done: input.completedEvents >= 1,
    },
    {
      id: "profile-80",
      label: "Profile 80% complete",
      done: input.profileStrength >= 80,
    },
    {
      id: "events-5",
      label: "5 events completed",
      done: input.completedEvents >= 5,
    },
  ]
}

function mapIncomplete(strength: WpProfileStrength | number | undefined): string[] {
  if (!strength || typeof strength === "number") return []
  const fromMissing = (strength.missing_items ?? []).filter(Boolean)
  if (fromMissing.length > 0) return fromMissing.slice(0, 6)

  return (strength.incomplete ?? [])
    .map((item) => {
      if (typeof item === "string") return item
      return item.label || item.tip || item.key || ""
    })
    .filter(Boolean)
    .slice(0, 6)
}

function mapRecommendations(raw: WpRecommendation[] | undefined): VendorGrowthRecommendation[] {
  return (raw ?? [])
    .map((r, index) => {
      if (typeof r === "string") {
        return {
          id: `rec-${index}`,
          actionKey: "general",
          title: r,
        }
      }
      const title = r.title || r.label || r.message || ""
      if (!title) return null
      return {
        id: r.id != null ? String(r.id) : `rec-${index}`,
        actionKey: r.action_key || "general",
        title,
        reason: r.reason || undefined,
      }
    })
    .filter((r): r is VendorGrowthRecommendation => Boolean(r))
    .slice(0, 5)
}

function mapInsights(
  raw: Array<string | { text?: string; label?: string; message?: string }> | undefined,
): string[] {
  return (raw ?? [])
    .map((item) => {
      if (typeof item === "string") return item
      return item.text || item.label || item.message || ""
    })
    .filter(Boolean)
    .slice(0, 3)
}

export function emptyVendorGrowthSnapshot(): VendorGrowthSnapshot {
  return {
    emptyState: true,
    completedEvents: 0,
    applicationSuccessRate: 0,
    vendorScore: 0,
    profileStrength: 0,
    applicationsSubmitted: 0,
    applicationsApproved: 0,
    citiesServed: 0,
    repeatOrganizerPct: 0,
    insights: [],
    profileIncomplete: [],
    recommendations: [],
    milestones: deriveGrowthMilestones({
      applicationsSubmitted: 0,
      completedEvents: 0,
      profileStrength: 0,
    }),
  }
}

function mapGrowth(data: WpGrowthDashboard["data"]): VendorGrowthSnapshot {
  const kpis = data?.kpis ?? {}
  const strengthRaw = data?.profile_strength
  const profileStrength =
    typeof strengthRaw === "number"
      ? strengthRaw
      : asNumber(strengthRaw?.score ?? kpis.profile_strength)

  const applicationsSubmitted = asNumber(kpis.applications_submitted)
  const completedEvents = asNumber(kpis.completed_events ?? kpis.events_completed)

  return {
    emptyState: Boolean(data?.empty_state),
    completedEvents,
    applicationSuccessRate: asNumber(kpis.application_success_rate ?? kpis.acceptance_rate),
    vendorScore: asNumber(kpis.vendor_score ?? kpis.growth_score),
    profileStrength,
    applicationsSubmitted,
    applicationsApproved: asNumber(kpis.applications_approved),
    citiesServed: asNumber(kpis.cities_served),
    repeatOrganizerPct: asNumber(kpis.repeat_organizer_pct),
    insights: mapInsights(data?.insights),
    profileIncomplete: mapIncomplete(strengthRaw),
    recommendations: mapRecommendations(data?.recommendations),
    milestones: deriveGrowthMilestones({
      applicationsSubmitted,
      completedEvents,
      profileStrength,
    }),
  }
}

export async function fetchVendorGrowth(auth?: WpAuth): Promise<VendorGrowthSnapshot> {
  return withFixtureFallback(
    "vendor.growth",
    async () => {
      if (!auth) {
        return emptyVendorGrowthSnapshot()
      }

      const payload = await wpFetchJson<WpGrowthDashboard>("/growth/dashboard", { auth })
      return mapGrowth(payload.data)
    },
    () => ({
      ...MOCK_VENDOR_GROWTH,
      recommendations: MOCK_VENDOR_GROWTH.recommendations.map((r) => ({ ...r })),
      milestones: MOCK_VENDOR_GROWTH.milestones.map((m) => ({ ...m })),
      insights: [...MOCK_VENDOR_GROWTH.insights],
      profileIncomplete: [...MOCK_VENDOR_GROWTH.profileIncomplete],
    }),
  )
}
