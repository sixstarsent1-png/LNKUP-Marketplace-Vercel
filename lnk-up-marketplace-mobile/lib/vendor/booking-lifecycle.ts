import { formatMoney } from "@/lib/api/format-money"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { localIsoDay } from "@/lib/format/date"

/**
 * Booking segments shown on the Bookings tab. Definitions match docs/mobile-vendor-v2/DATA_INTEGRITY.md.
 * WordPress supplies event_state / booking_state / payment_context; older servers fall back to dates.
 */
export type BookingSegment = "action" | "applications" | "upcoming" | "past" | "review"

export const BOOKING_SEGMENTS: ReadonlyArray<{ id: Exclude<BookingSegment, "review">; label: string }> = [
  { id: "action", label: "Action needed" },
  { id: "applications", label: "Applications" },
  { id: "upcoming", label: "Upcoming" },
  { id: "past", label: "Past" },
]

const CLOSED_APPLICATION = new Set(["rejected", "cancelled"])

/** Event lifecycle from the server, or derived from the start date for older servers. */
export function eventStateOf(r: VendorRequest, today = localIsoDay()): string {
  if (r.eventState) return r.eventState
  if (!r.eventDateIso) return "unknown"
  return r.eventDateIso < today ? "completed" : "upcoming"
}

export function isEventOver(r: VendorRequest, today = localIsoDay()): boolean {
  const state = eventStateOf(r, today)
  return state === "completed" || state === "cancelled"
}

/** An open, collectible balance. Never inferred from a "Confirmed" label. */
export function hasOpenBalance(r: VendorRequest): boolean {
  if (r.paymentContext) return r.paymentContext.hasBalance && r.paymentContext.collectible
  if (r.status === "payment_required") return true
  return r.status === "approved" && Boolean(r.paymentId)
}

/** Balance for an event that already happened: payable, but labelled as an invoice. */
export function isPastEventInvoice(r: VendorRequest, today = localIsoDay()): boolean {
  if (!hasOpenBalance(r)) return false
  if (r.paymentContext) return r.paymentContext.pastEventInvoice
  return isEventOver(r, today)
}

/** Payment that confirms an upcoming spot. */
export function isUpcomingPaymentDue(r: VendorRequest, today = localIsoDay()): boolean {
  return hasOpenBalance(r) && !isPastEventInvoice(r, today)
}

export function isConfirmed(r: VendorRequest): boolean {
  if (r.bookingState) return r.bookingState === "confirmed"
  return r.status === "paid"
}

export function bookingSegment(r: VendorRequest, today = localIsoDay()): BookingSegment {
  const state = eventStateOf(r, today)
  if (r.eventResolution === "unrecoverable") return "review"
  if (CLOSED_APPLICATION.has(r.status)) return "past"
  if (state === "completed" || state === "cancelled" || r.status === "completed") return "past"
  if (r.status === "info_requested" || isUpcomingPaymentDue(r, today)) return "action"
  if (isConfirmed(r)) return state === "unknown" ? "review" : "upcoming"
  return "applications"
}

export function segmentBookings(requests: VendorRequest[], today = localIsoDay()): Record<BookingSegment, VendorRequest[]> {
  const out: Record<BookingSegment, VendorRequest[]> = { action: [], applications: [], upcoming: [], past: [], review: [] }
  for (const r of requests) out[bookingSegment(r, today)].push(r)
  out.upcoming.sort((a, b) => (a.eventDateIso ?? "9999").localeCompare(b.eventDateIso ?? "9999"))
  out.past.sort((a, b) => (b.eventDateIso ?? "0000").localeCompare(a.eventDateIso ?? "0000"))
  return out
}

/** Next confirmed event (spotlight on Home). Undated bookings never qualify. */
export function nextUpcomingBooking(requests: VendorRequest[], today = localIsoDay()): VendorRequest | null {
  return segmentBookings(requests, today).upcoming.find((r) => Boolean(r.eventDateIso)) ?? null
}

/** "Pay $422.18" or "Pay invoice"; one label everywhere. */
export function payCtaLabel(r: Pick<VendorRequest, "amountDue" | "currency">): string {
  return r.amountDue && r.amountDue > 0 ? `Pay ${formatMoney(r.amountDue, r.currency)}` : "Pay invoice"
}

/** Why a payment is shown, in plain words. Never promises a spot for an event that is over. */
export function paymentReason(r: VendorRequest, today = localIsoDay()): string {
  if (isPastEventInvoice(r, today)) return "Outstanding invoice · past event"
  return "Pay to confirm your booth"
}

/** Pass actions only when the server says the credential is active. */
export function canOpenPass(r: VendorRequest): boolean {
  if (r.credentialState) return r.credentialState === "active"
  return r.status === "paid" && !isEventOver(r)
}

/** Days until the event, or null. */
export function daysUntilEvent(r: VendorRequest, today = localIsoDay()): number | null {
  if (!r.eventDateIso) return null
  const a = Date.parse(`${today}T00:00:00Z`)
  const b = Date.parse(`${r.eventDateIso}T00:00:00Z`)
  return Math.round((b - a) / 86_400_000)
}

export function countdownLabel(r: VendorRequest, today = localIsoDay()): string | null {
  if (eventStateOf(r, today) === "active") return "Happening now"
  const days = daysUntilEvent(r, today)
  if (days == null || days < 0) return null
  if (days === 0) return "Today"
  if (days === 1) return "Tomorrow"
  return `In ${days} days`
}
