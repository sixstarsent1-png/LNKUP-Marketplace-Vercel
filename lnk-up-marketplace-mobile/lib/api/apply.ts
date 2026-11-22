import { isLiveApiEnabled } from "@/lib/api/config"
import { invalidateVendorRequests } from "@/lib/api/vendor-requests"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_APPLY_CONTEXT } from "@/lib/data/fixtures"
import { formatShortDate } from "@/lib/format/date"

export type ApplyContext = {
  opportunityId: number
  eventName: string
  eventDate?: string
  city?: string
  state?: string
  venue?: string
  vendorFee?: number
  categories: string[]
  defaultCategory?: string
  canApply: boolean
  blockReason?: string
  buttonLabel: string
  buttonDisabled: boolean
  warnings: string[]
  organizerName?: string
  excerpt?: string
  questions: ApplyQuestion[]
  vendorDocuments: Array<{ id: number; label: string }>
  draft: ApplyDraft | null
  previousApplication: PreviousApplication | null
  tourId: number | null
  features: { drafts: boolean; tourMultiApply: boolean }
  /** What the organizer will see from the vendor profile; null when the vendor has no profile yet. */
  profile: ApplyProfile | null
}

/** Profile snapshot fields a vendor may change for one application (LNKup_Applications::PROFILE_OVERRIDE_FIELDS). */
export const PROFILE_OVERRIDE_FIELDS = ["businessName", "products", "setupNeeds", "contactEmail", "contactPhone"] as const

export type ProfileOverrideField = (typeof PROFILE_OVERRIDE_FIELDS)[number]

export type ApplyProfile = Record<ProfileOverrideField, string>

export type ProfileOverrides = Partial<ApplyProfile>

const OVERRIDE_WIRE: Record<ProfileOverrideField, string> = {
  businessName: "business_name",
  products: "products",
  setupNeeds: "setup_needs",
  contactEmail: "contact_email",
  contactPhone: "contact_phone",
}

type WpProfileFields = Partial<Record<string, unknown>>

function mapProfileFields(raw: WpProfileFields | null | undefined): ProfileOverrides {
  const out: ProfileOverrides = {}
  if (!raw || typeof raw !== "object") return out
  for (const field of PROFILE_OVERRIDE_FIELDS) {
    const value = raw[OVERRIDE_WIRE[field]]
    if (typeof value === "string") out[field] = value
  }
  return out
}

/** Only the fields that differ from the profile, so unchanged ones keep tracking it. */
export function changedProfileFields(overrides: ProfileOverrides, profile: ApplyProfile | null): ProfileOverrides {
  const out: ProfileOverrides = {}
  for (const field of PROFILE_OVERRIDE_FIELDS) {
    const value = overrides[field]
    if (value === undefined) continue
    const trimmed = value.trim()
    if (trimmed !== (profile?.[field] ?? "").trim()) out[field] = trimmed
  }
  return out
}

/** Wire body for `profile_overrides`. */
export function profileOverridesBody(overrides: ProfileOverrides | undefined): Record<string, string> {
  const body: Record<string, string> = {}
  for (const field of PROFILE_OVERRIDE_FIELDS) {
    const value = overrides?.[field]
    if (value !== undefined) body[OVERRIDE_WIRE[field]] = value
  }
  return body
}

export type ApplyAnswerValue = string | boolean | number

export type ApplyQuestion = {
  id: string
  label: string
  type: "text" | "textarea" | "select" | "checkbox" | "file"
  required: boolean
  options: string[]
  help: string
}

export type ApplyDraft = {
  id: number
  revision: number
  updatedAt: string
  fields: { selectedCategory: string; message: string; setupNotes: string }
  answers: Record<string, ApplyAnswerValue>
  profileOverrides: ProfileOverrides
}

export type PreviousApplication = {
  id: number
  status: string
  reapply: "allowed" | "blocked"
  reason: string
}

export type ApplyResult = {
  success: boolean
  applicationId?: string
  status?: string
  message?: string
  redirectUrl?: string
  warnings?: string[]
}

