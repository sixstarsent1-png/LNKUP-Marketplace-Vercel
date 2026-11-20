export type OrganizerOnboardingStep =
  | "organizer_basics"
  | "event_type"
  | "event_details"
  | "vendor_categories"
  | "fees_spots_deadline"
  | "documents"
  | "publish_or_handoff"
  | "complete"

export const ONBOARDING_STEP_ORDER: OrganizerOnboardingStep[] = [
  "organizer_basics",
  "event_type",
  "event_details",
  "vendor_categories",
  "fees_spots_deadline",
  "documents",
  "publish_or_handoff",
]

export type OrganizerOnboardingState = {
  organizerId?: string
  currentStep: OrganizerOnboardingStep
  completedSteps: OrganizerOnboardingStep[]
  launchReadiness: number
  hasEvent?: boolean
  hasOpportunity?: boolean
  draft?: {
    organizationName?: string
    eventType?: string
    city?: string
    state?: string
    categories?: string[]
    vendorFee?: number
    spotsTotal?: number
    applicationDeadline?: string
    documentsMode?: string
  }
  nextAction?: {
    label: string
    href: string
  }
  raw?: unknown
}

export function computeLaunchReadiness(completed: OrganizerOnboardingStep[]): number {
  const total = ONBOARDING_STEP_ORDER.length
  const done = completed.filter((s) => ONBOARDING_STEP_ORDER.includes(s)).length
  return Math.round((done / total) * 100)
}

export function nextOnboardingStep(current: OrganizerOnboardingStep): OrganizerOnboardingStep {
  const idx = ONBOARDING_STEP_ORDER.indexOf(current)
  if (idx < 0 || idx >= ONBOARDING_STEP_ORDER.length - 1) return "publish_or_handoff"
  return ONBOARDING_STEP_ORDER[idx + 1]
}

export function buildMockOnboardingState(): OrganizerOnboardingState {
  return {
    currentStep: "organizer_basics",
    completedSteps: [],
    launchReadiness: 0,
    hasEvent: false,
    hasOpportunity: false,
    nextAction: { label: "Continue setup", href: "/organizer/onboarding" },
  }
}
