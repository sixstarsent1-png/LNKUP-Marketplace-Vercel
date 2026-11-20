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
  rebookingRate: number
  profileViews: number
  insights: string[]
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

export type WpGrowthDashboard = {
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
    rebookingRate: 0,
    profileViews: 0,
    insights: [],
    milestones: deriveGrowthMilestones({
      applicationsSubmitted: 0,
      completedEvents: 0,
      profileStrength: 0,
    }),
  }
}

export function mockVendorGrowth(): VendorGrowthSnapshot {
  return {
    emptyState: false,
    completedEvents: 10,
    applicationSuccessRate: 68,
    vendorScore: 87,
    profileStrength: 78,
    applicationsSubmitted: 18,
    applicationsApproved: 12,
    citiesServed: 4,
    repeatOrganizerPct: 40,
    rebookingRate: 40,
    profileViews: 0,
    insights: [],
    milestones: deriveGrowthMilestones({
      applicationsSubmitted: 18,
      completedEvents: 10,
      profileStrength: 78,
    }),
  }
}

export function mapGrowthDashboard(payload: WpGrowthDashboard): VendorGrowthSnapshot {
  const data = payload.data
  const kpis = data?.kpis ?? {}
  const strengthRaw = data?.profile_strength
  const profileStrength =
    typeof strengthRaw === "number"
      ? strengthRaw
      : asNumber(strengthRaw?.score ?? kpis.profile_strength)

  const applicationsSubmitted = asNumber(kpis.applications_submitted)
  const completedEvents = asNumber(kpis.completed_events ?? kpis.events_completed)
  const repeat = asNumber(kpis.repeat_organizer_pct ?? kpis.rebooking_rate)

  return {
    emptyState: Boolean(data?.empty_state),
    completedEvents,
    applicationSuccessRate: asNumber(kpis.application_success_rate ?? kpis.acceptance_rate),
    vendorScore: asNumber(kpis.vendor_score ?? kpis.growth_score),
    profileStrength,
    applicationsSubmitted,
    applicationsApproved: asNumber(kpis.applications_approved),
    citiesServed: asNumber(kpis.cities_served),
    repeatOrganizerPct: repeat,
    rebookingRate: asNumber(kpis.rebooking_rate ?? repeat),
    profileViews: asNumber(kpis.profile_views),
    insights: (data?.insights ?? [])
      .map((item) => (typeof item === "string" ? item : item.text || item.label || item.message || ""))
      .filter(Boolean)
      .slice(0, 3),
    milestones: deriveGrowthMilestones({
      applicationsSubmitted,
      completedEvents,
      profileStrength,
    }),
  }
}
