import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import {
  bookedVendors,
  invalidateEventRoster,
  type EventRoster,
  type RosterEntry,
} from "@/lib/api/organizer-roster"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/** Gates an owner or operations lead may bypass with a written reason (server enforces both). */
const OVERRIDABLE_REASONS = new Set(["payment_pending", "documents"])

export type CheckInFilter = "all" | "waiting" | "checked_in" | "attention"

export const CHECK_IN_FILTERS: ReadonlyArray<{ key: CheckInFilter; label: string }> = [
  { key: "waiting", label: "Not checked in" },
  { key: "checked_in", label: "Checked in" },
  { key: "attention", label: "Needs attention" },
  { key: "all", label: "All" },
]

export type CheckInOutcome =
  | { kind: "checked_in"; message: string; override: boolean }
  | { kind: "already"; message: string }
  | { kind: "blocked"; message: string; reason: string; canOverride: boolean }
  | { kind: "offline"; message: string }
  | { kind: "unconfirmed"; message: string }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string }

export type EventBooth = {
  id: string
  number: string
  typeLabel: string
  applicationId?: string
  vendorName?: string
}

/** One row per booked application; checked-in vendors stay on the list. */
export function eventDayEntries(roster: EventRoster): RosterEntry[] {
  return bookedVendors(roster)
}

export function needsAttention(entry: RosterEntry): boolean {
  return !entry.checkedIn && Boolean(entry.blockReason) && entry.blockReason !== "ready"
}

export function canOverride(entry: RosterEntry): boolean {
  return !entry.checkedIn && OVERRIDABLE_REASONS.has(entry.blockReason ?? "")
}

export function canCheckIn(entry: RosterEntry): boolean {
  return !entry.checkedIn && entry.actions.includes("checked_in")
}

