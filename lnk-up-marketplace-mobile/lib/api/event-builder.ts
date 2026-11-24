import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import { invalidateOrganizerEvents } from "@/lib/api/organizer-events"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Event Creation OS 3.0 builder (lnkup/v1/organizer/builder). Drafts live on the server with a
 * revision; every save sends the revision so changes made on another device are never
 * overwritten silently. Fees, validation and publishing rules stay on WordPress.
 */

export type BuilderMethod = "single" | "recurring" | "tour" | "series" | "import" | "duplicate" | "template" | "bulk" | "edit"
export type BuilderStep = "info" | "options" | "applications" | "payments" | "publish"

export type BuilderOption = {
  key: string
  opportunity_id?: number
  name: string
  category: string
  fee: number
  spots: number
  description?: string
}

export type BuilderStop = {
  key: string
  event_id: number
  date: string
  start_time: string
  end_time: string
  city: string
  state: string
  venue_name: string
  venue_address: string
  url: string
  fee_override: number | null
  spots_override: number | null
  skip: boolean
  publish: boolean
}

export type BuilderState = {
  method: BuilderMethod
  quick: boolean
  event: {
    name: string
    description: string
    event_type: string
    date: string
    end_date: string
    start_time: string
    end_time: string
    timezone: string
    city: string
    state: string
    venue_name: string
    venue_address: string
    location_confirmed: boolean
    featured_image_id: number
    attendance: { mode: "unknown" | "estimate" | "range"; value: number; min: number; max: number }
  }
  sources: Array<{ url: string; provider: string; primary: boolean; mode: "link" | "import" | "account" }>
  options: BuilderOption[]
  applications: {
    mode: "review" | "invite" | "closed"
    documents: { mode: "none" | "platform_defaults" | "custom"; items: Array<{ key: string }> }
    questions: Array<{ id: string; label: string; type: string; required: boolean }>
  }
  payments: { deadline: { mode: "default" | "fixed" | "relative"; date: string; days_before: number } }
  publish: { when: "now" | "schedule"; applications: "open" | "closed"; attest: boolean }
  import: { provider: string; original_url: string }
  confirmed: string[]
  portfolio: {
    name: string
    publish_mode: "all" | "drafts"
    recurrence: { frequency: "weekly" | "biweekly" | "monthly_weekday" | "monthly_date" | "custom"; weekdays: string[]; start: string; until: string; count: number; exclude: string[]; dates: string[] }
    stops: BuilderStop[]
  }
}

export type BuilderCheck = { key: string; step: BuilderStep; level: "required" | "recommended" | "optional"; ok: boolean; label: string; message: string }

export type BuilderReport = {
  progress: number
  publishable: boolean
  checks: BuilderCheck[]
  summary: { types: number; spaces: number; free_spaces: number; max_base_fees: number; starting_fee?: number }
}

export type BuilderDraft = { id: number; revision: number; state: BuilderState; source_event: number; updated_at: string }
export type BuilderDraftPayload = { draft: BuilderDraft; report: BuilderReport; fees: Array<{ key: string; vendor_total: number; free: boolean }> }

export type BuilderConfig = {
  flags: Record<string, boolean>
  categories: string[]
  timezone: string
  payouts_ready: boolean
  commission_pct: number
}

export type BuilderDraftSummary = { id: number; title: string; method: BuilderMethod; updated_at: string; progress: number; missing: string[]; editing: number }

export type BuilderPublishResult =
  | { kind: "published"; eventId: number; title: string; publicUrl: string; applyUrl: string }
  | { kind: "portfolio"; tourId: number; title: string; dates: number; live: number; background: boolean; type: string }
  | { kind: "scheduled"; publishAt: string }
  | { kind: "not_ready"; checks: BuilderCheck[]; message: string }
  | { kind: "confirm"; booked: number; message: string }
  | { kind: "error"; message: string }

export type BuilderSaveResult = { kind: "saved"; payload: BuilderDraftPayload } | { kind: "conflict"; message: string } | { kind: "error"; message: string }

export const PORTFOLIO_METHODS: ReadonlyArray<BuilderMethod> = ["recurring", "tour", "series"]

export const BUILDER_STEPS: ReadonlyArray<{ key: BuilderStep; label: string }> = [
  { key: "info", label: "Event" },
  { key: "options", label: "Vendor options" },
  { key: "applications", label: "Applications" },
  { key: "publish", label: "Review" },
]

export function isPortfolioMethod(method: BuilderMethod): boolean {
  return PORTFOLIO_METHODS.includes(method)
}

