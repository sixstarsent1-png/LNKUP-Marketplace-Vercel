import { wpFetchJson, type WpAuth } from "@/lib/api/wp-fetch"

export type AppUserRole = "vendor" | "organizer" | "admin" | "connector" | "unknown"

export type AppSessionUser = {
  id?: string
  name?: string
  email?: string
  username?: string
  roles: AppUserRole[]
  primaryRole: AppUserRole
  canAccessVendor?: boolean
  canAccessOrganizer?: boolean
  canAccessAdmin?: boolean
}

type WpUserMe = {
  id?: number
  name?: string
  slug?: string
  email?: string
  roles?: string[]
  capabilities?: Record<string, boolean>
}

const ORGANIZER_ROLES = new Set([
  "lnkup_organizer",
  "organizer",
  "lnkup_admin",
  "administrator",
])

const VENDOR_ROLES = new Set(["lnkup_vendor", "vendor"])

const ADMIN_ROLES = new Set(["administrator", "lnkup_admin"])

function mapWpRole(role: string): AppUserRole {
  const r = role.toLowerCase()
  if (ADMIN_ROLES.has(r)) return "admin"
  if (ORGANIZER_ROLES.has(r)) return "organizer"
  if (VENDOR_ROLES.has(r)) return "vendor"
  if (r.includes("connector")) return "connector"
  return "unknown"
}

function hasCap(caps: Record<string, boolean> | undefined, keys: string[]): boolean {
  if (!caps) return false
  return keys.some((k) => caps[k] === true)
}

export const LAST_ROLE_STORAGE_KEY = "lnkup_last_role"

export type RoleHint = {
  intent?: string | null
  lastRole?: AppUserRole | null
}

export function readLastUsedRole(): AppUserRole | null {
  if (typeof window === "undefined") {
    return null
  }
  try {
    const value = window.localStorage.getItem(LAST_ROLE_STORAGE_KEY)
    if (value === "vendor" || value === "organizer" || value === "admin") {
      return value
    }
  } catch {
    return null
  }
  return null
}

export function writeLastUsedRole(role: AppUserRole): void {
  if (typeof window === "undefined") {
    return
  }
  if (role !== "vendor" && role !== "organizer" && role !== "admin") {
    return
  }
  try {
    window.localStorage.setItem(LAST_ROLE_STORAGE_KEY, role)
  } catch {
    // Ignore quota / private-mode failures.
  }
}

function preferredWorkspaceRole(hint?: RoleHint): "vendor" | "organizer" | null {
  const intent = (hint?.intent ?? "").toLowerCase()
  if (intent === "vendor") {
    return "vendor"
  }
  if (intent === "organizer") {
    return "organizer"
  }
  if (hint?.lastRole === "vendor" || hint?.lastRole === "organizer") {
    return hint.lastRole
  }
  return null
}

function resolvePrimaryRole(
  canAccessVendor: boolean,
  canAccessOrganizer: boolean,
  canAccessAdmin: boolean,
  mappedRoles: AppUserRole[],
  hint?: RoleHint,
): AppUserRole {
  const preferred = preferredWorkspaceRole(hint)
  if (preferred === "vendor" && canAccessVendor) {
    return "vendor"
  }
  if (preferred === "organizer" && canAccessOrganizer) {
    return "organizer"
  }

  const hasVendorRole = mappedRoles.includes("vendor")
  const hasOrganizerRole = mappedRoles.includes("organizer")

  if (hasVendorRole && hasOrganizerRole) {
    return "vendor"
  }
  if (hasVendorRole && !hasOrganizerRole && !canAccessAdmin) {
    return "vendor"
  }
  if (hasOrganizerRole && !hasVendorRole) {
    return "organizer"
  }
  if (canAccessAdmin) {
    return "organizer"
  }
  if (canAccessVendor && !canAccessOrganizer) {
    return "vendor"
  }
  if (canAccessOrganizer) {
    return "organizer"
  }
  return mappedRoles[0] ?? "unknown"
}