export function matchesCheckInFilter(entry: RosterEntry, filter: CheckInFilter): boolean {
  switch (filter) {
    case "all":
      return true
    case "waiting":
      return !entry.checkedIn
    case "checked_in":
      return entry.checkedIn
    case "attention":
      return needsAttention(entry)
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

export function matchesCheckInSearch(entry: RosterEntry, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return entry.businessName.toLowerCase().includes(q) || entry.booth.toLowerCase() === q
}

export function checkInCounts(entries: RosterEntry[]): { expected: number; checkedIn: number; attention: number } {
  return {
    expected: entries.length,
    checkedIn: entries.filter((entry) => entry.checkedIn).length,
    attention: entries.filter(needsAttention).length,
  }
}

/** Plain-language result for a failed check-in call; never implies the check-in was saved. */
export function checkInOutcomeFromError(error: unknown): CheckInOutcome {
  if (!(error instanceof WpFetchError)) {
    return { kind: "error", message: "Not recorded. Try again." }
  }
  if (error.code === "network_error" || error.status === 0) {
    return { kind: "offline", message: "Not recorded — you appear to be offline. Try again when you're connected." }
  }
  if (error.status === 408) {
    return { kind: "unconfirmed", message: "LNKup didn't confirm in time. Refreshing the list to check." }
  }
  if (error.status === 403) {
    return { kind: "denied", message: error.message || "You can't run check-in for this event." }
  }
  if (error.status === 409 && error.code) {
    return {
      kind: "blocked",
      reason: error.code,
      message: error.message || "This vendor can't be checked in yet.",
      canOverride: OVERRIDABLE_REASONS.has(error.code),
    }
  }
  if (error.status === 404) {
    return { kind: "error", message: error.message || "That pass or booking wasn't found." }
  }
  return { kind: "error", message: "Not recorded. Try again." }
}

type CheckInResponse = { success?: boolean; reason?: string; message?: string; override?: boolean }

function outcomeFromResponse(raw: CheckInResponse, fallback: string): CheckInOutcome {
  if (raw.reason === "already_checked_in") {
    return { kind: "already", message: raw.message || "Already checked in." }
  }
  return { kind: "checked_in", message: fallback, override: Boolean(raw.override) }
}

function requireLive(auth?: WpAuth): asserts auth is WpAuth {
  if (!isLiveApiEnabled()) {
    throw new WpFetchError("Check-in needs a live LNKup connection.", "fixture", 400)
  }
  if (!auth) throw new WpFetchError("Sign in to run check-in.", "auth_required", 401)
}

/** Manual check-in from the roster. Safe to retry: a repeat reports "already checked in". */
export async function checkInApplication(
  auth: WpAuth | undefined,
  eventId: string,
  applicationId: string,
): Promise<CheckInOutcome> {
  requireLive(auth)
  try {
    const raw = await wpFetchJson<CheckInResponse>(`/operations/applications/${applicationId}/checkin`, {
      auth,
      method: "POST",
      body: { action: "checked_in", method: "manual" },
    })
    return outcomeFromResponse(raw, "Checked in.")
  } catch (error) {
    return checkInOutcomeFromError(error)
  } finally {
    invalidateEventRoster(eventId)
  }
}

/** Audited override of the payment/documents gate; the server requires owner or operations staff. */
export async function overrideCheckIn(
  auth: WpAuth | undefined,
  eventId: string,
  applicationId: string,
  reason: string,
): Promise<CheckInOutcome> {
  requireLive(auth)
  try {
    const raw = await wpFetchJson<CheckInResponse>(
      `/operations/events/${eventId}/vendors/${applicationId}/status`,
      { auth, method: "PUT", body: { status: "checked_in", override: true, reason } },
    )
    return outcomeFromResponse(raw, "Checked in with override.")
  } catch (error) {
    return checkInOutcomeFromError(error)
  } finally {
    invalidateEventRoster(eventId)
  }
}

/** Typed pass code (the QR token printed on the vendor's pass). */
export async function checkInByCode(
  auth: WpAuth | undefined,
  eventId: string,
  code: string,
): Promise<CheckInOutcome & { businessName?: string }> {
  requireLive(auth)
  try {
    const raw = await wpFetchJson<CheckInResponse & { business_name?: string }>("/operations/qr/scan", {
      auth,
      method: "POST",
      body: { token: code.trim(), event_id: Number(eventId) },
    })
    const name = raw.business_name || undefined
    return { ...outcomeFromResponse(raw, name ? `${name} checked in.` : "Checked in."), businessName: name }
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return { kind: "error", message: "That code doesn't match a pass. Check it and try again." }
    }
    return checkInOutcomeFromError(error)
  } finally {
    invalidateEventRoster(eventId)
  }
}

export function mapEventBooths(raw: unknown): EventBooth[] {
  const items = (raw as { items?: unknown[] } | null)?.items
  if (!Array.isArray(items)) return []
  return items.flatMap((item) => {
    if (!item || typeof item !== "object") return []
    const row = item as Record<string, unknown>
    if (row.id == null) return []
    const appId = Number(row.application_id ?? 0)
    return [
      {
        id: String(row.id),
        number: String(row.booth_number ?? row.id),
        typeLabel: String(row.booth_type_label ?? ""),
        applicationId: appId > 0 ? String(appId) : undefined,
        vendorName: row.vendor_name ? String(row.vendor_name) : undefined,
      },
    ]
  })
}

export async function fetchEventBooths(auth: WpAuth | undefined, eventId: string): Promise<EventBooth[]> {
  if (!isLiveApiEnabled()) return []
  if (!auth) throw new WpFetchError("Sign in to see booths.", "auth_required", 401)
  return mapEventBooths(await wpFetchJson<unknown>(`/operations/events/${eventId}/booths`, { auth }))
}

/** Assign (or move) one vendor to a booth; the server frees their previous booth. */
export async function assignBooth(
  auth: WpAuth | undefined,
  eventId: string,
  boothId: string,
  applicationId: string,
): Promise<void> {
  requireLive(auth)
  try {
    await wpFetchJson(`/operations/events/${eventId}/booths/${boothId}/assign`, {
      auth,
      method: "POST",
      body: { application_id: Number(applicationId) },
    })
  } finally {
    invalidateEventRoster(eventId)
  }
}
