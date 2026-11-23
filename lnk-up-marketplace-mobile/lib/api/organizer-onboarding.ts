import type { Href } from "expo-router"

import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson, type WpAuth } from "@/lib/api/wp-client"

export type OnboardingStepKey = "account" | "organization" | "format" | "first_event" | "payouts" | "share"

export type OrganizerOnboarding = {
  percent: number
  isComplete: boolean
  dismissed: boolean
  formats: string[]
  nextStep: { key: OnboardingStepKey; title: string; url: string; missing: string[] } | null
  resumeUrl: string
}

type WpPayload = {
  percent?: number
  is_complete?: boolean
  next_step?: { key: OnboardingStepKey; title: string; url: string; missing?: string[] } | null
  resume_url?: string
  state?: { formats?: string[]; dismissed?: boolean }
}

/**
 * Server guided setup (lnkup/v1/organizer/onboarding). Null when the feature is off (404),
 * the user is not an organizer, or the request fails — the home screen then simply hides the card.
 */
export async function fetchOrganizerOnboarding(auth?: WpAuth): Promise<OrganizerOnboarding | null> {
  if (!isLiveApiEnabled()) {
    return null
  }
  try {
    const res = await wpFetchJson<{ success?: boolean; data?: WpPayload }>("/organizer/onboarding", { auth })
    const d = res?.data
    if (!d) {
      return null
    }
    return {
      percent: Number(d.percent ?? 0),
      isComplete: Boolean(d.is_complete),
      dismissed: Boolean(d.state?.dismissed),
      formats: Array.isArray(d.state?.formats) ? d.state.formats : [],
      nextStep: d.next_step
        ? { key: d.next_step.key, title: d.next_step.title, url: d.next_step.url, missing: d.next_step.missing ?? [] }
        : null,
      resumeUrl: String(d.resume_url ?? ""),
    }
  } catch {
    return null
  }
}

const TOUR_FORMATS = ["tour", "recurring", "series", "company"]

/**
 * Native screen for a setup step, or null when it should open on LNKup web (tour/series setup
 * and the format choice live in the web wizard).
 */
export function nativeRouteForStep(step: OnboardingStepKey, formats: string[]): Href | null {
  switch (step) {
    case "organization":
      return "/(organizer)/profile"
    case "first_event":
      return formats.length > 0 && TOUR_FORMATS.includes(formats[0]) ? null : "/(organizer)/launch"
    case "payouts":
      return "/(organizer)/money"
    case "share":
      return "/(organizer)/events"
    case "account":
    case "format":
      return null
    default: {
      const unreachable: never = step
      return unreachable
    }
  }
}