type WpApplyContext = {
  opportunity?: {
    opportunity_id?: number
    id?: number
    name?: string
    event_name?: string
    date?: string
    event_date?: string
    city?: string
    state?: string
    venue_name?: string
    vendor_fee?: number
    organizer_name?: string
    categories_needed?: string[]
    excerpt?: string
    description?: string
  }
  eligibility?: {
    can_apply?: boolean
    block_reason?: string
  }
  categories?: string[]
  default_category?: string
  button_state?: {
    label?: string
    disabled?: boolean
    reason?: string
  }
  warnings?: Array<string | { message?: string; code?: string }>
  questions?: ApplyQuestion[]
  vendor_documents?: Array<{ id: number; label: string }>
  draft?: WpDraft | null
  previous_application?: PreviousApplication | null
  tour_id?: number
  features?: { drafts?: boolean; tour_multi_apply?: boolean }
  profile?: WpProfileFields | null
}

type WpDraft = {
  id?: number
  revision?: number
  updated_at?: string
  fields?: { selected_category?: string; application_message?: string; setup_notes?: string }
  answers?: Record<string, ApplyAnswerValue>
  profile_overrides?: WpProfileFields | unknown[]
}

type WpApplyResult = {
  success?: boolean
  application_id?: number
  status?: string
  message?: string
  redirect_url?: string
  warnings?: string[]
}

function normalizeWarnings(
  warnings: Array<string | { message?: string; code?: string }> | undefined,
): string[] {
  if (!Array.isArray(warnings)) return []
  const out: string[] = []
  for (const item of warnings) {
    if (typeof item === "string") {
      const trimmed = item.trim()
      if (trimmed) out.push(trimmed)
      continue
    }
    if (item && typeof item === "object") {
      const message = typeof item.message === "string" ? item.message.trim() : ""
      if (message) out.push(message)
    }
  }
  return out
}

export function mapApplyContext(opportunityId: number, data: WpApplyContext): ApplyContext {
  const opp = data.opportunity ?? {}
  const categories = data.categories ?? opp.categories_needed ?? []
  const canApply = data.eligibility?.can_apply !== false && !data.button_state?.disabled

  return {
    opportunityId: opp.opportunity_id ?? opp.id ?? opportunityId,
    eventName: opp.name ?? opp.event_name ?? "Event opportunity",
    eventDate: opp.date ?? opp.event_date,
    city: opp.city,
    state: opp.state,
    venue: opp.venue_name,
    vendorFee: opp.vendor_fee,
    categories,
    defaultCategory: data.default_category ?? categories[0],
    canApply,
    blockReason: data.eligibility?.block_reason ?? data.button_state?.reason,
    buttonLabel: data.button_state?.label ?? (canApply ? "Submit application" : "Unavailable"),
    buttonDisabled: Boolean(data.button_state?.disabled) || !canApply,
    warnings: normalizeWarnings(data.warnings),
    organizerName: opp.organizer_name,
    excerpt: (opp.excerpt ?? opp.description)?.trim() || undefined,
    questions: Array.isArray(data.questions) ? data.questions : [],
    vendorDocuments: Array.isArray(data.vendor_documents) ? data.vendor_documents : [],
    draft: mapDraft(data.draft),
    previousApplication: data.previous_application ?? null,
    tourId: data.tour_id ? Number(data.tour_id) : null,
    features: { drafts: Boolean(data.features?.drafts), tourMultiApply: Boolean(data.features?.tour_multi_apply) },
    profile: data.profile ? { businessName: "", products: "", setupNeeds: "", contactEmail: "", contactPhone: "", ...mapProfileFields(data.profile) } : null,
  }
}

export function mapDraft(draft: WpDraft | null | undefined): ApplyDraft | null {
  if (!draft?.id) return null
  return {
    id: Number(draft.id),
    revision: Number(draft.revision ?? 0),
    updatedAt: draft.updated_at ?? "",
    fields: {
      selectedCategory: draft.fields?.selected_category ?? "",
      message: draft.fields?.application_message ?? "",
      setupNotes: draft.fields?.setup_notes ?? "",
    },
    answers: draft.answers ?? {},
    // PHP encodes an empty overrides map as [].
    profileOverrides: Array.isArray(draft.profile_overrides) ? {} : mapProfileFields(draft.profile_overrides),
  }
}

