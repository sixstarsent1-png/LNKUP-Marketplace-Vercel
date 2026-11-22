import type { WpApplicationQuestion } from "@/lib/api/types"

/** One vendor slot on a tour stop, as returned by GET /tours/{id}/apply-context. */
export interface TourApplySlot {
  opportunityId: number
  eventId: number
  stopLabel: string
  date: string
  title: string
  fee: number
  quoteTotal: number | null
  canApply: boolean
  blockReason: string
  applicationStatus: string
  matchesMe: boolean
  categories: string[]
  defaultCategory: string
  questions: WpApplicationQuestion[]
}

export interface TourApplyDraft {
  groupId: string
  revision: number
  selections: Array<{ event_id: number; opportunity_id: number }>
  shared: { application_message?: string; setup_notes?: string; selected_category?: string }
  perStop: Record<string, { selected_category?: string; answers?: Record<string, unknown> }>
}

export interface TourApplyContext {
  tourId: number
  title: string
  slots: TourApplySlot[]
  draft: TourApplyDraft | null
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

export interface TourApplyResult {
  group_id: string
  tour_id: number
  results: Array<{
    event_id: number
    opportunity_id: number
    event_name: string
    city: string
    date: string
    result: TourStopResult
    code?: string
    message?: string
    fields?: string[]
    application_id?: number
  }>
  summary: { submitted: number; already: number; failed: number; total: number; all_succeeded: boolean }
}

type WpTourApplyOpportunity = {
  opportunity_id?: number
  title?: string
  vendor_fee?: number
  is_open?: boolean
  matches_me?: boolean
  application_status?: string
  quote?: { total?: number }
  questions?: WpApplicationQuestion[]
  can_apply?: boolean
  block_reason?: string
  categories?: string[]
  default_category?: string
}

export type WpTourApplyContext = {
  tour?: {
    id?: number
    title?: string
    stops?: Array<{
      event_id?: number
      title?: string
      date?: string
      city?: string
      state?: string
      opportunities?: WpTourApplyOpportunity[]
    }>
  }
  draft?: {
    group_id?: string
    revision?: number
    payload?: {
      selections?: Array<{ event_id?: number; opportunity_id?: number }>
      shared?: TourApplyDraft["shared"]
      per_stop?: TourApplyDraft["perStop"]
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
        quoteTotal: typeof opp.quote?.total === "number" ? opp.quote.total : null,
        canApply: Boolean(opp.can_apply),
        blockReason: opp.block_reason ?? "",
        applicationStatus: opp.application_status ?? "",
        matchesMe: Boolean(opp.matches_me),
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
          selections: (draft.payload?.selections ?? [])
            .filter((s) => s.opportunity_id)
            .map((s) => ({ event_id: Number(s.event_id ?? 0), opportunity_id: Number(s.opportunity_id) })),
          shared: draft.payload?.shared ?? {},
          perStop: draft.payload?.per_stop ?? {},
        }
      : null,
  }
}

/** Allowlisted multi-stop body forwarded to WordPress (selections, shared, per_stop, group_id). */
export function tourApplyBody(body: Record<string, unknown>) {
  const selections = Array.isArray(body.selections)
    ? body.selections
        .map((s) => (s && typeof s === "object" ? (s as Record<string, unknown>) : {}))
        .map((s) => ({ event_id: Number(s.event_id ?? 0), opportunity_id: Number(s.opportunity_id ?? 0) }))
        .filter((s) => s.opportunity_id > 0)
        .slice(0, 60)
    : []
  return {
    group_id: typeof body.group_id === "string" ? body.group_id : "",
    selections,
    shared: body.shared && typeof body.shared === "object" ? body.shared : {},
    per_stop: body.per_stop && typeof body.per_stop === "object" ? body.per_stop : {},
    client: "web",
  }
}

const RESULT_COPY: Record<TourStopResult, string> = {
  submitted: "Application sent",
  already_submitted: "Already sent",
  already_applied: "You already applied to this stop",
  closed: "Applications closed",
  full: "No spots left",
  ineligible: "Not available to you",
  needs_input: "Needs more information",
  error: "Something went wrong",
}

/** Short vendor-facing label for a per-stop result. */
export function tourStopResultLabel(result: TourStopResult): string {
  return RESULT_COPY[result]
}
