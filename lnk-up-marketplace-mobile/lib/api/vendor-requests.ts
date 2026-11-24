import { withFixtureFallback } from "@/lib/api/fixture-honesty"
import {
  cachedRequest,
  invalidateCache,
  subscribeCache,
  type CachedRequestOptions,
} from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import type { WpVendorApplicationItem, WpVendorApplicationsResponse } from "@/lib/api/types"
import { mapEventMedia } from "@/lib/api/event-media"
import { MOCK_REQUESTS } from "@/lib/data/fixtures"
import { formatShortDate, localIsoDay } from "@/lib/format/date"
import {
  applicationStatusLabel,
  applicationStatusTone,
  type ApplicationStatus,
} from "@/lib/status/application-status"
import type { StatusTone } from "@/lib/theme"
import {
  bookingSegment,
  hasOpenBalance,
  isEventOver,
  isPastEventInvoice,
  isUpcomingPaymentDue,
} from "@/lib/vendor/booking-lifecycle"

export type VendorRequestStatus = ApplicationStatus

export type VendorRequest = {
  id: string
  opportunityId?: string
  eventId?: string
  eventName: string
  eventDate?: string
  /** Raw Y-m-d from WordPress, for date comparisons (eventDate is display text). */
  eventDateIso?: string
  city?: string
  state?: string
  organizerName?: string
  category?: string
  status: VendorRequestStatus
  submittedAt?: string
  amountDue?: number
  currency?: string
  paymentId?: string
  /** Payment status; a processing or paid payment blocks withdrawal. */
  paymentStatus?: string
  /** Open organizer question when status is info_requested. */
  infoRequest?: VendorInfoRequest
  /** Event cover image from WordPress. */
  imageUrl?: string
  /** Customer-facing status from the WordPress presentation contract (e.g. "Invoice open"). */
  primaryStatus?: string
  venue?: string
  /** Vendor option title (booth / category) the vendor applied to. */
  opportunityTitle?: string
  eventEndDateIso?: string
  /** live | opportunity | snapshot | unrecoverable (how WordPress found the event). */
  eventResolution?: string
  /** upcoming | active | completed | cancelled | postponed | unknown (end-date aware). */
  eventState?: string
  applicationState?: string
  bookingState?: string
  paymentState?: string
  /** locked | active | ended | revoked */
  credentialState?: string
  paymentContext?: { hasBalance: boolean; pastEventInvoice: boolean; collectible: boolean }
  /** Present on the single-booking endpoint only. */
  timeline?: Array<{ key: string; label: string; state: "done" | "current" | "pending"; at?: string }>
}

export type VendorInfoRequest = {
  message: string
  documents: string[]
  askedAt?: string
}

const KNOWN_STATUSES = new Set<VendorRequestStatus>([
  "submitted",
  "under_review",
  "info_requested",
  "approved",
  "payment_required",
  "paid",
  "waitlisted",
  "rejected",
  "cancelled",
  "completed",
])

export function normalizeVendorRequestStatus(value: string | undefined): VendorRequestStatus {
  const normalized = (value ?? "").trim().toLowerCase().replace(/-/g, "_")
  if (normalized === "payment_due") return "payment_required"
  if (KNOWN_STATUSES.has(normalized as VendorRequestStatus)) {
    return normalized as VendorRequestStatus
  }
  return "unknown"
}

export function vendorRequestStatusLabel(status: VendorRequestStatus): string {
  return applicationStatusLabel(status, "vendor")
}

export function vendorRequestStatusTone(status: VendorRequestStatus): StatusTone {
  return applicationStatusTone(status, "vendor")
}

function mapInfoRequest(raw: WpVendorApplicationItem["info_request"]): VendorInfoRequest | undefined {
  if (!raw || typeof raw !== "object" || typeof raw.message !== "string") return undefined
  return {
    message: raw.message,
    documents: Array.isArray(raw.documents) ? raw.documents.filter((d): d is string => typeof d === "string") : [],
    askedAt: typeof raw.at === "string" ? raw.at : undefined,
  }
}

/**
 * Reply to an organizer's question. The server moves the request back to review; callers
 * re-read the list rather than assuming the new status.
 */
export async function respondToInfoRequest(
  applicationId: string,
  message: string,
  auth: WpAuth,
): Promise<void> {
  const trimmed = message.trim()
  if (trimmed.length < 2) {
    throw new Error("Add a reply for the organizer.")
  }
  await wpFetchJson(`/applications/${encodeURIComponent(applicationId)}/respond-info`, {
    method: "POST",
    auth,
    body: { message: trimmed },
  })
  invalidateVendorRequests()
}

const formatDate = formatShortDate

function formatLocation(city?: string, state?: string): string {
  return [city, state].filter(Boolean).join(", ")
}

