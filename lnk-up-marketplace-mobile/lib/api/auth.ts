import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import * as SecureStore from "@/lib/storage/secure-store"

const SESSION_KEY = "lnkup_wp_session"

export type MobileSession = {
  username: string
  /** @deprecated Legacy Application Password Basic auth; prefer token. */
  appPassword: string
  /** Bearer token from POST /lnkup/v1/auth/login (website password). */
  token?: string
  /** Token expiry (unix seconds) from the login response. */
  expiresAt?: number
  user: {
    id: number
    name: string
    slug: string
    roles: string[]
  }
}

type AuthLoginResponse = {
  token: string
  expires_at?: number
  user: {
    id: number
    name: string
    slug: string
    roles?: string[]
  }
}

export async function loadSession(): Promise<MobileSession | null> {
  try {
    const raw = await SecureStore.getItemAsync(SESSION_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as MobileSession
    if (!parsed?.username) return null
    if (!parsed.token && !parsed.appPassword) return null
    if (isSessionExpired(parsed)) {
      await clearSession()
      return null
    }
    return parsed
  } catch {
    return null
  }
}

/** True once a token session is past its server expiry (with a minute of clock-skew margin). */
export function isSessionExpired(session: MobileSession, nowMs: number = Date.now()): boolean {
  if (!session.token || !session.expiresAt) return false
  return session.expiresAt * 1000 <= nowMs + 60_000
}

function expiresAtFrom(value: unknown): number | undefined {
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? n : undefined
}

export async function saveSession(session: MobileSession): Promise<void> {
  await SecureStore.setItemAsync(SESSION_KEY, JSON.stringify(session))
}

export async function clearSession(): Promise<void> {
  await SecureStore.deleteItemAsync(SESSION_KEY)
}

/**
 * Sign in with WordPress website username + password.
 * Server validates via wp_authenticate and returns a Bearer token.
 */
export async function loginWithSitePassword(
  username: string,
  password: string,
): Promise<MobileSession> {
  const body = await wpFetchJson<AuthLoginResponse>("/auth/login", {
    method: "POST",
    body: {
      username: username.trim(),
      password: password.trim(),
    },
  })

  if (!body?.token || !body?.user?.id) {
    throw new Error("Invalid login response from LNKup")
  }

  const session: MobileSession = {
    username: username.trim(),
    appPassword: "",
    token: body.token,
    expiresAt: expiresAtFrom(body.expires_at),
    user: {
      id: body.user.id,
      name: body.user.name,
      slug: body.user.slug,
      roles: Array.isArray(body.user.roles) ? body.user.roles : [],
    },
  }

  await saveSession(session)
  return session
}

export type RegisterPayload = {
  email: string
  password: string
  passwordConfirm: string
  role: "vendor" | "organizer"
  fullName: string
  terms: boolean
  /** Organizer basics; WordPress stores them as the organizer profile when guided setup is on. */
  organizationName?: string
  city?: string
  state?: string
  /** Campaign / referral touch captured from the link that opened the app (sanitized by WordPress). */
  attribution?: RegisterAttribution
}

/** Keys WordPress accepts in the registration `attribution` payload (LNKup_Acquisition_Attribution). */
export type RegisterAttribution = Partial<
  Record<
    | "utm_source"
    | "utm_medium"
    | "utm_campaign"
    | "utm_content"
    | "utm_term"
    | "utm_id"
    | "gclid"
    | "ttclid"
    | "fbclid"
    | "ref"
    | "org_ref"
    | "cc"
    | "partner"
    | "lnk"
    | "market"
    | "landing",
    string
  >
>

/**
 * Create a vendor or organizer account and return the same session as login.
 */
export async function registerWithSitePassword(payload: RegisterPayload): Promise<MobileSession> {
  const body = await wpFetchJson<AuthLoginResponse>("/auth/register", {
    method: "POST",
    body: {
      email: payload.email.trim(),
      password: payload.password,
      password_confirm: payload.passwordConfirm,
      role: payload.role,
      full_name: payload.fullName.trim(),
      terms: payload.terms,
      ...(payload.attribution && Object.keys(payload.attribution).length > 0
        ? { attribution: { ...payload.attribution, device: "app" } }
        : {}),
      ...(payload.role === "organizer"
        ? {
            organization_name: (payload.organizationName ?? "").trim(),
            city: (payload.city ?? "").trim(),
            state: (payload.state ?? "").trim(),
          }
        : {}),
    },
  })

  if (!body?.token || !body?.user?.id) {
    throw new Error("Invalid register response from LNKup")
  }

  const session: MobileSession = {
    username: body.user.slug || payload.email.trim(),
    appPassword: "",
    token: body.token,
    expiresAt: expiresAtFrom(body.expires_at),
    user: {
      id: body.user.id,
      name: body.user.name,
      slug: body.user.slug,
      roles: Array.isArray(body.user.roles) ? body.user.roles : [],
    },
  }

  await saveSession(session)
  return session
}

/** @deprecated Use loginWithSitePassword */
export const loginWithAppPassword = loginWithSitePassword

export function sessionToAuth(session: MobileSession): WpAuth {
  return {
    username: session.username,
    appPassword: session.appPassword || "",
    token: session.token,
  }
}
