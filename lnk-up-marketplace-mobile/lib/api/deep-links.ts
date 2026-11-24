import type { RegisterAttribution } from "@/lib/api/auth"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * WordPress `lnkup/v1/public/*` deep-link endpoints (flag deep_links_v2). Slugs and /l/ codes are
 * resolved on the server so the app never guesses IDs; unknown or private targets come back as the
 * feed.
 */

export type ResolvedRoute = "event" | "opportunity" | "tour" | "organizer" | "signup" | "feed"

export type ResolvedLink = {
  route: ResolvedRoute
  id: number
  params: Record<string, string>
  attribution: RegisterAttribution
}

const ROUTES: ReadonlySet<string> = new Set<ResolvedRoute>(["event", "opportunity", "tour", "organizer", "signup", "feed"])

function stringRecord(raw: unknown): Record<string, string> {
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return {}
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (typeof value === "string" || typeof value === "number") out[key] = String(value)
  }
  return out
}

export async function resolveAppPath(path: string): Promise<ResolvedLink> {
  const body = await wpFetchJson<{ data?: { route?: string; id?: number; params?: unknown; attribution?: unknown } }>(
    "/public/resolve",
    { query: { path } },
  )
  const data = body?.data ?? {}
  const route = ROUTES.has(String(data.route)) ? (data.route as ResolvedRoute) : "feed"
  return {
    route,
    id: Number(data.id ?? 0) || 0,
    params: stringRecord(data.params),
    attribution: stringRecord(data.attribution) as RegisterAttribution,
  }
}

export type PublicEventState = "scheduled" | "postponed" | "cancelled" | "past"

export type PublicEvent = {
  id: number
  title: string
  url: string
  date: string
  state: PublicEventState
  description: string
  venue: string
  city: string
  stateCode: string
  image: string
  organizerName: string
  organizerSlug: string
  external: boolean
  opportunities: Array<{ id: number; title: string; statusLabel: string; deadline: string }>
}

type WpPublicEvent = {
  id?: number
  title?: string
  url?: string
  date?: string
  state?: string
  description?: string
  venue?: string
  city?: string
  state_code?: string
  image?: string
  organizer?: { name?: string; slug?: string }
  external?: boolean
  opportunities?: Array<{ id?: number; title?: string; status_label?: string; deadline?: string }>
}

const EVENT_STATES: ReadonlySet<string> = new Set<PublicEventState>(["scheduled", "postponed", "cancelled", "past"])

export function mapPublicEvent(data: WpPublicEvent): PublicEvent {
  return {
    id: Number(data.id ?? 0),
    title: data.title ?? "",
    url: data.url ?? "",
    date: data.date ?? "",
    state: EVENT_STATES.has(String(data.state)) ? (data.state as PublicEventState) : "scheduled",
    description: data.description ?? "",
    venue: data.venue ?? "",
    city: data.city ?? "",
    stateCode: data.state_code ?? "",
    image: data.image ?? "",
    organizerName: data.organizer?.name ?? "",
    organizerSlug: data.organizer?.slug ?? "",
    external: Boolean(data.external),
    opportunities: (data.opportunities ?? [])
      .filter((opp) => Number(opp.id) > 0)
      .map((opp) => ({
        id: Number(opp.id),
        title: opp.title ?? "",
        statusLabel: opp.status_label ?? "",
        deadline: opp.deadline ?? "",
      })),
  }
}

export async function fetchPublicEvent(eventId: number): Promise<PublicEvent> {
  const body = await wpFetchJson<{ data?: WpPublicEvent }>(`/public/events/${eventId}`)
  return mapPublicEvent(body?.data ?? {})
}

export type ShareTarget =
  | { type: "event" | "opportunity" | "tour" | "organizer"; id: number }
  | { type: "market"; key: string }
  | { type: "signup"; key: "vendor" | "organizer" }

/**
 * Tracked /l/ link for native share when the signed-in user may promote the target; otherwise the
 * plain public URL. Repeat shares reuse the same link.
 */
export async function fetchShareLink(auth: WpAuth, target: ShareTarget): Promise<{ url: string; tracked: boolean }> {
  const body = await wpFetchJson<{ data?: { url?: string; tracked?: boolean } }>("/app/share-link", {
    method: "POST",
    auth,
    body: {
      target_type: target.type,
      target_id: "id" in target ? target.id : 0,
      target_key: "key" in target ? target.key : "",
    },
  })
  const url = body?.data?.url ?? ""
  if (!url) throw new Error("Share link unavailable")
  return { url, tracked: Boolean(body?.data?.tracked) }
}
