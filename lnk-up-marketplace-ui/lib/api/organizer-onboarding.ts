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

/** Canonical WordPress guided-setup steps (GET /lnkup/v1/organizer/onboarding). */
export type WpOnboardingStepKey = "account" | "organization" | "format" | "first_event" | "payouts" | "share"

export interface WpOnboardingStep {
  key: WpOnboardingStepKey
  title: string
  status: "complete" | "in_progress" | "not_started"
  missing: string[]
  cta_url: string
  skipped?: boolean
  skippable?: boolean
}

export interface WpOnboardingPayload {
  enabled: boolean
  percent: number
  is_complete: boolean
  next_step: { key: WpOnboardingStepKey; title: string; url: string; missing: string[] } | null
  resume_url: string
  steps: WpOnboardingStep[]
  drafts: Array<{ type: string; id: number; title: string; updated_at: string; url: string }>
  state: { formats: string[]; current_step: string; skipped: string[]; dismissed: boolean }
}

const SERVER_STEP: Record<OrganizerOnboardingStep, WpOnboardingStepKey> = {
  organizer_basics: "organization",
  event_type: "format",
  event_details: "first_event",
  vendor_categories: "first_event",
  fees_spots_deadline: "first_event",
  documents: "first_event",
  publish_or_handoff: "share",
  complete: "share",
}

/** Server step that a local wizard step belongs to. */
export function toServerStep(step: OrganizerOnboardingStep): WpOnboardingStepKey {
  return SERVER_STEP[step]
}

export type OrganizerOnboardingState = {
  organizerId?: string
  currentStep: OrganizerOnboardingStep
  completedSteps: OrganizerOnboardingStep[]
  skippedSteps?: OrganizerOnboardingStep[]
  /** WordPress guided setup when it is enabled; readiness and next action come from here. */
  server?: WpOnboardingPayload | null
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
