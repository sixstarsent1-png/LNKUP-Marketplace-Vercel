import type { VendorRequest } from "@/lib/api/vendor-requests"
import type { VendorPacketPreview } from "@/lib/api/vendor-packet"
import { buildVendorEventReadiness, isEventDay, isEventWithinHours } from "@/lib/vendor/event-readiness"
import { getPublicWpSiteUrl } from "@/lib/api/config"

export type VendorOperationsEventStatus =
  | "payment_required"
  | "pass_ready"
  | "event_day"
  | "upcoming"
  | "completed"
  | "cancelled"

export type VendorEventDayChecklistItem = {
  id: string
  label: string
  complete: boolean
  severity: "required" | "recommended" | "info"
  actionHref?: string
  actionLabel?: string
}

export type VendorOperationsEvent = {
  requestId: string
  eventId?: string
  eventName: string
  eventDate?: string
  city?: string
  state?: string
  organizerName?: string
  status: VendorOperationsEventStatus
  passStatus: "locked" | "unlocked" | "unknown"
  readinessPercent: number
  readinessTier: "ready" | "on_track" | "at_risk" | "unknown"
  checklist: VendorEventDayChecklistItem[]
  boothAssignment?: string
  loadInTime?: string
  checkInCode?: string
  passUrl: string
  paymentUrl?: string
  wordpressUrl?: string
}

export type WpVendorHomeResponse = {
  application_id?: number
  booth?: string
  parking?: string
  load_in?: string
  load_out?: string
  qr_pass?: {
    code?: string
    url?: string
    status?: string
  }
  readiness?: {
    percent?: number
    items?: Array<{ id?: string; label?: string; complete?: boolean }>
  }
  ops_url?: string
}

function deriveOpsStatus(
  request: VendorRequest,
  packet?: VendorPacketPreview | null,
): VendorOperationsEventStatus {
  if (request.status === "cancelled" || request.status === "rejected") {
    return "cancelled"
  }
  if (request.status === "completed") {
    return "completed"
  }
  if (request.status === "payment_required" || request.status === "approved") {
    return "payment_required"
  }
  if (isEventDay(request.eventDate)) {
    return "event_day"
  }
  if (packet?.status === "unlocked" || request.status === "paid") {
    if (isEventWithinHours(request.eventDate, 72)) {
      return "event_day"
    }
    return "pass_ready"
  }
  return "upcoming"
}

export function mapVendorApplicationToOperationsEvent(
  request: VendorRequest,
  packet?: VendorPacketPreview | null,
): VendorOperationsEvent {
  const readiness = buildVendorEventReadiness(request, packet)
  const status = deriveOpsStatus(request, packet)
  const passUrl = `/vendor/pass/${request.id}`
  const wpBase = getPublicWpSiteUrl()

  return {
    requestId: request.id,
    eventId: request.eventId,
    eventName: request.eventName,
    eventDate: request.eventDate,
    city: request.city,
    state: request.state,
    organizerName: request.organizerName,
    status,
    passStatus: packet?.status ?? (request.status === "paid" ? "unlocked" : "locked"),
    readinessPercent: readiness.percent,
    readinessTier: readiness.tier,
    checklist: readiness.items,
    boothAssignment: packet?.boothAssignment,
    loadInTime: packet?.loadInTime,
    checkInCode: packet?.checkInCode,
    passUrl,
    paymentUrl:
      request.status === "payment_required" || request.status === "approved"
        ? `/vendor/requests?pay=${request.id}`
        : undefined,
    wordpressUrl: packet?.wordpressUrl ?? (wpBase ? `${wpBase}/vendor-dashboard/?view=operations` : undefined),
  }
}

export function enrichOperationsEvent(
  event: VendorOperationsEvent,
  vendorHome?: WpVendorHomeResponse | null,
): VendorOperationsEvent {
  if (!vendorHome) return event

  const enriched = { ...event }
  if (vendorHome.booth) enriched.boothAssignment = vendorHome.booth
  if (vendorHome.load_in) enriched.loadInTime = vendorHome.load_in
  if (vendorHome.qr_pass?.code) enriched.checkInCode = vendorHome.qr_pass.code
  if (vendorHome.ops_url) enriched.wordpressUrl = vendorHome.ops_url

  if (typeof vendorHome.readiness?.percent === "number") {
    enriched.readinessPercent = vendorHome.readiness.percent
  }

  return enriched
}

