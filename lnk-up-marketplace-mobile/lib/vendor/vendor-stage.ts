import type { VendorRequest } from "@/lib/api/vendor-requests"
import { vendorLifecycleBucket } from "@/lib/status/application-status"
import { eventStateOf, isConfirmed, isEventOver, isUpcomingPaymentDue } from "@/lib/vendor/booking-lifecycle"

/** Where the vendor is in the marketplace journey; drives what Home leads with. */
export type VendorStage = "new" | "awaiting" | "action" | "confirmed" | "exploring"

export type VendorNextStep =
  | { kind: "reply"; request: VendorRequest }
  | { kind: "pay"; request: VendorRequest }
  | { kind: "prepare"; request: VendorRequest }
  | { kind: "track"; count: number }
  | { kind: "profile"; completion: number }
  | { kind: "explore" }

function isoToday(now: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

function isoPlusDays(now: Date, days: number): string {
  const next = new Date(now)
  next.setDate(next.getDate() + days)
  return isoToday(next)
}

/** End-date aware (server `event_state` when present). */
export function isEventPast(request: VendorRequest, now: Date = new Date()): boolean {
  return isEventOver(request, isoToday(now))
}

export function lifecycleOf(request: VendorRequest, now: Date = new Date()) {
  return vendorLifecycleBucket(request.status, isEventPast(request, now))
}

/** Paid bookings whose event is today or tomorrow, soonest first. */
export function eventDayBookings(requests: VendorRequest[], now: Date = new Date()): VendorRequest[] {
  const today = isoToday(now)
  const tomorrow = isoPlusDays(now, 1)
  return requests
    .filter((r) => isConfirmed(r) && (r.eventDateIso === today || r.eventDateIso === tomorrow || eventStateOf(r, today) === "active"))
    .sort((a, b) => (a.eventDateIso ?? "").localeCompare(b.eventDateIso ?? ""))
}

export function isEventToday(request: VendorRequest, now: Date = new Date()): boolean {
  return request.eventDateIso === isoToday(now)
}

/**
 * The single most useful next step, in priority order: answer an organizer, pay to confirm,
 * prepare for the next confirmed event, then track pending applications, finish the profile,
 * or explore. Only real records produce a step.
 */
export function vendorNextStep(
  requests: VendorRequest[],
  profileCompletion: number | null,
  now: Date = new Date(),
): { stage: VendorStage; step: VendorNextStep } {
  const live = requests.filter((r) => !isEventPast(r, now))
  const reply = live.find((r) => r.status === "info_requested")
  if (reply) return { stage: "action", step: { kind: "reply", request: reply } }

  const pay = live.find((r) => isUpcomingPaymentDue(r, isoToday(now)))
  if (pay) return { stage: "action", step: { kind: "pay", request: pay } }

  const confirmed = live
    .filter((r) => isConfirmed(r) && Boolean(r.eventDateIso))
    .sort((a, b) => (a.eventDateIso ?? "9999").localeCompare(b.eventDateIso ?? "9999"))[0]
  if (confirmed) return { stage: "confirmed", step: { kind: "prepare", request: confirmed } }

  const pending = live.filter((r) => lifecycleOf(r, now) === "review").length
  if (pending > 0) return { stage: "awaiting", step: { kind: "track", count: pending } }

  if (requests.length === 0 && profileCompletion != null && profileCompletion < 60) {
    return { stage: "new", step: { kind: "profile", completion: profileCompletion } }
  }
  return { stage: requests.length === 0 ? "new" : "exploring", step: { kind: "explore" } }
}

export function vendorLifecycleCounts(requests: VendorRequest[], now: Date = new Date()) {
  const counts = { action: 0, review: 0, confirmed: 0, past: 0, closed: 0 }
  for (const request of requests) counts[lifecycleOf(request, now)] += 1
  return counts
}
