import type { VendorOnboardingState } from "@/lib/api/vendor-onboarding"

export const mockVendorOnboarding: VendorOnboardingState = {
  userId: "mock-vendor",
  currentStep: "categories",
  completedSteps: ["business"],
  profileCompletion: 42,
  nextAction: { label: "Complete profile", href: "/vendor/profile" },
  canSkip: true,
}

export const mockVendorOnboardingComplete: VendorOnboardingState = {
  userId: "mock-vendor",
  currentStep: "complete",
  completedSteps: ["business", "categories", "service_area", "setup", "documents", "first_search"],
  profileCompletion: 88,
  nextAction: { label: "Find events", href: "/vendor/find" },
  canSkip: false,
}
