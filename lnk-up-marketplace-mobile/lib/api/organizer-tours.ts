import { isLiveApiEnabled } from "@/lib/api/config"
import { cachedRequest } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Tours and series (Portfolio OS). Organizer money is shown as collected booth fees only;
 * the platform fee and estimated-net fields in the server payload are deliberately not mapped.
 */

export type TourApplications = { total: number; pending: number; approvedUnpaid: number; paid: number; waitlisted: number }

export type OrgTour = {
  id: string
  title: string
  type: string
  status: string
  season: string
  coverUrl: string
  stopCount: number
  published: number
  drafts: number
  nextDate: string
  publicUrl: string
}

export type TourStop = {
  eventId: string
  title: string
  city: string
  date: string
  venue: string
  state: string
  order: number
  applications: TourApplications
  spotsTotal: number
  spotsHeld: number
  spotsOpen: number
  collected: number
  outstanding: number
  paidBookings: number
  nextDeadline: string
}

export type TourSummary = {
  tour: OrgTour
  applications: TourApplications
  inventory: { total: number; held: number; open: number }
  money: { collected: number; outstanding: number; refunds: number; paidBookings: number }
  stops: TourStop[]
}

type Wp = Record<string, unknown>
const obj = (v: unknown): Wp => (v && typeof v === "object" ? (v as Wp) : {})
const str = (v: unknown) => (v == null ? "" : String(v))
const num = (v: unknown) => (Number.isFinite(Number(v)) ? Number(v) : 0)

function mapApplications(raw: unknown): TourApplications {
  const a = obj(raw)
  return { total: num(a.total), pending: num(a.pending), approvedUnpaid: num(a.approved_unpaid), paid: num(a.paid), waitlisted: num(a.waitlisted) }
}

export function mapTour(raw: unknown): OrgTour {
  const t = obj(raw)
  const counts = obj(t.counts)
  return {
    id: str(t.id),
    title: str(t.title),
    type: str(t.type),
    status: str(t.status),
    season: str(t.season),
    coverUrl: str(t.cover_url),
    stopCount: num(t.stop_count),
    published: num(counts.published),
    drafts: num(counts.draft),
    nextDate: str(t.next_date),
    publicUrl: str(t.public_url),
  }
}

export function mapTourSummary(raw: unknown): TourSummary {
  const r = obj(raw)
  const inv = obj(r.inventory)
  const money = obj(r.money)
  const stops = (Array.isArray(r.stops) ? r.stops : []).map((s) => {
    const x = obj(s)
    const m = obj(x.money)
    return {
      eventId: str(x.event_id),
      title: str(x.title),
      city: str(x.city),
      date: str(x.event_date),
      venue: str(x.venue_name),
      state: str(x.state),
      order: num(x.order),
      applications: mapApplications(x.applications),
      spotsTotal: num(x.spots_total),
      spotsHeld: num(x.spots_held),
      spotsOpen: num(x.spots_open),
      collected: num(m.collected),
      outstanding: num(m.pending),
      paidBookings: num(m.paid_bookings),
      nextDeadline: str(x.next_deadline),
    }
  })
  stops.sort((a, b) => (a.date || "9999").localeCompare(b.date || "9999"))
  return {
    tour: mapTour(r.tour),
    applications: mapApplications(r.applications),
    inventory: { total: num(inv.spots_total), held: num(inv.spots_held), open: num(inv.spots_open) },
    money: { collected: num(money.collected), outstanding: num(money.pending), refunds: num(money.refunds), paidBookings: num(money.paid_bookings) },
    stops,
  }
}

export function fetchTours(auth: WpAuth, force = false): Promise<OrgTour[]> {
  if (!isLiveApiEnabled()) return Promise.resolve([])
  return cachedRequest(
    `org-mobile:tours:${auth.username}`,
    async () => {
      const res = obj(await wpFetchJson<unknown>("/portfolio/tours", { auth }))
      return (Array.isArray(res.items) ? res.items : []).map(mapTour)
    },
    { ttlMs: 30_000, force },
  )
}

export async function fetchTourSummary(tourId: string, auth: WpAuth): Promise<TourSummary | null> {
  if (!isLiveApiEnabled()) return null
  return mapTourSummary(await wpFetchJson<unknown>(`/portfolio/tours/${tourId}/summary`, { auth }))
}
