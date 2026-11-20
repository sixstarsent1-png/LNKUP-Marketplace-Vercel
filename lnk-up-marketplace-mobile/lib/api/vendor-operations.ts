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

type WpVendorHome = {
  has_event?: boolean
  application_id?: number
  readiness_percent?: number
  readiness?: { percent?: number }
  booth?: string
  loadin?: string
  load_in?: string
  packet_status?: string
  qr_pass?: { status?: string }
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

async function enrichWithVendorHome(
  event: VendorOperationsEvent,
  home: WpVendorHome,
): Promise<VendorOperationsEvent> {
  const percent = home.readiness_percent ?? home.readiness?.percent
  const packetUnlocked =
    home.packet_status === "unlocked" || home.qr_pass?.status === "unlocked"
  return {
    ...event,
    readinessPercent: typeof percent === "number" ? percent : event.readinessPercent,
    boothAssignment: home.booth || event.boothAssignment,
    loadInTime: home.load_in || home.loadin || event.loadInTime,
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

  const enriched = await Promise.all(
    enrichable.map(async (event) => {
      const home = byApp.get(event.requestId)
      if (!home) return event
      return enrichWithVendorHome(event, home)
    }),
  )
  const byId = new Map(enriched.map((e) => [e.requestId, e]))

  return {
    items: base.map((e) => byId.get(e.requestId) ?? e),
  }
}
