import type { WpProfileStrength, WpVerificationVendor } from "@/lib/api/types"
import type { OnboardingProgress, OnboardingStepKey } from "@/lib/auth/onboarding-progress"
import { getPublicWpSiteUrl } from "@/lib/api/config"

export type VendorOnboardingStep =
  | "business"
  | "categories"
  | "service_area"
  | "setup"
  | "documents"
  | "first_search"
  | "complete"

export type VendorOnboardingState = {
  userId?: string
  currentStep: VendorOnboardingStep
  completedSteps: string[]
  profileCompletion: number
  nextAction?: {
    label: string
    href: string
  }
  canSkip?: boolean
  raw?: unknown
}

const STEP_ORDER: VendorOnboardingStep[] = [
  "business",
  "categories",
  "service_area",
  "setup",
  "documents",
  "first_search",
  "complete",
]

const SECTION_KEYS: Record<OnboardingStepKey, string> = {
  business: "business_basics",
  categories: "products_services",
  service_area: "service_areas",
  setup: "setup_requirements",
  documents: "documents",
  first_search: "first_search",
}

function sectionComplete(strength: WpProfileStrength | undefined, key: string): boolean {
  const section = strength?.sections?.find((s) => s.key === key)
  return Boolean(section?.complete)
}

function documentsComplete(verification?: WpVerificationVendor): boolean {
  const docs = verification?.required_documents ?? []
  if (docs.length === 0) {
    const missing = verification?.missing_items ?? []
    const docMissing = missing.some((m) => {
      const label = typeof m === "string" ? m : m.label || ""
      return /document|insurance|permit|license|w9/i.test(label)
    })
    return !docMissing
  }
  return docs.every((d) => d.status === "approved" || d.status === "verified")
}

export function deriveOnboardingState(
  strength: WpProfileStrength | undefined,
  verification: WpVerificationVendor | undefined,
  progress: OnboardingProgress,
  userId?: string,
): VendorOnboardingState {
  const wpCompleted: OnboardingStepKey[] = []

  if (sectionComplete(strength, SECTION_KEYS.business)) wpCompleted.push("business")
  if (sectionComplete(strength, SECTION_KEYS.categories)) wpCompleted.push("categories")
  if (sectionComplete(strength, SECTION_KEYS.service_area)) wpCompleted.push("service_area")
  if (sectionComplete(strength, SECTION_KEYS.setup)) wpCompleted.push("setup")
  if (documentsComplete(verification)) wpCompleted.push("documents")

  const sessionCompleted = progress.completedSteps ?? []
  const skipped = new Set(progress.skippedSteps ?? [])

  const completedSet = new Set<string>([...wpCompleted, ...sessionCompleted])
  if (progress.completedSteps.includes("first_search") || skipped.has("first_search")) {
    completedSet.add("first_search")
  }

  const completedSteps = STEP_ORDER.filter((s) => s !== "complete" && completedSet.has(s))

  let currentStep: VendorOnboardingStep = "complete"
  for (const step of STEP_ORDER) {
    if (step === "complete") break
    if (!completedSet.has(step) && !skipped.has(step)) {
      currentStep = step
      break
    }
  }

  const profileCompletion = strength?.score ?? verification?.percent_complete ?? 0
  const wpProfileUrl = getPublicWpSiteUrl()
    ? `${getPublicWpSiteUrl()}/wp-admin/profile.php`
    : "/vendor/profile"

  const nextAction =
    currentStep === "complete"
      ? { label: "Find events", href: "/vendor/find" }
      : currentStep === "documents"
        ? { label: "Upload documents", href: "/vendor/documents" }
        : { label: "Complete on LNKup web", href: wpProfileUrl }

  return {
    userId,
    currentStep,
    completedSteps,
    profileCompletion,
    nextAction,
    canSkip: currentStep !== "complete" && currentStep !== "documents",
    raw: { strength, verification, progress },
  }
}

export const ONBOARDING_STEP_META: Record<
  Exclude<VendorOnboardingStep, "complete">,
  { title: string; description: string; wpNote?: string }
> = {
  business: {
    title: "Business basics",
    description: "Add your business name, category, and a short description.",
    wpNote: "Profile fields are saved in your LNKup vendor profile.",
  },
  categories: {
    title: "Categories & products",
    description: "Tell organizers what you sell and which booth categories fit you.",
    wpNote: "Update products and services on LNKup web.",
  },
  service_area: {
    title: "Service area",
    description: "Share the cities and states where you can vend.",
    wpNote: "Service areas are managed on LNKup web.",
  },
  setup: {
    title: "Setup needs",
    description: "Power, water, tent size, and load-in notes help organizers plan.",
    wpNote: "Setup requirements live in your LNKup profile.",
  },
  documents: {
    title: "Documents",
    description: "Upload insurance, permits, and licenses once — reuse across events.",
    wpNote: "You can upload documents here or on LNKup web.",
  },
  first_search: {
    title: "Find your first opportunity",
    description: "Browse events near you and save opportunities you like.",
  },
}
