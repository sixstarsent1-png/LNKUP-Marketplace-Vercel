import type { CheckinPopulations, CheckinReason, CheckinRosterRow } from "@/lib/api/organizer-mobile"
import type { RosterEntry } from "@/lib/api/organizer-roster"

export type CheckinFilter = "expected" | "checked_in" | "attention" | "all"

export const CHECKIN_FILTERS: ReadonlyArray<{ key: CheckinFilter; label: string }> = [
  { key: "expected", label: "Not arrived" },
  { key: "checked_in", label: "Checked in" },
  { key: "attention", label: "Needs attention" },
  { key: "all", label: "All" },
]

/** Gates an owner or operations lead may bypass with a written reason (the server enforces who). */
const OVERRIDABLE: ReadonlySet<CheckinReason> = new Set(["payment_pending", "documents"])
const BLOCKED: ReadonlySet<CheckinReason> = new Set(["payment_pending", "documents", "invalid_booking"])

export function isBlocked(row: CheckinRosterRow): boolean {
  return !row.checkedIn && BLOCKED.has(row.reason)
}

export function canOverrideRow(row: CheckinRosterRow): boolean {
  return !row.checkedIn && OVERRIDABLE.has(row.reason)
}

export function canCheckInRow(row: CheckinRosterRow): boolean {
  return !row.checkedIn && row.eligible && row.actions.includes("checked_in")
}

export function matchesCheckinFilter(row: CheckinRosterRow, filter: CheckinFilter): boolean {
  switch (filter) {
    case "expected":
      return !row.checkedIn && !isBlocked(row)
    case "checked_in":
      return row.checkedIn
    case "attention":
      return isBlocked(row)
    case "all":
      return true
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

/** Name, category or booth number. */
export function matchesCheckinSearch(row: CheckinRosterRow, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return row.vendorName.toLowerCase().includes(q) || row.category.toLowerCase().includes(q) || row.booth.toLowerCase() === q
}

/** Same rules as the server summary, for older servers that only send the roster. */
export function populationsFromRows(rows: CheckinRosterRow[]): CheckinPopulations {
  const blocked = { paymentPending: 0, documents: 0 }
  let expected = 0
  let checkedIn = 0
  let noShow = 0
  let invalid = 0
  for (const row of rows) {
    if (row.reason === "invalid_booking") invalid++
    else if (row.reason === "payment_pending") blocked.paymentPending++
    else if (row.reason === "documents") blocked.documents++
    else {
      expected++
      if (row.checkedIn) checkedIn++
      else if (row.opsStatus === "no_show") noShow++
    }
  }
  return {
    booked: rows.length,
    expected,
    checkedIn,
    waiting: expected - checkedIn - noShow,
    noShow,
    invalid,
    blocked,
    arrivalPct: expected ? Math.round((100 * checkedIn) / expected) : 0,
  }
}

/** "3 of 6 eligible checked in · 2 blocked" */
export function populationLine(p: CheckinPopulations): string {
  const blocked = p.blocked.paymentPending + p.blocked.documents
  return [`${p.checkedIn} of ${p.expected} eligible checked in`, blocked > 0 ? `${blocked} blocked` : null].filter(Boolean).join(" · ")
}

export function rowFromRoster(entry: RosterEntry): CheckinRosterRow {
  const raw = entry.blockReason === "documents_missing" ? "documents" : entry.blockReason
  const reason = (entry.checkedIn ? "already_checked_in" : (raw as CheckinReason | undefined) ?? "ready") as CheckinReason
  return {
    applicationId: entry.applicationId,
    vendorName: entry.businessName,
    category: "",
    booth: entry.booth,
    reason,
    eligible: entry.actions.includes("checked_in"),
    checkedIn: entry.checkedIn,
    checkedInAt: entry.checkedInAt ?? "",
    opsStatus: entry.noShow ? "no_show" : "",
    actions: entry.actions,
  }
}

/** Plain-language reason a vendor can't be checked in. */
export function reasonCopy(reason: CheckinReason): string {
  switch (reason) {
    case "payment_pending":
      return "Booth fee not paid yet."
    case "documents":
      return "Required documents need attention."
    case "invalid_booking":
      return "This booking isn't active (cancelled or declined)."
    case "wrong_event":
      return "This pass is for a different event."
    case "event_completed":
      return "This event is closed for check-in."
    case "forbidden":
      return "You can't run check-in for this event."
    case "already_checked_in":
      return "Already checked in."
    case "not_found":
      return "Booking not found."
    case "conflict":
      return "That isn't possible from the vendor's current status."
    case "ready":
      return "Ready to check in."
    default: {
      const exhaustive: never = reason
      return exhaustive
    }
  }
}