export function normalizeWpUser(wpUser: WpUserMe, hint?: RoleHint): AppSessionUser {
  const rawRoles = wpUser.roles ?? []
  const roles = Array.from(
    new Set(rawRoles.map(mapWpRole).filter((r) => r !== "unknown")),
  ) as AppUserRole[]

  const caps = wpUser.capabilities
  const canAccessAdmin =
    hasCap(caps, ["manage_options", "lnkup_manage_all"]) ||
    rawRoles.some((r) => ADMIN_ROLES.has(r.toLowerCase()))
  const hasVendorRole =
    hasCap(caps, ["lnkup_vendor"]) ||
    rawRoles.some((r) => VENDOR_ROLES.has(r.toLowerCase()))
  const hasOrganizerRole =
    hasCap(caps, ["lnkup_manage_events", "edit_lnkup_events"]) ||
    rawRoles.some((r) => ORGANIZER_ROLES.has(r.toLowerCase()))
  const canAccessOrganizer = canAccessAdmin || hasOrganizerRole
  const canAccessVendor = canAccessAdmin || hasVendorRole

  const primaryRole = resolvePrimaryRole(
    canAccessVendor,
    canAccessOrganizer,
    canAccessAdmin,
    roles,
    hint,
  )

  return {
    id: wpUser.id ? String(wpUser.id) : undefined,
    name: wpUser.name,
    email: wpUser.email,
    username: wpUser.slug,
    roles: roles.length > 0 ? roles : primaryRole !== "unknown" ? [primaryRole] : [],
    primaryRole,
    canAccessVendor,
    canAccessOrganizer,
    canAccessAdmin,
  }
}

export async function fetchWpUserProfile(auth: WpAuth): Promise<AppSessionUser> {
  const wpUser = await wpFetchJson<WpUserMe>("/users/me", {
    auth,
    namespace: "wp",
    query: { context: "edit" },
  })
  return normalizeWpUser(wpUser)
}

export function sessionToAppUser(session: {
  userId?: number
  displayName?: string
  username: string
  roles?: AppUserRole[]
  primaryRole?: AppUserRole
  canAccessVendor?: boolean
  canAccessOrganizer?: boolean
  canAccessAdmin?: boolean
}): AppSessionUser {
  const primaryRole = session.primaryRole ?? "unknown"
  return {
    id: session.userId ? String(session.userId) : undefined,
    name: session.displayName ?? session.username,
    username: session.username,
    roles: session.roles ?? [primaryRole],
    primaryRole,
    canAccessVendor: session.canAccessVendor,
    canAccessOrganizer: session.canAccessOrganizer,
    canAccessAdmin: session.canAccessAdmin,
  }
}

function isSafeRelativePath(path: string): boolean {
  return path.startsWith("/") && !path.startsWith("//") && !path.includes("://")
}

function canOpenPath(user: AppSessionUser, path: string): boolean {
  if (path === "/admin" || path.startsWith("/admin/") || path.startsWith("/ops") || path.startsWith("/qa")) {
    return false
  }
  if (path.startsWith("/organizer")) {
    return Boolean(user.canAccessOrganizer)
  }
  if (path.startsWith("/vendor")) {
    return Boolean(user.canAccessVendor)
  }
  return false
}

export function resolvePostLoginPath(
  user: AppSessionUser,
  redirect?: string | null,
  hint?: RoleHint,
): string {
  if (redirect && isSafeRelativePath(redirect) && canOpenPath(user, redirect)) {
    return redirect
  }

  const preferred = preferredWorkspaceRole(hint ?? { lastRole: user.primaryRole })
  if (preferred === "vendor" && user.canAccessVendor) {
    return "/vendor"
  }
  if (preferred === "organizer" && user.canAccessOrganizer) {
    return "/organizer"
  }

  const hasVendorRole = user.roles.includes("vendor")
  const hasOrganizerRole = user.roles.includes("organizer")

  if (hasVendorRole && hasOrganizerRole) {
    return "/vendor"
  }
  if (hasVendorRole && !hasOrganizerRole && !user.canAccessAdmin) {
    return "/vendor"
  }
  if (hasOrganizerRole && !hasVendorRole) {
    return "/organizer"
  }
  if (user.canAccessAdmin) {
    return "/organizer"
  }
  if (user.canAccessVendor && !user.canAccessOrganizer) {
    return "/vendor"
  }
  if (user.canAccessOrganizer) {
    return "/organizer"
  }
  if (user.canAccessVendor) {
    return "/vendor"
  }
  return "/"
}
