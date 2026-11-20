import { cookies } from "next/headers"

const COOKIE_NAME = "lnkup_onboarding_progress"
const MAX_AGE_SECONDS = 60 * 60 * 24 * 30

export type OnboardingStepKey =
  | "business"
  | "categories"
  | "service_area"
  | "setup"
  | "documents"
  | "first_search"

export type OnboardingProgress = {
  completedSteps: OnboardingStepKey[]
  skippedSteps: OnboardingStepKey[]
  lastStep?: OnboardingStepKey
}

export async function getOnboardingProgress(): Promise<OnboardingProgress> {
  const cookieStore = await cookies()
  const raw = cookieStore.get(COOKIE_NAME)?.value
  if (!raw) {
    return { completedSteps: [], skippedSteps: [] }
  }
  try {
    const parsed = JSON.parse(decodeURIComponent(raw)) as OnboardingProgress
    return {
      completedSteps: parsed.completedSteps ?? [],
      skippedSteps: parsed.skippedSteps ?? [],
      lastStep: parsed.lastStep,
    }
  } catch {
    return { completedSteps: [], skippedSteps: [] }
  }
}

export async function setOnboardingProgress(progress: OnboardingProgress): Promise<void> {
  const cookieStore = await cookies()
  const value = encodeURIComponent(JSON.stringify(progress))
  cookieStore.set(COOKIE_NAME, value, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: MAX_AGE_SECONDS,
  })
}
