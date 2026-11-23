import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  fetchVendorRequests,
  type VendorRequest,
  type VendorRequestStatus,
} from "@/lib/api/vendor-requests"
import { MOCK_VENDOR_OPERATIONS } from "@/lib/data/fixtures"

export type VendorOperationsStatus =
  | "payment_required"
  | "pass_ready"
  | "upcoming"
  | "completed"
  | "cancelled"

export type VendorOperationsEvent = {
  requestId: string
  eventName: string
  eventDate?: string
  city?: string
  state?: string
  status: VendorOperationsStatus
  passStatus: "locked" | "unlocked" | "unknown"
  readinessPercent?: number
  boothAssignment?: string
  loadInTime?: string
}

type WpBooth = {
  booth_number?: string | number
  booth_type_label?: string
  booth_size?: string
}

type WpLoadInWindow = {
  label?: string
  start_at?: string
  end_at?: string
  gate?: string
}

export type WpVendorHome = {
  has_event?: boolean
  application_id?: number
  readiness_percent?: number
  readiness?: { percent?: number }
  /** Formatted booth row (LNKup_Booths::format), or null when unassigned. */
  booth?: WpBooth | string | null
  /** Load-in window (LNKup_Loadin_Schedule::format_window), or null. */
  loadin?: WpLoadInWindow | string | null
  load_in?: string
  /** Display text: "Locked", "Not opened", "Rules pending", "Complete" (empty without a packet). */
  packet_status?: string
  /** QR pass token; empty until the packet is unlocked. */
  qr_pass?: string | { status?: string } | null
}

export function formatBooth(booth: WpVendorHome["booth"]): string | undefined {
  if (!booth) return undefined
  if (typeof booth === "string") return booth || undefined
  const number = booth.booth_number != null ? String(booth.booth_number).trim() : ""
  const details = [booth.booth_type_label, booth.booth_size].filter(Boolean).join(", ")
  const label = [number, details ? `(${details})` : ""].filter(Boolean).join(" ")
  return label || undefined
}

export function formatLoadIn(loadin: WpVendorHome["loadin"], fallback?: string): string | undefined {
  if (typeof loadin === "string") return loadin || fallback || undefined
  if (loadin && typeof loadin === "object") {
    const window = loadin.label || [loadin.start_at, loadin.end_at].filter(Boolean).join(" – ")
    const label = [window, loadin.gate ? `Gate ${loadin.gate}` : ""].filter(Boolean).join(" · ")
    if (label) return label
  }
  return fallback || undefined
}

export function isPassUnlocked(home: WpVendorHome): boolean {
  const qr = home.qr_pass
  if (typeof qr === "string" && qr.trim() !== "") return true
  if (qr && typeof qr === "object" && qr.status === "unlocked") return true
  const packet = (home.packet_status ?? "").trim().toLowerCase()
  return packet !== "" && packet !== "locked"
}

const OPS_STATUSES = new Set<VendorRequestStatus>([
  "approved",
  "payment_required",
  "paid",
  "completed",
])

function deriveStatus(request: VendorRequest): VendorOperationsStatus {
  if (request.status === "cancelled" || request.status === "rejected") return "cancelled"
  if (request.status === "completed") return "completed"
  if (request.status === "payment_required" || request.status === "approved") {
    return "payment_required"
  }
  if (request.status === "paid") return "pass_ready"
  return "upcoming"
}

function mapRequest(request: VendorRequest): VendorOperationsEvent {
  const status = deriveStatus(request)
  return {
    requestId: request.id,
    eventName: request.eventName,
    eventDate: request.eventDate,
    city: request.city,
    state: request.state,
    status,
    passStatus: status === "pass_ready" || status === "completed" ? "unlocked" : "locked",
  }
}

export function enrichWithVendorHome(event: VendorOperationsEvent, home: WpVendorHome): VendorOperationsEvent {
  const percent = home.readiness_percent ?? home.readiness?.percent
  const packetUnlocked = isPassUnlocked(home)
  return {
    ...event,
    readinessPercent: typeof percent === "number" ? percent : event.readinessPercent,
    boothAssignment: formatBooth(home.booth) ?? event.boothAssignment,
    loadInTime: formatLoadIn(home.loadin, home.load_in) ?? event.loadInTime,
    passStatus: packetUnlocked ? "unlocked" : event.passStatus,
    status: packetUnlocked && event.status === "upcoming" ? "pass_ready" : event.status,
  }
}

export function operationsStatusLabel(status: VendorOperationsStatus): string {
  const labels: Record<VendorOperationsStatus, string> = {
    payment_required: "Payment required",
    pass_ready: "Pass ready",
    upcoming: "Upcoming",
    completed: "Completed",
    cancelled: "Cancelled",
  }
  return labels[status]
}

export async function fetchVendorOperations(auth?: WpAuth): Promise<{ items: VendorOperationsEvent[] }> {
  if (!isLiveApiEnabled()) {
    return { items: MOCK_VENDOR_OPERATIONS.map((i) => ({ ...i })) }
  }

  const { items: requests } = await fetchVendorRequests(auth)
  const base = requests
    .filter((r) => OPS_STATUSES.has(r.status))
    .map(mapRequest)

  const enrichable = base.filter(
    (e) => e.status === "pass_ready" || e.status === "payment_required" || e.status === "completed",
  )

  if (enrichable.length === 0) {
    return { items: base }
  }

  const ids = enrichable.map((e) => e.requestId).join(",")
  let homes: WpVendorHome[] = []
  try {
    const batch = await wpFetchJson<{ items?: WpVendorHome[] }>("/operations/vendor-homes", {
      auth,
      query: { application_ids: ids },
    })
    homes = Array.isArray(batch.items) ? batch.items : []
  } catch {
    // Fallback: soft-skip enrichment rather than N+1 hammering
    homes = []
  }

  const byApp = new Map<string, WpVendorHome>()
  for (const home of homes) {
    const appId = home.application_id != null ? String(home.application_id) : ""
    if (appId) byApp.set(appId, home)
  }

  const enriched = enrichable.map((event) => {
    const home = byApp.get(event.requestId)
    return home ? enrichWithVendorHome(event, home) : event
  })
  const byId = new Map(enriched.map((e) => [e.requestId, e]))

  return {
    items: base.map((e) => byId.get(e.requestId) ?? e),
  }
}
