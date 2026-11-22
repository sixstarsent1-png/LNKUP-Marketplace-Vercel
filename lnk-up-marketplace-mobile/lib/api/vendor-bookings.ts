import { WpFetchError } from "@/lib/api/errors"
import { normalizeVendorRequestStatus, type VendorRequest } from "@/lib/api/vendor-requests"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { formatShortDate } from "@/lib/format/date"
import type { TimelineState, TimelineStep } from "@/lib/vendor/booking-timeline"

export type BookingPayment = {
  status: string
  total: number
  creditApplied: number
  cardCharged: number
  amountDue: number
  refunded: number
  dueAt?: string
  paidAt?: string
  receipts: Array<{ id: number; number: string; amount: number; createdAt?: string }>
  refunds: Array<{ id: number; amount: number; status: string; createdAt?: string }>
}

export type BookingAnswer = { id: string; label: string; value: string }

export type BookingReadiness = {
  percent: number
  items: Array<{ key: string; label: string; done: boolean }>
}

/** One booking from `GET /bookings/vendor/{id}` (LNKup_Vendor_Bookings::detail). */
export type VendorBooking = {
  /** List-shaped view of the booking, for status pills, primary actions and withdraw. */
  request: VendorRequest
  booth: string
  venue: string
  venueAddress: string
  startTime: string
  passAvailable: boolean
  canWithdraw: boolean
  /** Organizer's note when declining. Cancellation reasons are internal keys; the timeline labels them. */
  rejectionReason: string
  answers: BookingAnswer[]
  payment: BookingPayment | null
  readiness: BookingReadiness | null
  timeline: TimelineStep[]
}

type WpTimelineItem = { key?: string; label?: string; at?: string; state?: string; note?: string }

type WpBookingDetail = {
  application_id?: number
  event_id?: number
  event_name?: string
  organizer_name?: string
  image?: string
  event_date?: string
  start_time?: string
  venue?: string
  city?: string
  state?: string
  booth?: string
  status?: string
  payment_id?: number
  payment_status?: string
  amount_due?: number
  payment_due_at?: string
  pass_available?: boolean
  event?: { venue_address?: string }
  booking?: {
    category?: string
    submitted_at?: string
    answers?: Array<{ id?: string; label?: string; type?: string; value?: unknown }>
    rejection_reason?: string
    can_withdraw?: boolean
  }
  payment?: {
    status?: string
    total?: number
    credit_applied?: number
    card_charged?: number
    amount_due?: number
    refunded?: number
    credit_refunded?: number
    paid_at?: string
    due_at?: string
    receipts?: Array<{ id?: number; receipt_number?: string; amount?: number; created_at?: string }>
    refunds?: Array<{ id?: number; amount?: number; status?: string; created_at?: string }>
  } | null
  readiness?: { percent?: number; items?: Array<{ key?: string; label?: string; done?: boolean }> } | null
  timeline?: WpTimelineItem[]
}

const TIMELINE_STATES: readonly TimelineState[] = ["done", "current", "upcoming", "stopped"]

const dayLabel = formatShortDate

function num(value: unknown): number {
  const n = Number(value)
  return Number.isFinite(n) ? n : 0
}

function answerText(answer: { type?: string; value?: unknown }): string {
  if (answer.type === "checkbox") return answer.value ? "Yes" : "No"
  if (answer.type === "file") return "Document attached"
  if (Array.isArray(answer.value)) return answer.value.map(String).join(", ")
  return answer.value == null ? "" : String(answer.value)
}

function mapTimeline(items: WpTimelineItem[] | undefined): TimelineStep[] {
  if (!Array.isArray(items)) return []
  return items
    .filter((item) => item.key && item.label)
    .map((item) => ({
      key: String(item.key),
      label: String(item.label),
      detail: item.note || dayLabel(item.at),
      state: TIMELINE_STATES.includes(item.state as TimelineState) ? (item.state as TimelineState) : "upcoming",
    }))
}

function mapPayment(raw: WpBookingDetail["payment"]): BookingPayment | null {
  if (!raw) return null
  return {
    status: raw.status ?? "",
    total: num(raw.total),
    creditApplied: num(raw.credit_applied),
    cardCharged: num(raw.card_charged),
    amountDue: num(raw.amount_due),
    refunded: num(raw.refunded) + num(raw.credit_refunded),
    dueAt: dayLabel(raw.due_at),
    paidAt: dayLabel(raw.paid_at),
    receipts: (raw.receipts ?? []).map((r) => ({
      id: num(r.id),
      number: r.receipt_number ?? "",
      amount: num(r.amount),
      createdAt: dayLabel(r.created_at),
    })),
    refunds: (raw.refunds ?? []).map((r) => ({
      id: num(r.id),
      amount: num(r.amount),
      status: r.status ?? "",
      createdAt: dayLabel(r.created_at),
    })),
  }
}

export function mapVendorBooking(data: WpBookingDetail): VendorBooking {
  const booking = data.booking ?? {}
  const location = [data.city, data.state].filter(Boolean).join(", ")
  const readiness = data.readiness
  return {
    request: {
      id: String(data.application_id ?? ""),
      eventId: data.event_id ? String(data.event_id) : undefined,
      eventName: data.event_name || "Event",
      eventDate: formatShortDate(data.event_date) ?? data.event_date,
      eventDateIso: data.event_date ? data.event_date.slice(0, 10) : undefined,
      city: location || undefined,
      state: data.state,
      organizerName: data.organizer_name || undefined,
      category: booking.category || undefined,
      status: normalizeVendorRequestStatus(data.status),
      submittedAt: dayLabel(booking.submitted_at),
      amountDue: num(data.amount_due) > 0 ? num(data.amount_due) : undefined,
      currency: "USD",
      paymentId: data.payment_id ? String(data.payment_id) : undefined,
      paymentStatus: data.payment_status || undefined,
      imageUrl: data.image || undefined,
    },
    booth: data.booth ?? "",
    venue: data.venue ?? "",
    venueAddress: data.event?.venue_address ?? "",
    startTime: data.start_time ?? "",
    passAvailable: Boolean(data.pass_available),
    canWithdraw: Boolean(booking.can_withdraw),
    rejectionReason: booking.rejection_reason ?? "",
    answers: (booking.answers ?? [])
      .filter((a) => a.id && a.label)
      .map((a) => ({ id: String(a.id), label: String(a.label), value: answerText(a) }))
      .filter((a) => a.value !== ""),
    payment: mapPayment(data.payment),
    readiness: readiness
      ? {
          percent: Math.round(num(readiness.percent)),
          items: (readiness.items ?? [])
            .filter((item) => item.label)
            .map((item) => ({ key: String(item.key ?? item.label), label: String(item.label), done: Boolean(item.done) })),
        }
      : null,
    timeline: mapTimeline(data.timeline),
  }
}

/**
 * One booking for its vendor. Resolves `null` when the server has no booking route (older plugin),
 * so callers can fall back to the request list; a 404 for a booking that isn't theirs throws.
 */
export async function fetchVendorBooking(applicationId: string, auth: WpAuth): Promise<VendorBooking | null> {
  try {
    const data = await wpFetchJson<WpBookingDetail>(`/bookings/vendor/${encodeURIComponent(applicationId)}`, { auth })
    return mapVendorBooking(data)
  } catch (err) {
    if (err instanceof WpFetchError && err.code === "rest_no_route") return null
    throw err
  }
}
