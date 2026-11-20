import type { AppIconName } from "@/components/ui/app-icon"

export type TutorialRole = "vendor" | "organizer"

export type VendorTutorialStepId =
  | "profile_readiness"
  | "discover_opportunity"
  | "submit_application"
  | "complete_payment_pass"

export type OrganizerTutorialStepId =
  | "launch_event"
  | "review_requests"
  | "track_payments"

export type TutorialStepId = VendorTutorialStepId | OrganizerTutorialStepId

export type TutorialStep = {
  id: TutorialStepId
  label: string
  description: string
  href: string
  ctaLabel: string
  done: boolean
  icon?: AppIconName
}

export type RoleTutorial = {
  role: TutorialRole
  title: string
  subtitle: string
  steps: TutorialStep[]
  completedCount: number
  totalCount: number
  isComplete: boolean
  /** Where to open push preferences from the tutorial footer */
  pushHref: string
}
