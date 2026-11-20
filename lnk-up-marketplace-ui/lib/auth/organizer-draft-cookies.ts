import { cookies } from "next/headers"

const ONBOARDING_COOKIE = "lnkup_organizer_onboarding"
const LAUNCH_COOKIE = "lnkup_organizer_launch_draft"
const MAX_AGE_SECONDS = 60 * 60 * 24 * 30

export async function getOrganizerOnboardingCookie<T>(): Promise<T | null> {
  const cookieStore = await cookies()
  const raw = cookieStore.get(ONBOARDING_COOKIE)?.value
  if (!raw) return null
  try {
    return JSON.parse(decodeURIComponent(raw)) as T
  } catch {
    return null
  }
}

export async function setOrganizerOnboardingCookie<T>(value: T): Promise<void> {
  const cookieStore = await cookies()
  cookieStore.set(ONBOARDING_COOKIE, encodeURIComponent(JSON.stringify(value)), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: MAX_AGE_SECONDS,
  })
}

export async function getOrganizerLaunchDraftCookie<T>(): Promise<T | null> {
  const cookieStore = await cookies()
  const raw = cookieStore.get(LAUNCH_COOKIE)?.value
  if (!raw) return null
  try {
    return JSON.parse(decodeURIComponent(raw)) as T
  } catch {
    return null
  }
}

export async function setOrganizerLaunchDraftCookie<T>(value: T): Promise<void> {
  const cookieStore = await cookies()
  cookieStore.set(LAUNCH_COOKIE, encodeURIComponent(JSON.stringify(value)), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: MAX_AGE_SECONDS,
  })
}

export async function clearOrganizerLaunchDraftCookie(): Promise<void> {
  const cookieStore = await cookies()
  cookieStore.set(LAUNCH_COOKIE, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: 0,
  })
}