/** Idempotency key for one submit attempt (8–64 chars of [A-Za-z0-9_-]); reuse it on retries. */
export function newIdempotencyKey(): string {
  let key = Date.now().toString(36)
  while (key.length < 32) key += Math.random().toString(36).slice(2)
  return key.slice(0, 32)
}

/** Vendor-facing note when applying again after an earlier application closed. */
export function reapplyNote(previous: PreviousApplication | null): string | null {
  if (!previous || previous.reapply !== "allowed") return null
  switch (previous.reason) {
    case "organizer_allowed":
      return "The organizer re-opened this booth for you. You can apply again."
    case "invited_again":
      return "The organizer invited you again. You can apply again."
    case "opportunity_changed":
      return "This booth changed since your last application. You can apply again."
    default:
      return "You withdrew an earlier application for this booth. You can apply again."
  }
}

export const DECLINED_MESSAGE =
  "This organizer declined your earlier application. You can apply again if they invite you or change this opportunity."

export async function fetchApplyContext(
  opportunityId: number,
  auth?: WpAuth,
): Promise<ApplyContext> {
  if (!isLiveApiEnabled()) {
    return {
      ...MOCK_APPLY_CONTEXT,
      opportunityId,
    }
  }

  const data = await wpFetchJson<WpApplyContext>(`/applications/apply-context/${opportunityId}`, {
    auth,
  })
  return mapApplyContext(opportunityId, data)
}

export async function submitApplication(
  input: {
    opportunityId: number
    selectedCategory?: string
    message?: string
    setupNotes?: string
    acceptedTerms: boolean
    answers?: Record<string, ApplyAnswerValue>
    profileOverrides?: ProfileOverrides
    idempotencyKey?: string
  },
  auth?: WpAuth,
): Promise<ApplyResult> {
  if (!isLiveApiEnabled()) {
    return {
      success: true,
      applicationId: `mock-${input.opportunityId}`,
      status: "submitted",
      message: "Mock application submitted. Check Requests.",
    }
  }

  if (!auth) {
    throw new Error("Sign in to apply.")
  }

  const data = await wpFetchJson<WpApplyResult>("/applications/apply-with-profile", {
    method: "POST",
    auth,
    headers: input.idempotencyKey ? { "Idempotency-Key": input.idempotencyKey } : undefined,
    body: {
      opportunity_id: input.opportunityId,
      selected_category: input.selectedCategory ?? "",
      application_message: input.message ?? "",
      setup_notes: input.setupNotes ?? "",
      accepted_terms: input.acceptedTerms,
      answers: input.answers ?? {},
      profile_overrides: profileOverridesBody(input.profileOverrides),
      client: "app",
      source: "app",
    },
  })
  invalidateVendorRequests()
  return mapApplyResult(data)
}

function mapApplyResult(data: WpApplyResult): ApplyResult {
  return {
    success: Boolean(data.success ?? data.application_id),
    applicationId: data.application_id ? String(data.application_id) : undefined,
    status: data.status,
    message: data.message ?? "Application submitted.",
    redirectUrl: data.redirect_url,
    warnings: data.warnings,
  }
}

/* ------------------------------------------------------------------ */
/* Drafts (server-side, resumable on any device)                       */
/* ------------------------------------------------------------------ */

export type DraftInput = {
  selectedCategory: string
  message: string
  setupNotes: string
  answers: Record<string, ApplyAnswerValue>
  /** Changed fields only; see changedProfileFields. */
  profileOverrides: ProfileOverrides
}

/** Autosave; pass the last revision seen. A 409 `lnkup_draft_conflict` carries the newer draft in `data.draft`. */
export async function saveApplyDraft(
  opportunityId: number,
  input: DraftInput,
  revision: number | null,
  auth?: WpAuth,
): Promise<ApplyDraft | null> {
  if (!isLiveApiEnabled() || !auth) return null
  const data = await wpFetchJson<{ draft?: WpDraft }>(`/applications/drafts/${opportunityId}`, {
    method: "PUT",
    auth,
    body: {
      step: "review",
      fields: { selected_category: input.selectedCategory, application_message: input.message, setup_notes: input.setupNotes },
      answers: input.answers,
      profile_overrides: profileOverridesBody(input.profileOverrides),
      revision,
      client: "app",
    },
  })
  return mapDraft(data.draft)
}

