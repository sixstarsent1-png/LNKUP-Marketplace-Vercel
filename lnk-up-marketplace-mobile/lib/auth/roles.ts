import type { MobileSession } from "@/lib/api/auth"
import * as SecureStore from "@/lib/storage/secure-store"

const HOME_PREF_KEY = "lnkup_home_pref"

export type AppHomeRole = "vendor" | "organizer"

const ORGANIZER_ROLES = new Set(["lnkup_organizer", "lnkup_admin", "administrator"])
const VENDOR_ROLES = new Set(["lnkup_vendor"])

export function isOrganizerRole(roles: string[] | undefined): boolean {
  return (roles ?? []).some((role) => ORGANIZER_ROLES.has(role))
}

export function isVendorRole(roles: string[] | undefined): boolean {
  return (roles ?? []).some((role) => VENDOR_ROLES.has(role) || role === "administrator")
}

export function sessionHasOrganizer(session: MobileSession | null): boolean {
  return isOrganizerRole(session?.user.roles)
}

export function sessionHasVendor(session: MobileSession | null): boolean {
  return isVendorRole(session?.user.roles)
}

export async function loadHomePreference(): Promise<AppHomeRole | null> {
  try {
    const value = await SecureStore.getItemAsync(HOME_PREF_KEY)
    if (value === "vendor" || value === "organizer") return value
    return null
  } catch {
    return null
  }
}

export async function saveHomePreference(role: AppHomeRole): Promise<void> {
  await SecureStore.setItemAsync(HOME_PREF_KEY, role)
}

export async function clearHomePreference(): Promise<void> {
  await SecureStore.deleteItemAsync(HOME_PREF_KEY)
}

export type HomeHref = "/(vendor)" | "/(organizer)" | "/no-access"

/**
 * Resolve which shell to open after auth. Dual-role uses last preference, else organizer.
 * Accounts with no marketplace role get the no-access screen (both shells would bounce them to login).
 */
export async function resolveHomeHref(session: MobileSession): Promise<HomeHref> {
  const hasOrg = sessionHasOrganizer(session)
  const hasVendor = sessionHasVendor(session)

  if (hasOrg && !hasVendor) return "/(organizer)"
  if (hasVendor && !hasOrg) return "/(vendor)"

  if (hasOrg && hasVendor) {
    const pref = await loadHomePreference()
    if (pref === "vendor") return "/(vendor)"
    return "/(organizer)"
  }

  return "/no-access"
}
