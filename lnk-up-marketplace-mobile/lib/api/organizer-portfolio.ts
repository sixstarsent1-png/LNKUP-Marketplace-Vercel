import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Booth capacity as the web workspace shows it: confirmed = paid, held = approved and awaiting
 * payment, open = total minus both. `total` is 0 when no slot sets a spot count.
 */
export type EventCapacity = {
  total: number
  confirmed: number
  held: number
  open: number
  waitlisted: number
  boothTypes: number
}

/** Event-scoped booth fees from payment records, before LNKup fees. */
export type EventMoneyGross = {
  collectedGross: number
  outstanding: number
  paidBookings: number
}

/** Presentation fields from `GET /organizer/portfolio` and `GET /organizer/events/{id}/summary`. */
export type OrganizerEventPresentation = {
  id: string
  title: string
  place: string
  venue: string
  publicationLabel: string
  chronologyLabel: string
  capacity: EventCapacity
  money?: EventMoneyGross
}

type WpCapacity = {
  total?: number
  confirmed?: number
  held?: number
  open?: number
  waitlisted?: number
  booth_types?: number
}

type WpEventSummary = {
  id?: number
  title?: string
  place?: string
  venue?: string
  publication_label?: string
  chronology_label?: string
  capacity?: WpCapacity
  money?: { collected_gross?: number; outstanding?: number; paid_bookings?: number }
}

function mapCapacity(c: WpCapacity | undefined): EventCapacity {
  return {
    total: Number(c?.total ?? 0),
    confirmed: Number(c?.confirmed ?? 0),
    held: Number(c?.held ?? 0),
    open: Number(c?.open ?? 0),
    waitlisted: Number(c?.waitlisted ?? 0),
    boothTypes: Number(c?.booth_types ?? 0),
  }
}

export function mapEventSummary(row: WpEventSummary): OrganizerEventPresentation | null {
  if (row?.id == null) return null
  return {
    id: String(row.id),
    title: String(row.title ?? ""),
    place: String(row.place ?? ""),
    venue: String(row.venue ?? ""),
    publicationLabel: String(row.publication_label ?? ""),
    chronologyLabel: String(row.chronology_label ?? ""),
    capacity: mapCapacity(row.capacity),
    money: row.money
      ? {
          collectedGross: Number(row.money.collected_gross ?? 0),
          outstanding: Number(row.money.outstanding ?? 0),
          paidBookings: Number(row.money.paid_bookings ?? 0),
        }
      : undefined,
  }
}

/** Older servers don't have these routes; callers keep their existing fields when this is empty. */
function isMissingRoute(error: unknown): boolean {
  return error instanceof WpFetchError && (error.status === 404 || error.code === "rest_no_route")
}

/** Portfolio presentation rows keyed by event ID. Empty map in demo mode or on older servers. */
export async function fetchOrganizerPortfolio(auth: WpAuth): Promise<Map<string, OrganizerEventPresentation>> {
  const out = new Map<string, OrganizerEventPresentation>()
  if (!isLiveApiEnabled()) return out
  try {
    const res = await wpFetchJson<{ events?: WpEventSummary[] }>("/organizer/portfolio", { auth })
    for (const row of Array.isArray(res.events) ? res.events : []) {
      const mapped = mapEventSummary(row)
      if (mapped) out.set(mapped.id, mapped)
    }
  } catch (error) {
    if (!isMissingRoute(error)) throw error
  }
  return out
}

/** One event's presentation summary, or null when the server predates the route. */
export async function fetchOrganizerEventSummary(
  eventId: string,
  auth: WpAuth,
): Promise<OrganizerEventPresentation | null> {
  if (!isLiveApiEnabled() || !eventId) return null
  try {
    return mapEventSummary(await wpFetchJson<WpEventSummary>(`/organizer/events/${eventId}/summary`, { auth }))
  } catch (error) {
    if (isMissingRoute(error)) return null
    throw error
  }
}