export async function submitApplyDraft(
  opportunityId: number,
  input: { acceptedTerms: boolean; acceptCurrentPrice: boolean; idempotencyKey: string },
  auth?: WpAuth,
): Promise<ApplyResult> {
  if (!auth) throw new Error("Sign in to apply.")
  const data = await wpFetchJson<WpApplyResult>(`/applications/drafts/${opportunityId}/submit`, {
    method: "POST",
    auth,
    headers: { "Idempotency-Key": input.idempotencyKey },
    body: { accepted_terms: input.acceptedTerms, accept_current_price: input.acceptCurrentPrice },
  })
  invalidateVendorRequests()
  return mapApplyResult(data)
}

/**
 * Full open draft for an opportunity. Apply context omits profile overrides from its draft, and
 * autosave replaces them wholesale, so the apply screen must read them here before saving.
 */
export async function fetchApplyDraft(opportunityId: number, auth?: WpAuth): Promise<ApplyDraft | null> {
  if (!isLiveApiEnabled() || !auth) return null
  const data = await wpFetchJson<{ draft?: WpDraft | null }>(`/applications/drafts/${opportunityId}`, { auth })
  return mapDraft(data.draft)
}

export async function discardApplyDraft(opportunityId: number, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled() || !auth) return
  await wpFetchJson(`/applications/drafts/${opportunityId}`, { method: "DELETE", auth })
}

/** Resume card for an unfinished application (`GET /applications/drafts`). */
export type ApplyDraftCard = {
  id: number
  opportunityId: number
  eventName: string
  boothName: string
  eventDate?: string
  location: string
  deadline?: string
  updatedAt?: string
  progressPercent: number
  canSubmit: boolean
  priceChanged: boolean
}

type WpDraftCard = {
  id?: number
  opportunity_id?: number
  event_name?: string
  opportunity_title?: string
  event_date?: string
  location_label?: string
  deadline?: string
  updated_at?: string
  progress_percent?: number
  can_submit?: boolean
  price?: { changed?: boolean }
}

export function mapDraftCards(data: { items?: WpDraftCard[] } | null | undefined): ApplyDraftCard[] {
  const items = Array.isArray(data?.items) ? data.items : []
  return items
    .filter((item) => item.id && item.opportunity_id)
    .map((item) => ({
      id: Number(item.id),
      opportunityId: Number(item.opportunity_id),
      eventName: item.event_name || item.opportunity_title || "Event",
      boothName: item.opportunity_title && item.opportunity_title !== item.event_name ? item.opportunity_title : "",
      eventDate: formatShortDate(item.event_date),
      location: item.location_label ?? "",
      deadline: formatShortDate(item.deadline),
      updatedAt: formatShortDate(item.updated_at),
      progressPercent: Math.max(0, Math.min(100, Number(item.progress_percent ?? 0))),
      canSubmit: item.can_submit !== false,
      priceChanged: Boolean(item.price?.changed),
    }))
}

export async function fetchApplyDrafts(auth?: WpAuth): Promise<ApplyDraftCard[]> {
  if (!isLiveApiEnabled() || !auth) return []
  const data = await wpFetchJson<{ items?: WpDraftCard[] }>("/applications/drafts", { auth })
  return mapDraftCards(data)
}

/* ------------------------------------------------------------------ */
/* Price estimate (LNKup_Fee_Engine via REST; never computed here)     */
/* ------------------------------------------------------------------ */

export type PriceQuote = {
  currency: string
  lines: Array<{ key: string; label: string; amount: number }>
  total: number
  note: string
}

type WpPriceQuote = {
  currency?: string
  lines?: Array<{ key?: string; label?: string; amount?: number }>
  total?: number
  note?: string
}

export function mapPriceQuote(data: WpPriceQuote): PriceQuote | null {
  const lines = (Array.isArray(data.lines) ? data.lines : [])
    .filter((line) => line.key && line.label)
    .map((line) => ({ key: String(line.key), label: String(line.label), amount: Number(line.amount ?? 0) || 0 }))
  const total = Number(data.total)
  if (!lines.length || !Number.isFinite(total)) return null
  return { currency: data.currency || "USD", lines, total, note: data.note ?? "" }
}