export const OPS_STATUS_LABELS: Record<VendorOperationsEventStatus, string> = {
  payment_required: "Payment required",
  pass_ready: "Pass ready",
  event_day: "Event day",
  upcoming: "Upcoming",
  completed: "Completed",
  cancelled: "Cancelled",
}

export const OPS_STATUS_ORDER: VendorOperationsEventStatus[] = [
  "payment_required",
  "event_day",
  "pass_ready",
  "upcoming",
  "completed",
  "cancelled",
]

export function groupOperationsEvents(
  events: VendorOperationsEvent[],
): Record<VendorOperationsEventStatus, VendorOperationsEvent[]> {
  const groups: Record<VendorOperationsEventStatus, VendorOperationsEvent[]> = {
    payment_required: [],
    pass_ready: [],
    event_day: [],
    upcoming: [],
    completed: [],
    cancelled: [],
  }
  for (const event of events) {
    groups[event.status].push(event)
  }
  return groups
}

export function mockVendorOperationsEvents(): VendorOperationsEvent[] {
  const wpBase = getPublicWpSiteUrl()
  const wpOps = wpBase ? `${wpBase}/vendor-dashboard/?view=operations` : undefined

  return [
    {
      requestId: "req-2",
      eventId: "atlanta-food-fest",
      eventName: "Atlanta Food Fest",
      eventDate: "Jul 15, 2026",
      city: "Atlanta",
      state: "GA",
      organizerName: "Southern Eats Co.",
      status: "payment_required",
      passStatus: "locked",
      readinessPercent: 25,
      readinessTier: "at_risk",
      checklist: [
        { id: "payment", label: "Payment complete", complete: false, severity: "required" },
        { id: "pass", label: "Pass unlocked", complete: false, severity: "required" },
        { id: "loadin", label: "Load-in reviewed", complete: false, severity: "recommended" },
      ],
      passUrl: "/vendor/pass/req-2",
      paymentUrl: "/vendor/requests?pay=req-2",
      wordpressUrl: wpOps,
    },
    {
      requestId: "req-1",
      eventId: "charlotte-night-market",
      eventName: "Charlotte Night Market",
      eventDate: "Jul 1, 2026",
      city: "Charlotte",
      state: "NC",
      organizerName: "Queen City Events",
      status: "event_day",
      passStatus: "unlocked",
      readinessPercent: 88,
      readinessTier: "ready",
      checklist: [
        { id: "payment", label: "Payment complete", complete: true, severity: "required" },
        { id: "pass", label: "Pass unlocked", complete: true, severity: "required" },
        { id: "loadin", label: "Load-in reviewed", complete: true, severity: "recommended" },
        { id: "checkin", label: "Check-in code ready", complete: true, severity: "info" },
      ],
      boothAssignment: "Booth A-12",
      loadInTime: "2:00 PM – 4:00 PM",
      checkInCode: "CHK-8842",
      passUrl: "/vendor/pass/req-1",
      wordpressUrl: wpOps,
    },
    {
      requestId: "req-3",
      eventId: "raleigh-summer-fair",
      eventName: "Raleigh Summer Fair",
      eventDate: "Aug 20, 2026",
      city: "Raleigh",
      state: "NC",
      organizerName: "Triangle Fairs",
      status: "pass_ready",
      passStatus: "unlocked",
      readinessPercent: 75,
      readinessTier: "on_track",
      checklist: [
        { id: "payment", label: "Payment complete", complete: true, severity: "required" },
        { id: "pass", label: "Pass unlocked", complete: true, severity: "required" },
        { id: "parking", label: "Parking reviewed", complete: false, severity: "recommended" },
      ],
      boothAssignment: "Lot C-7",
      loadInTime: "10:00 AM – 12:00 PM",
      checkInCode: "CHK-3301",
      passUrl: "/vendor/pass/req-3",
      wordpressUrl: wpOps,
    },
    {
      requestId: "req-4",
      eventId: "nashville-street-fair",
      eventName: "Nashville Street Fair",
      eventDate: "May 10, 2026",
      city: "Nashville",
      state: "TN",
      organizerName: "Music City Markets",
      status: "completed",
      passStatus: "unlocked",
      readinessPercent: 100,
      readinessTier: "ready",
      checklist: [
        { id: "payment", label: "Payment complete", complete: true, severity: "required" },
        { id: "pass", label: "Pass unlocked", complete: true, severity: "required" },
      ],
      passUrl: "/vendor/pass/req-4",
      wordpressUrl: wpOps,
    },
  ]
}
