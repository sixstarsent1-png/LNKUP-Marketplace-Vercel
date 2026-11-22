import { isLiveApiEnabled } from "@/lib/api/config"
import { invalidateVendorRequests } from "@/lib/api/vendor-requests"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_APPLY_CONTEXT } from "@/lib/data/fixtures"

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
}

type WpDraft = {
  id?: number
  revision?: number
  updated_at?: string
  fields?: { selected_category?: string; application_message?: string; setup_notes?: string }
  answers?: Record<string, ApplyAnswerValue>
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

export async function discardApplyDraft(opportunityId: number, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled() || !auth) return
  await wpFetchJson(`/applications/drafts/${opportunityId}`, { method: "DELETE", auth })
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
