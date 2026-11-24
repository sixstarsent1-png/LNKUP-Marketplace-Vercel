import { getOrganizerDashboardUrl, isLiveApiEnabled } from "@/lib/api/config"
import { invalidateEventRoster } from "@/lib/api/organizer-roster"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/** Read-only event map: a published image when the organizer uploaded one. Editing stays on web. */
export type EventMap = {
  status: string
  imageUrl: string
  elementCount: number
  updatedAt: string
}

export type LoadinWindow = { id: string; label: string; startAt: string; gate: string; maxSlots: number; assigned: number; remaining: number }
export type ParkingSpot = { id: string; label: string; gate: string; vehicleType: string; assigned: boolean; instructions: string }
export type PacketSection = { key: string; label: string; complete: boolean }
export type PacketOverview = { sections: PacketSection[]; complete: number; total: number; stage: string; stageLabel: string; stageHint: string; unlocked: number }
export type PassDetails = Record<"venueAddress" | "loadInTime" | "loadOutTime" | "vendorGate" | "loadinEntrance" | "parkingInfo" | "vendorRules", string>

type Wp = Record<string, unknown>
const obj = (v: unknown): Wp => (v && typeof v === "object" ? (v as Wp) : {})
const str = (v: unknown) => (v == null ? "" : String(v))
const num = (v: unknown) => (Number.isFinite(Number(v)) ? Number(v) : 0)

export function mapEventMap(raw: unknown): EventMap {
  const r = obj(raw)
  const map = obj(r.map)
  return {
    status: str(map.status),
    imageUrl: str(map.image_url),
    elementCount: Array.isArray(r.elements) ? r.elements.length : 0,
    updatedAt: str(map.updated_at),
  }
}

export function mapLoadin(raw: unknown): LoadinWindow[] {
  const windows = obj(raw).windows
  return (Array.isArray(windows) ? windows : []).map((w) => {
    const x = obj(w)
    return {
      id: str(x.id),
      label: str(x.label),
      startAt: str(x.start_at),
      gate: str(x.gate),
      maxSlots: num(x.max_slots),
      assigned: num(x.assigned_count),
      remaining: num(x.slots_remaining),
    }
  })
}

export function mapParking(raw: unknown): ParkingSpot[] {
  const items = obj(raw).items
  return (Array.isArray(items) ? items : []).map((p) => {
    const x = obj(p)
    return {
      id: str(x.id),
      label: str(x.label),
      gate: str(x.gate),
      vehicleType: str(x.vehicle_type),
      assigned: num(x.application_id) > 0,
      instructions: str(x.instructions),
    }
  })
}

export function mapPacketOverview(raw: unknown): PacketOverview {
  const r = obj(raw)
  const lifecycle = obj(r.lifecycle)
  return {
    sections: (Array.isArray(r.items) ? r.items : []).map((s) => ({ key: str(obj(s).key), label: str(obj(s).label), complete: Boolean(obj(s).complete) })),
    complete: num(r.complete),
    total: num(r.total),
    stage: str(lifecycle.stage) || "draft",
    stageLabel: str(lifecycle.label),
    stageHint: str(lifecycle.hint),
    unlocked: num(lifecycle.unlocked),
  }
}

export function mapPassDetails(raw: unknown): PassDetails {
  const r = obj(raw)
  return {
    venueAddress: str(r.venue_address),
    loadInTime: str(r.load_in_time),
    loadOutTime: str(r.load_out_time),
    vendorGate: str(r.vendor_gate),
    loadinEntrance: str(r.loadin_entrance),
    parkingInfo: str(r.parking_info),
    vendorRules: str(r.vendor_rules),
  }
}

export async function fetchEventMap(eventId: string, auth: WpAuth): Promise<EventMap | null> {
  if (!isLiveApiEnabled()) return null
  return mapEventMap(await wpFetchJson<unknown>(`/maps/events/${eventId}`, { auth }))
}

export async function fetchLoadin(eventId: string, auth: WpAuth): Promise<LoadinWindow[]> {
  if (!isLiveApiEnabled()) return []
  return mapLoadin(await wpFetchJson<unknown>(`/operations/events/${eventId}/loadin`, { auth }))
}

export async function fetchParking(eventId: string, auth: WpAuth): Promise<ParkingSpot[]> {
  if (!isLiveApiEnabled()) return []
  return mapParking(await wpFetchJson<unknown>(`/operations/events/${eventId}/parking`, { auth }))
}

export async function fetchPacketOverview(eventId: string, auth: WpAuth): Promise<PacketOverview | null> {
  if (!isLiveApiEnabled()) return null
  return mapPacketOverview(await wpFetchJson<unknown>(`/packets/event/${eventId}/completeness`, { auth }))
}

export async function fetchPassDetails(eventId: string, auth: WpAuth): Promise<PassDetails | null> {
  if (!isLiveApiEnabled()) return null
  return mapPassDetails(await wpFetchJson<unknown>(`/packets/event/${eventId}/pass-details`, { auth }))
}

/** New booth. The server rejects a duplicate booth number for the same event. */
export async function createBooth(
  eventId: string,
  input: { number: string; type?: string; size?: string; hasPower?: boolean; hasWater?: boolean },
  auth: WpAuth,
): Promise<string> {
  const res = await wpFetchJson<{ id?: number }>(`/operations/events/${eventId}/booths`, {
    method: "POST",
    auth,
    body: {
      booth_number: input.number.trim(),
      booth_type: input.type ?? "standard",
      booth_size: input.size ?? "",
      has_power: Boolean(input.hasPower),
      has_water: Boolean(input.hasWater),
    },
  })
  invalidateEventRoster(eventId)
  return String(res.id ?? "")
}

/** Exact web page for map editing (drag-and-drop layout is desktop-first). */
export function mapBuilderUrl(eventId: string): string {
  return `${getOrganizerDashboardUrl()}?event=${encodeURIComponent(eventId)}&ops=1#map`
}
