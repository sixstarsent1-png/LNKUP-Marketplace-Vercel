import * as SecureStore from "@/lib/storage/secure-store"
import { isOrganizerRole, isPartnerRole, isVendorRole } from "@/lib/auth/roles"

/**
 * One place that remembers where the user was trying to go (push tap, deep link, expired session)
 * and hands it back after sign-in — only if the destination is a known in-app route the signed-in
 * roles may open.
 */

const STORAGE_KEY = "lnkup_pending_destination_v1"
const MAX_AGE_MS = 30 * 60 * 1000

type Scope = "vendor" | "organizer" | "partner" | "any"

const ROUTE_RULES: Array<{ pattern: RegExp; scope: Scope }> = [
  { pattern: /^\/\(vendor\)(\/[\w-]+)?(\?[\w=&%.-]*)?$/, scope: "vendor" },
  { pattern: /^\/\(organizer\)(\/[\w-]+){0,2}(\?[\w=&%.-]*)?$/, scope: "organizer" },
  { pattern: /^\/\(partner\)(\/[\w-]+)?(\?[\w=&%.-]*)?$/, scope: "partner" },
  { pattern: /^\/(pass|apply|booking)\/\d+(\?[\w=&%.-]*)?$/, scope: "vendor" },
  { pattern: /^\/opportunity\/\d+(\?[\w=&%.-]*)?$/, scope: "any" },
  { pattern: /^\/tour\/\d+$/, scope: "any" },
  { pattern: /^\/tour\/apply\/\d+$/, scope: "vendor" },
  { pattern: /^\/storefront\/(vendor|organizer)(\?[\w=&%.-]*)?$/, scope: "any" },
]

type Listener = () => void

let memory: { href: string; at: number } | null = null
let suppressUntil = 0
const listeners = new Set<Listener>()

/** After an explicit sign-out the shell's login redirect must not record the screen it left. */
const SIGN_OUT_SUPPRESS_MS = 4000

function normalize(raw: string): string | null {
  let value = raw.trim()
  try {
    value = decodeURIComponent(value)
  } catch {
    return null
  }
  if (!value.startsWith("/") || value.startsWith("//") || value.includes("..")) return null
  return value
}

/** Scope of a destination, or null when it isn't a known in-app route. */
export function destinationScope(raw: string): Scope | null {
  const href = normalize(raw)
  if (!href) return null
  return ROUTE_RULES.find((rule) => rule.pattern.test(href))?.scope ?? null
}

/** Whether the given roles may open the destination. */
export function isDestinationAllowed(raw: string, roles: string[] | undefined): boolean {
  const scope = destinationScope(raw)
  if (!scope) return false
  switch (scope) {
    case "any":
      return isVendorRole(roles) || isOrganizerRole(roles)
    case "vendor":
      return isVendorRole(roles)
    case "organizer":
      return isOrganizerRole(roles)
    case "partner":
      return isPartnerRole(roles)
    default: {
      const unhandled: never = scope
      return unhandled
    }
  }
}

/** Allowed destination (decoded) or null. */
export function safeDestination(raw: string | string[] | undefined | null, roles: string[] | undefined): string | null {
  const value = Array.isArray(raw) ? raw[0] : raw
  if (!value) return null
  const href = normalize(value)
  if (!href) return null
  return isDestinationAllowed(href, roles) ? href : null
}

export function setPendingDestination(raw: string): void {
  if (Date.now() < suppressUntil) return
  const href = normalize(raw)
  if (!href || !destinationScope(href)) return
  memory = { href, at: Date.now() }
  void SecureStore.setItemAsync(STORAGE_KEY, JSON.stringify(memory)).catch(() => undefined)
  listeners.forEach((listener) => listener())
}

async function readPending(): Promise<{ href: string; at: number } | null> {
  if (memory) return memory
  try {
    const raw = await SecureStore.getItemAsync(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as { href?: string; at?: number }
    return parsed.href && parsed.at ? { href: parsed.href, at: parsed.at } : null
  } catch {
    return null
  }
}

export async function clearPendingDestination(): Promise<void> {
  memory = null
  await SecureStore.deleteItemAsync(STORAGE_KEY).catch(() => undefined)
}

/** Explicit sign-out: drop any destination and ignore new ones for a moment. */
export async function resetPendingDestinationForSignOut(): Promise<void> {
  suppressUntil = Date.now() + SIGN_OUT_SUPPRESS_MS
  await clearPendingDestination()
}

/**
 * Return and clear the pending destination when the roles may open it. Expired or forbidden
 * destinations are discarded (a vendor push must not open an organizer screen).
 */
export async function takePendingDestination(roles: string[] | undefined): Promise<string | null> {
  const pending = await readPending()
  if (!pending) return null
  await clearPendingDestination()
  if (Date.now() - pending.at > MAX_AGE_MS) return null
  return isDestinationAllowed(pending.href, roles) ? pending.href : null
}

export function hasPendingDestination(): boolean {
  return memory !== null
}

export function subscribePendingDestination(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}
