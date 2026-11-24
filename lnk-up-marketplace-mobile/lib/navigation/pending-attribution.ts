import type { RegisterAttribution } from "@/lib/api/auth"
import * as SecureStore from "@/lib/storage/secure-store"

/**
 * Campaign / referral touch from the link that opened the app (utm_*, click IDs, ref, org_ref, cc,
 * lnk). Kept until signup so WordPress can credit the referral or campaign exactly like a web visit;
 * WordPress re-validates every value. A newer link replaces an older one (last touch).
 */

const STORAGE_KEY = "lnkup_pending_attribution_v1"
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000
const MAX_VALUE_LENGTH = 200

export const ATTRIBUTION_KEYS = [
  "utm_source",
  "utm_medium",
  "utm_campaign",
  "utm_content",
  "utm_term",
  "utm_id",
  "gclid",
  "ttclid",
  "fbclid",
  "ref",
  "org_ref",
  "cc",
  "partner",
  "lnk",
  "market",
  "landing",
] as const satisfies ReadonlyArray<keyof RegisterAttribution>

/** Allowed, non-empty attribution values from a query-like record. */
export function pickAttribution(source: Record<string, string | string[] | null | undefined>): RegisterAttribution {
  const out: RegisterAttribution = {}
  for (const key of ATTRIBUTION_KEYS) {
    const raw = source[key]
    const value = (Array.isArray(raw) ? raw[0] : raw)?.trim() ?? ""
    if (value && value.length <= MAX_VALUE_LENGTH) out[key] = value
  }
  return out
}

/** Attribution carried by a URL's query string, with the landing path. */
export function attributionFromUrl(raw: string): RegisterAttribution {
  try {
    const url = new URL(raw, "https://thelnkup.com")
    const params: Record<string, string> = {}
    url.searchParams.forEach((value, key) => {
      params[key] = value
    })
    const picked = pickAttribution(params)
    if (Object.keys(picked).length === 0) return picked
    return { ...picked, landing: picked.landing ?? url.pathname }
  } catch {
    return {}
  }
}

export async function savePendingAttribution(attribution: RegisterAttribution): Promise<void> {
  const clean = pickAttribution(attribution)
  if (Object.keys(clean).length === 0) return
  await SecureStore.setItemAsync(STORAGE_KEY, JSON.stringify({ at: Date.now(), attribution: clean })).catch(() => undefined)
}

/** Attribution to send with signup (not cleared; call clearPendingAttribution after success). */
export async function readPendingAttribution(): Promise<RegisterAttribution> {
  try {
    const raw = await SecureStore.getItemAsync(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as { at?: number; attribution?: Record<string, string> }
    if (!parsed.at || Date.now() - parsed.at > MAX_AGE_MS) {
      await clearPendingAttribution()
      return {}
    }
    return pickAttribution(parsed.attribution ?? {})
  } catch {
    return {}
  }
}

export async function clearPendingAttribution(): Promise<void> {
  await SecureStore.deleteItemAsync(STORAGE_KEY).catch(() => undefined)
}