export async function fetchPriceQuote(opportunityId: number, category: string, auth?: WpAuth): Promise<PriceQuote | null> {
  if (!isLiveApiEnabled() || !auth) return null
  const data = await wpFetchJson<WpPriceQuote>(`/applications/price-quote/${opportunityId}`, {
    auth,
    query: category ? { category } : undefined,
  })
  return mapPriceQuote(data)
}

/* ------------------------------------------------------------------ */
/* Withdraw                                                            */
/* ------------------------------------------------------------------ */

/** Statuses a vendor may withdraw (mirrors LNKup_Applications::WITHDRAWABLE). */
const WITHDRAWABLE = ["submitted", "under_review", "info_requested", "waitlisted", "approved", "payment_required"]
const PAYMENT_IN_PROGRESS = ["processing", "paid", "partially_paid"]

export function canWithdraw(status: string | undefined, paymentStatus?: string): boolean {
  const normalized = (status ?? "").toLowerCase().replace(/-/g, "_")
  return WITHDRAWABLE.includes(normalized) && !PAYMENT_IN_PROGRESS.includes((paymentStatus ?? "").toLowerCase())
}

export async function withdrawApplication(
  applicationId: number | string,
  auth?: WpAuth,
): Promise<{ status: string; statusLabel: string }> {
  if (!auth) throw new Error("Sign in to manage your applications.")
  const data = await wpFetchJson<{ status?: string; status_label?: string }>(
    `/applications/${encodeURIComponent(String(applicationId))}/withdraw`,
    { method: "POST", auth, body: {} },
  )
  invalidateVendorRequests()
  return { status: data.status ?? "cancelled", statusLabel: data.status_label ?? "Withdrawn" }
}

/* ------------------------------------------------------------------ */
/* Tour multi-stop apply                                               */
/* ------------------------------------------------------------------ */

export type TourApplySlot = {
  opportunityId: number
  eventId: number
  stopLabel: string
  date: string
  title: string
  fee: number
  canApply: boolean
  blockReason: string
  categories: string[]
  defaultCategory: string
  questions: ApplyQuestion[]
}

export type TourPerStop = Record<string, { selected_category?: string; answers?: Record<string, ApplyAnswerValue> }>

export type TourApplyContext = {
  tourId: number
  title: string
  slots: TourApplySlot[]
  draft: {
    groupId: string
    revision: number
    selections: number[]
    message: string
    setupNotes: string
    perStop: TourPerStop
  } | null
}

export type TourStopResult =
  | "submitted"
  | "already_submitted"
  | "already_applied"
  | "closed"
  | "full"
  | "ineligible"
  | "needs_input"
  | "error"

export type TourApplyResult = {
  results: Array<{ opportunityId: number; place: string; result: TourStopResult; message: string }>
  submitted: number
  failed: number
  total: number
  allSucceeded: boolean
}

type WpTourApplyContext = {
  tour?: {
    id?: number
    title?: string
    stops?: Array<{
      event_id?: number
      title?: string
      date?: string
      city?: string
      state?: string
      opportunities?: Array<{
        opportunity_id?: number
        title?: string
        vendor_fee?: number
        can_apply?: boolean
        block_reason?: string
        categories?: string[]
        default_category?: string
        questions?: ApplyQuestion[]
      }>
    }>
  }
  draft?: {
    group_id?: string
    revision?: number
    payload?: {
      selections?: Array<{ opportunity_id?: number }>
      shared?: { application_message?: string; setup_notes?: string }
      per_stop?: TourPerStop
    }
  } | null
}