/** Nested patch object for a dotted path ("event.name" → { event: { name } }). */
export function patchFor(path: string, value: unknown): Record<string, unknown> {
  const parts = path.split(".")
  const root: Record<string, unknown> = {}
  let node = root
  parts.forEach((part, i) => {
    if (i === parts.length - 1) {
      node[part] = value
      return
    }
    const next: Record<string, unknown> = {}
    node[part] = next
    node = next
  })
  return root
}

/** Merge patches: objects merge key by key; arrays and scalars are replaced (matches the server). */
export function mergePatch(a: Record<string, unknown>, b: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = { ...a }
  Object.entries(b).forEach(([key, value]) => {
    const prev = out[key]
    const bothObjects = value && typeof value === "object" && !Array.isArray(value) && prev && typeof prev === "object" && !Array.isArray(prev)
    out[key] = bothObjects ? mergePatch(prev as Record<string, unknown>, value as Record<string, unknown>) : value
  })
  return out
}

/** Required checks that are still failing, in builder step order. */
export function blockingChecks(report: BuilderReport | null | undefined): BuilderCheck[] {
  if (!report) return []
  const order: BuilderStep[] = ["info", "options", "applications", "payments", "publish"]
  return report.checks.filter((c) => c.level === "required" && !c.ok).sort((x, y) => order.indexOf(x.step) - order.indexOf(y.step))
}

/** Native screens fold payments into Applications. */
export function nativeStepFor(step: BuilderStep): BuilderStep {
  return step === "payments" ? "applications" : step
}

export function newOptionKey(): string {
  return `m${Math.random().toString(36).slice(2, 10)}`
}

export function blankStop(): BuilderStop {
  return { key: `s${Math.random().toString(36).slice(2, 10)}`, event_id: 0, date: "", start_time: "", end_time: "", city: "", state: "", venue_name: "", venue_address: "", url: "", fee_override: null, spots_override: null, skip: false, publish: true }
}

function errorMessage(err: unknown, fallback: string): string {
  return err instanceof Error && err.message ? err.message : fallback
}

function requireLive(auth: WpAuth | undefined): asserts auth is WpAuth {
  if (!isLiveApiEnabled()) throw new WpFetchError("Connect to LNKup to create events.", "wordpress_unavailable", 0)
  if (!auth) throw new WpFetchError("Sign in as an organizer to continue.", "auth_required", 401)
}

/** Builder config, or null when the builder is not enabled for this account (legacy flow stays). */
export async function fetchBuilderConfig(auth: WpAuth | undefined): Promise<BuilderConfig | null> {
  if (!isLiveApiEnabled() || !auth) return null
  try {
    return await wpFetchJson<BuilderConfig>("/organizer/builder/config", { auth })
  } catch (err) {
    if (err instanceof WpFetchError && (err.status === 401 || err.status === 403 || err.status === 404)) return null
    throw err
  }
}

export async function listBuilderDrafts(auth: WpAuth | undefined): Promise<BuilderDraftSummary[]> {
  requireLive(auth)
  const data = await wpFetchJson<{ drafts: BuilderDraftSummary[] }>("/organizer/builder/drafts", { auth })
  return data.drafts ?? []
}

export async function createBuilderDraft(
  auth: WpAuth | undefined,
  body: { method: BuilderMethod; quick?: boolean; template_id?: string; duplicate_event?: number; components?: string[] },
): Promise<BuilderDraftPayload> {
  requireLive(auth)
  return wpFetchJson<BuilderDraftPayload>("/organizer/builder/drafts", { method: "POST", auth, body })
}

export async function getBuilderDraft(auth: WpAuth | undefined, id: number): Promise<BuilderDraftPayload> {
  requireLive(auth)
  return wpFetchJson<BuilderDraftPayload>(`/organizer/builder/drafts/${id}`, { auth })
}

export async function saveBuilderDraft(auth: WpAuth | undefined, id: number, revision: number, patch: Record<string, unknown>): Promise<BuilderSaveResult> {
  try {
    requireLive(auth)
    const payload = await wpFetchJson<BuilderDraftPayload>(`/organizer/builder/drafts/${id}`, { method: "PATCH", auth, body: { revision, patch } })
    return { kind: "saved", payload }
  } catch (err) {
    if (err instanceof WpFetchError && err.status === 409) {
      return { kind: "conflict", message: "This draft changed on another device. Reload to see the latest version." }
    }
    return { kind: "error", message: errorMessage(err, "Couldn't save. Your changes are kept on this screen.") }
  }
}

