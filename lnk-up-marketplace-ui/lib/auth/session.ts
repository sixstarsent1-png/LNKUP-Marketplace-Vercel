import { cookies } from "next/headers"
import { createCipheriv, createDecipheriv, createHash, randomBytes } from "crypto"
import { getSessionSecret } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-fetch"
import type { AppUserRole } from "@/lib/auth/app-user"

const COOKIE_NAME = "lnkup_wp_session"
const MAX_AGE_SECONDS = 60 * 60 * 24 * 7

export type SessionUser = WpAuth & {
  displayName?: string
  userId?: number
  roles?: AppUserRole[]
  primaryRole?: AppUserRole
  canAccessVendor?: boolean
  canAccessOrganizer?: boolean
  canAccessAdmin?: boolean
}

function deriveKey(secret: string): Buffer {
  return createHash("sha256").update(secret).digest()
}

function encryptPayload(payload: string, secret: string): string {
  const key = deriveKey(secret)
  const iv = randomBytes(12)
  const cipher = createCipheriv("aes-256-gcm", key, iv)
  const encrypted = Buffer.concat([cipher.update(payload, "utf8"), cipher.final()])
  const tag = cipher.getAuthTag()
  return Buffer.concat([iv, tag, encrypted]).toString("base64url")
}

function decryptPayload(token: string, secret: string): string | null {
  try {
    const key = deriveKey(secret)
    const data = Buffer.from(token, "base64url")
    const iv = data.subarray(0, 12)
    const tag = data.subarray(12, 28)
    const encrypted = data.subarray(28)
    const decipher = createDecipheriv("aes-256-gcm", key, iv)
    decipher.setAuthTag(tag)
    const decrypted = Buffer.concat([decipher.update(encrypted), decipher.final()])
    return decrypted.toString("utf8")
  } catch {
    return null
  }
}

export async function getSession(): Promise<SessionUser | null> {
  const cookieStore = await cookies()
  const token = cookieStore.get(COOKIE_NAME)?.value
  if (!token) return null

  let secret: string
  try {
    secret = getSessionSecret()
  } catch {
    return null
  }

  const raw = decryptPayload(token, secret)
  if (!raw) return null

  try {
    const parsed = JSON.parse(raw) as SessionUser
    if (!parsed.username || !parsed.appPassword) return null
    return parsed
  } catch {
    return null
  }
}

export async function setSession(user: SessionUser): Promise<void> {
  const secret = getSessionSecret()
  const token = encryptPayload(JSON.stringify(user), secret)
  const cookieStore = await cookies()
  cookieStore.set(COOKIE_NAME, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: MAX_AGE_SECONDS,
  })
}

export async function clearSession(): Promise<void> {
  const cookieStore = await cookies()
  cookieStore.set(COOKIE_NAME, "", {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: 0,
  })
}

export function toWpAuth(session: SessionUser): WpAuth {
  return {
    username: session.username,
    appPassword: session.appPassword,
  }
}
