import { isLiveApiEnabled } from "@/lib/api/config"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { normalizeOrganizerRequestStatus } from "@/lib/api/organizer-requests"
import type { ApplicationStatus } from "@/lib/status/application-status"

export type RosterGroup =
  | "applied"
  | "approved"
  | "paid"
  | "missing_payment"
  | "missing_documents"
  | "checked_in"
  | "no_shows"
  | "waitlisted"
  | "rejected"

export type RosterEntry = {
  applicationId: string
  vendorUserId?: string
  profileId?: string
  businessName: string
  status: ApplicationStatus
  group: RosterGroup
  booth: string
  checkedIn: boolean
  checkedInAt?: string
  noShow: boolean
  readinessLabel?: string
  opsLabel?: string
  /** Why check-in would be blocked (payment_pending, documents_missing, …). */
  blockReason?: string
  blockMessage?: string
  /** Event-day actions the server allows right now (checked_in, setup_started, …). */
  actions: string[]
}

export type EventRoster = {
  eventId: string
  entries: RosterEntry[]
}

type WpRosterRow = {
  application_id?: number
  vendor_user_id?: number
  profile_id?: number
  business_name?: string
  status?: string
  booth?: string
  checked_in?: boolean
  checked_in_at?: string
  no_show?: boolean
  readiness_label?: string
  ops_label?: string
  reason?: string
  message?: string
  actions?: string[]
}

/** Server group keys; a checked-in vendor moves from `paid` to `checked_in`, so every group is read. */
const GROUPS: RosterGroup[] = [
  "checked_in",
  "paid",
  "approved",
  "missing_payment",
  "missing_documents",
  "no_shows",
  "applied",
  "waitlisted",
  "rejected",
]

const BOOKED_STATUSES = new Set<ApplicationStatus>(["approved", "payment_required", "paid", "completed"])
const ROSTER_PREFIX = "organizer.roster:"

export function mapRoster(eventId: string, data: unknown): EventRoster {
  const groups = ((data as { groups?: Record<string, unknown> })?.groups ?? {}) as Record<string, unknown>
  const entries: RosterEntry[] = []
  const seen = new Set<string>()
  for (const group of GROUPS) {
    const rows = Array.isArray(groups[group]) ? (groups[group] as WpRosterRow[]) : []
    for (const row of rows) {
      if (row.application_id == null || seen.has(String(row.application_id))) continue
      seen.add(String(row.application_id))
      entries.push({
        applicationId: String(row.application_id),
        vendorUserId: row.vendor_user_id ? String(row.vendor_user_id) : undefined,
        profileId: row.profile_id ? String(row.profile_id) : undefined,
        businessName: row.business_name || "Vendor",
        status: normalizeOrganizerRequestStatus(row.status),
        group,
        booth: row.booth ?? "",
        checkedIn: Boolean(row.checked_in),
        checkedInAt: row.checked_in_at || undefined,
        noShow: Boolean(row.no_show),
        readinessLabel: row.readiness_label || undefined,
        opsLabel: row.ops_label || undefined,
        blockReason: row.reason && row.reason !== "ready" ? row.reason : undefined,
        blockMessage: row.message || undefined,
        actions: Array.isArray(row.actions) ? row.actions.map(String) : [],
      })
    }
  }
  return { eventId, entries }
}

/** Event roster with booth, readiness and check-in state per application (owner/staff only). */
export async function fetchEventRoster(
  eventId: string,
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<EventRoster> {
  if (!isLiveApiEnabled()) return { eventId, entries: [] }
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  return cachedRequest(
    `${ROSTER_PREFIX}${eventId}`,
    async () => mapRoster(eventId, await wpFetchJson<unknown>(`/crm/events/${eventId}/roster`, { auth })),
    { ttlMs: 10_000, ...options },
  )
}

export function invalidateEventRoster(eventId?: string): void {
  invalidateCache(eventId ? `${ROSTER_PREFIX}${eventId}` : ROSTER_PREFIX)
}

/** Confirmed vendors (paid, including those already checked in) — the people who show up on event day. */
export function confirmedVendors(roster: EventRoster): RosterEntry[] {
  return roster.entries.filter((entry) => entry.status === "paid" || entry.status === "completed")
}

/** Approved vendors who hold a spot, paid or not; the event-day check-in list. */
export function bookedVendors(roster: EventRoster): RosterEntry[] {
  return roster.entries.filter((entry) => BOOKED_STATUSES.has(entry.status))
}