export async function deleteBuilderDraft(auth: WpAuth | undefined, id: number): Promise<void> {
  requireLive(auth)
  await wpFetchJson(`/organizer/builder/drafts/${id}`, { method: "DELETE", auth })
}

export type BuilderPreview = {
  title: string
  date: string
  time: string
  place: string
  description: string
  options: Array<{ name: string; category: string; price: number | null; free: boolean }>
  sources: Array<{ url: string; label: string }>
  disclosure: string
}

export async function fetchBuilderPreview(auth: WpAuth | undefined, id: number, mode: "vendor" | "guest" = "vendor"): Promise<BuilderPreview> {
  requireLive(auth)
  return wpFetchJson<BuilderPreview>(`/organizer/builder/drafts/${id}/preview`, { auth, query: { mode } })
}

export type BuilderDates = { occurrences: Array<{ date: string; excluded: boolean; past: boolean; conflict: string }>; count: number; first: string; last: string; deadline: string }

export async function fetchBuilderDates(auth: WpAuth | undefined, id: number): Promise<BuilderDates> {
  requireLive(auth)
  return wpFetchJson<BuilderDates>(`/organizer/builder/drafts/${id}/dates`, { auth })
}

export type BuilderLibrary = {
  templates: Array<{ id: number; name: string; version: number; booths: Array<{ label: string; price: number }> }>
  starters: Array<{ key: string; name: string; categories: string[] }>
}

export async function fetchBuilderLibrary(auth: WpAuth | undefined): Promise<BuilderLibrary> {
  requireLive(auth)
  const data = await wpFetchJson<BuilderLibrary>("/organizer/builder/library", { auth })
  return { templates: data.templates ?? [], starters: data.starters ?? [] }
}

export type BuilderRecentEvent = { id: number; title: string; date: string; city: string }

export async function fetchRecentEvents(auth: WpAuth | undefined): Promise<BuilderRecentEvent[]> {
  requireLive(auth)
  const data = await wpFetchJson<{ recent_events?: BuilderRecentEvent[] }>("/organizer/builder/recommendations", { auth })
  return data.recent_events ?? []
}

type RawPublish = {
  published?: boolean
  portfolio?: boolean
  scheduled?: boolean
  publish_at?: string
  event_id?: number
  tour_id?: number
  title?: string
  public_url?: string
  apply_url?: string
  dates?: number
  live?: number
  background?: boolean
  type?: string
}

/** Map a publish response (or error) to a result the screen can switch on exhaustively. */
export function classifyPublish(data: RawPublish | null, err?: unknown): BuilderPublishResult {
  if (err) {
    if (err instanceof WpFetchError) {
      const checks = (err.data?.checks as BuilderCheck[] | undefined) ?? []
      if (err.status === 422 && checks.length) return { kind: "not_ready", checks, message: err.message }
      if (err.status === 409 && err.code === "lnkup_builder_confirm") {
        const review = err.data?.review as { booked?: number } | undefined
        return { kind: "confirm", booked: review?.booked ?? 0, message: err.message }
      }
      return { kind: "error", message: err.message }
    }
    return { kind: "error", message: errorMessage(err, "Couldn't publish. Your draft is saved — try again.") }
  }
  if (!data) return { kind: "error", message: "Unexpected response. Try again." }
  if (data.scheduled) return { kind: "scheduled", publishAt: data.publish_at ?? "" }
  if (data.portfolio) {
    return { kind: "portfolio", tourId: data.tour_id ?? 0, title: data.title ?? "", dates: data.dates ?? 0, live: data.live ?? 0, background: Boolean(data.background), type: data.type ?? "series" }
  }
  return { kind: "published", eventId: data.event_id ?? 0, title: data.title ?? "", publicUrl: data.public_url ?? "", applyUrl: data.apply_url ?? "" }
}

export async function publishBuilderDraft(auth: WpAuth | undefined, id: number, confirm = false): Promise<BuilderPublishResult> {
  try {
    requireLive(auth)
    const data = await wpFetchJson<RawPublish>(`/organizer/builder/drafts/${id}/publish`, { method: "POST", auth, body: confirm ? { confirm: true } : {} })
    invalidateOrganizerEvents()
    return classifyPublish(data)
  } catch (err) {
    return classifyPublish(null, err)
  }
}

export async function startEventEdit(auth: WpAuth | undefined, eventId: number): Promise<BuilderDraftPayload> {
  requireLive(auth)
  return wpFetchJson<BuilderDraftPayload>(`/organizer/builder/events/${eventId}/edit`, { method: "POST", auth })
}