export function mapTourApplyContext(data: WpTourApplyContext): TourApplyContext {
  const slots: TourApplySlot[] = []
  for (const stop of data.tour?.stops ?? []) {
    const stopLabel = [stop.city, stop.state].filter(Boolean).join(", ") || stop.title || "Stop"
    for (const opp of stop.opportunities ?? []) {
      if (!opp.opportunity_id) continue
      slots.push({
        opportunityId: opp.opportunity_id,
        eventId: Number(stop.event_id ?? 0),
        stopLabel,
        date: stop.date ?? "",
        title: opp.title ?? "",
        fee: Number(opp.vendor_fee ?? 0),
        canApply: Boolean(opp.can_apply),
        blockReason: opp.block_reason ?? "",
        categories: opp.categories ?? [],
        defaultCategory: opp.default_category ?? "",
        questions: opp.questions ?? [],
      })
    }
  }
  const draft = data.draft
  return {
    tourId: Number(data.tour?.id ?? 0),
    title: data.tour?.title ?? "",
    slots,
    draft: draft?.group_id
      ? {
          groupId: draft.group_id,
          revision: Number(draft.revision ?? 0),
          selections: (draft.payload?.selections ?? []).map((s) => Number(s.opportunity_id ?? 0)).filter((id) => id > 0),
          message: draft.payload?.shared?.application_message ?? "",
          setupNotes: draft.payload?.shared?.setup_notes ?? "",
          perStop: draft.payload?.per_stop ?? {},
        }
      : null,
  }
}

export async function fetchTourApplyContext(tourId: number, auth?: WpAuth): Promise<TourApplyContext> {
  const data = await wpFetchJson<WpTourApplyContext>(`/tours/${tourId}/apply-context`, { auth })
  return mapTourApplyContext(data)
}

export type TourApplyInput = {
  groupId: string
  slots: TourApplySlot[]
  selected: number[]
  message: string
  setupNotes: string
  perStop: TourPerStop
}

/** WordPress body for the multi-stop draft / submit routes. */
export function tourApplyBody(input: TourApplyInput) {
  return {
    group_id: input.groupId,
    selections: input.slots
      .filter((slot) => input.selected.includes(slot.opportunityId))
      .map((slot) => ({ event_id: slot.eventId, opportunity_id: slot.opportunityId })),
    shared: { application_message: input.message, setup_notes: input.setupNotes },
    per_stop: input.perStop,
    client: "app",
  }
}

export async function saveTourApplyDraft(
  tourId: number,
  input: TourApplyInput,
  revision: number | null,
  auth?: WpAuth,
): Promise<number | null> {
  if (!auth) return null
  const data = await wpFetchJson<{ draft?: { revision?: number } }>(`/tours/${tourId}/apply-draft`, {
    method: "PUT",
    auth,
    body: { ...tourApplyBody(input), revision },
  })
  return data.draft?.revision ?? null
}

export async function submitTourApply(
  tourId: number,
  input: TourApplyInput & { idempotencyKey: string },
  auth?: WpAuth,
): Promise<TourApplyResult> {
  if (!auth) throw new Error("Sign in to apply.")
  const data = await wpFetchJson<{
    results?: Array<{ opportunity_id?: number; city?: string; event_name?: string; result?: TourStopResult; message?: string }>
    summary?: { submitted?: number; already?: number; failed?: number; total?: number; all_succeeded?: boolean }
  }>(`/tours/${tourId}/apply`, {
    method: "POST",
    auth,
    headers: { "Idempotency-Key": input.idempotencyKey },
    body: { ...tourApplyBody(input), accepted_terms: true },
  })
  invalidateVendorRequests()
  return {
    results: (data.results ?? []).map((row) => ({
      opportunityId: Number(row.opportunity_id ?? 0),
      place: row.city || row.event_name || "Stop",
      result: row.result ?? "error",
      message: row.message ?? "",
    })),
    submitted: Number(data.summary?.submitted ?? 0) + Number(data.summary?.already ?? 0),
    failed: Number(data.summary?.failed ?? 0),
    total: Number(data.summary?.total ?? 0),
    allSucceeded: Boolean(data.summary?.all_succeeded),
  }
}

/** Short vendor-facing label for a per-stop result. */
export function tourStopResultLabel(result: TourStopResult): string {
  switch (result) {
    case "submitted":
      return "Application sent"
    case "already_submitted":
      return "Already sent"
    case "already_applied":
      return "You already applied to this stop"
    case "closed":
      return "Applications closed"
    case "full":
      return "No spots left"
    case "ineligible":
      return "Not available to you"
    case "needs_input":
      return "Needs more information"
    case "error":
      return "Something went wrong"
    default: {
      const unreachable: never = result
      return unreachable
    }
  }
}
