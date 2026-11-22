import type { MobileSession } from "@/lib/api/auth"
import * as SecureStore from "@/lib/storage/secure-store"

const HOME_PREF_KEY = "lnkup_home_pref"

export type AppHomeRole = "vendor" | "organizer" | "partner"

const ORGANIZER_ROLES = new Set(["lnkup_organizer", "lnkup_admin", "administrator"])
const VENDOR_ROLES = new Set(["lnkup_vendor"])
/** City Connector, affiliate and strategic partner accounts. The server only lets active accounts sign in. */
const PARTNER_ROLES = new Set(["lnkup_connector", "lnkup_partner"])

export function isOrganizerRole(roles: string[] | undefined): boolean {
  return (roles ?? []).some((role) => ORGANIZER_ROLES.has(role))
}

export function isVendorRole(roles: string[] | undefined): boolean {
  return (roles ?? []).some((role) => VENDOR_ROLES.has(role) || role === "administrator")
}

export function isPartnerRole(roles: string[] | undefined): boolean {
  return (roles ?? []).some((role) => PARTNER_ROLES.has(role))
}

export function sessionHasOrganizer(session: MobileSession | null): boolean {
  return isOrganizerRole(session?.user.roles)
}

export function sessionHasVendor(session: MobileSession | null): boolean {
  return isVendorRole(session?.user.roles)
}

export function sessionHasPartner(session: MobileSession | null): boolean {
  return isPartnerRole(session?.user.roles)
}

export async function loadHomePreference(): Promise<AppHomeRole | null> {
  try {
    const value = await SecureStore.getItemAsync(HOME_PREF_KEY)
    if (value === "vendor" || value === "organizer" || value === "partner") return value
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

export type HomeHref = "/(vendor)" | "/(organizer)" | "/(partner)" | "/no-access"

const HOME_FOR: Record<AppHomeRole, HomeHref> = {
  vendor: "/(vendor)",
  organizer: "/(organizer)",
  partner: "/(partner)",
}

/**
 * Resolve which shell to open after auth.
 * Multi-role accounts use the last preference, else organizer, then vendor, then partner.
 * Accounts with no marketplace or partner role get the no-access screen.
 */
export async function resolveHomeHref(session: MobileSession): Promise<HomeHref> {
  const available: AppHomeRole[] = []
  if (sessionHasOrganizer(session)) available.push("organizer")
  if (sessionHasVendor(session)) available.push("vendor")
  if (sessionHasPartner(session)) available.push("partner")

  if (available.length === 0) return "/no-access"
  if (available.length === 1) return HOME_FOR[available[0]]

  const pref = await loadHomePreference()
  if (pref && available.includes(pref)) return HOME_FOR[pref]
  return HOME_FOR[available[0]]
}