export function mapWpVendorApplication(item: WpVendorApplicationItem): VendorRequest {
  return {
    id: String(item.application_id ?? item.id ?? ""),
    opportunityId: item.opportunity_id ? String(item.opportunity_id) : undefined,
    eventId: item.event_id ? String(item.event_id) : undefined,
    eventName: item.event_name ?? "Event",
    eventDate: formatDate(item.event_date) ?? item.event_date,
    eventDateIso: item.event_date ? String(item.event_date).slice(0, 10) : undefined,
    city: item.place || formatLocation(item.city, item.state) || item.city,
    state: item.state,
    organizerName: item.organizer_name,
    category: item.category,
    status: normalizeVendorRequestStatus(item.status),
    submittedAt: formatDate(item.submitted_at) ?? item.submitted_at,
    amountDue: item.amount_due ?? item.vendor_fee,
    currency: item.currency ?? "USD",
    paymentId: item.payment_id ? String(item.payment_id) : undefined,
    paymentStatus: item.payment_status || undefined,
    infoRequest: mapInfoRequest(item.info_request),
    imageUrl: mapEventMedia(item.media, item.featured_image)?.thumbUrl,
    primaryStatus: item.primary_status || undefined,
    venue: item.venue || undefined,
    opportunityTitle: item.opportunity_title || undefined,
    eventEndDateIso: item.event_end_date ? String(item.event_end_date).slice(0, 10) : undefined,
    eventResolution: item.event_resolution,
    eventState: item.event_state || undefined,
    applicationState: item.application_state || undefined,
    bookingState: item.booking_state || undefined,
    paymentState: item.payment_state || undefined,
    credentialState: item.credential_state || undefined,
    paymentContext: item.payment_context
      ? {
          hasBalance: Boolean(item.payment_context.has_balance),
          pastEventInvoice: Boolean(item.payment_context.past_event_invoice),
          collectible: Boolean(item.payment_context.collectible),
        }
      : undefined,
    timeline: Array.isArray(item.timeline)
      ? item.timeline.map((step) => ({
          key: String(step.key ?? ""),
          label: String(step.label ?? ""),
          state: step.state === "done" || step.state === "current" ? step.state : "pending",
          at: step.at || undefined,
        }))
      : undefined,
  }
}

/** One booking with its timeline (`GET /applications/vendor/{id}`). */
export async function fetchVendorRequest(id: string, auth?: WpAuth): Promise<VendorRequest | null> {
  if (!auth) {
    return MOCK_REQUESTS.find((r) => r.id === id) ?? null
  }
  const data = await wpFetchJson<WpVendorApplicationItem>(`/applications/vendor/${encodeURIComponent(id)}`, { auth })
  const mapped = mapWpVendorApplication(data)
  return mapped.id ? mapped : null
}

export function mapVendorRequestList(
  data: WpVendorApplicationsResponse | WpVendorApplicationItem[],
): VendorRequest[] {
  const items = Array.isArray(data) ? data : data.items ?? []
  return items.map(mapWpVendorApplication).filter((item) => item.id)
}

export type VendorRequestsResult = {
  items: VendorRequest[]
  total: number
}

export const VENDOR_REQUESTS_CACHE_PREFIX = "vendor.requests:"

export function vendorRequestsCacheKey(auth?: WpAuth): string {
  return `${VENDOR_REQUESTS_CACHE_PREFIX}${auth?.username ?? "anon"}`
}

/**
 * Vendor applications. Shared across Home, Requests, More, Wallet and the tab badge:
 * concurrent callers reuse one request and results are reused for 15s unless `force`.
 */
export async function fetchVendorRequests(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<VendorRequestsResult> {
  if (!auth) {
    return withFixtureFallback(
      "vendor.requests",
      async () => ({ items: [], total: 0 }),
      () => ({ items: MOCK_REQUESTS, total: MOCK_REQUESTS.length }),
    )
  }
  return cachedRequest(
    vendorRequestsCacheKey(auth),
    () =>
      withFixtureFallback(
        "vendor.requests",
        async () => {
          const data = await wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", {
            auth,
          })
          const items = mapVendorRequestList(data)
          return { items, total: data.total ?? items.length }
        },
        () => ({ items: MOCK_REQUESTS, total: MOCK_REQUESTS.length }),
      ),
    options,
  )
}

/** Call after apply / pay so every screen re-reads canonical status. */
export function invalidateVendorRequests(): void {
  invalidateCache(VENDOR_REQUESTS_CACHE_PREFIX)
}

export function subscribeVendorRequests(
  auth: WpAuth | undefined,
  listener: (result: VendorRequestsResult) => void,
): () => void {
  return subscribeCache<VendorRequestsResult>(vendorRequestsCacheKey(auth), listener)
}

/** Open, collectible balance (upcoming or past event). Wallet lists these; Home separates them. */
export function isPaymentDueRequest(r: VendorRequest): boolean {
  return hasOpenBalance(r)
}

/** Confirmed booking for an upcoming or in-progress event. Undated bookings are never upcoming. */
export function isUpcomingBooking(r: VendorRequest): boolean {
  return bookingSegment(r, localIsoDay()) === "upcoming"
}

/** Confirmed or completed booking for an event that is over. */
export function isPastBooking(r: VendorRequest): boolean {
  if (r.status === "completed") return true
  return (r.status === "paid" || r.bookingState === "completed") && isEventOver(r)
}

export function countByStatus(requests: VendorRequest[]) {
  return {
    review: requests.filter((r) => r.status === "submitted" || r.status === "under_review").length,
    paymentDue: requests.filter((r) => isUpcomingPaymentDue(r)).length,
    pastInvoices: requests.filter((r) => isPastEventInvoice(r)).length,
    booked: requests.filter(isUpcomingBooking).length,
    past: requests.filter(isPastBooking).length,
    total: requests.length,
  }
}

/** Payments that confirm an upcoming spot (past-event invoices are listed separately). */
export function paymentDueRequests(requests: VendorRequest[], limit = 2): VendorRequest[] {
  return requests.filter((r) => isUpcomingPaymentDue(r)).slice(0, limit)
}

/** Upcoming confirmed bookings, soonest first. */
export function upcomingBookedRequests(requests: VendorRequest[], limit = 3): VendorRequest[] {
  return requests
    .filter(isUpcomingBooking)
    .sort((a, b) => (a.eventDateIso ?? "9999").localeCompare(b.eventDateIso ?? "9999"))
    .slice(0, limit)
}
