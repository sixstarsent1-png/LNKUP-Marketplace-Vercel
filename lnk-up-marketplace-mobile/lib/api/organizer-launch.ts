import AsyncStorage from "@react-native-async-storage/async-storage"

import { isValidIsoDate } from "@/components/iso-date-picker-field"
import { getOrganizerDashboardUrl, isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import { invalidateOrganizerEvents } from "@/lib/api/organizer-events"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

const DRAFT_KEY = "lnkup_organizer_launch_draft"

export type OrganizerLaunchFeeRow = {
  category: string
  vendorFee: number
  spotsTotal: number
}

export type OrganizerLaunchDraft = {
  event: {
    title: string
    date?: string
    startTime?: string
    endTime?: string
    city?: string
    state?: string
    venueName?: string
    venueAddress?: string
    eventType?: string
    expectedAttendance?: number
    description?: string
    externalUrl?: string
  }
  opportunity: {
    feeRows: OrganizerLaunchFeeRow[]
    currency?: string
    applicationDeadline?: string
    indoor?: boolean
    outdoor?: boolean
    requiredDocumentsMode?: "none" | "platform_defaults" | "custom"
    applicationInstructions?: string
  }
  status: "draft" | "ready_to_publish" | "published" | "handoff_required" | "error"
  /** Event id once "Save draft" has created the event on WordPress (no vendor slots yet). */
  serverEventId?: string
  /** Idempotency key for this draft's create/publish; survives app restarts with the draft. */
  clientToken?: string
}

export type OrganizerLaunchPublishResult = {
  /** partial = event saved but vendor slots were not; retry with the same token. */
  status: "published" | "partial" | "validation_error" | "auth_required" | "forbidden" | "error"
  eventId?: string
  opportunityId?: string
  applicationUrl?: string
  message?: string
  validationErrors?: Array<{ field: string; message: string }>
}

export const LAUNCH_CATEGORIES = [
  "Food Vendor",
  "Retail Vendor",
  "Food Truck",
  "Beauty Vendor",
  "Clothing Brand",
  "Beverage Vendor",
] as const

export function emptyFeeRow(): OrganizerLaunchFeeRow {
  return { category: "", vendorFee: 250, spotsTotal: 10 }
}

export function emptyLaunchDraft(): OrganizerLaunchDraft {
  return {
    event: { title: "" },
    opportunity: {
      feeRows: [emptyFeeRow()],
      requiredDocumentsMode: "platform_defaults",
      outdoor: true,
    },
    status: "draft",
  }
}

export function buildMockLaunchDraft(): OrganizerLaunchDraft {
  return {
    event: {
      title: "Charlotte Night Market",
      date: "2026-09-15",
      city: "Charlotte",
      state: "NC",
      venueName: "Uptown Green",
      eventType: "Market",
      expectedAttendance: 5000,
      description: "Monthly night market featuring local vendors.",
    },
    opportunity: {
      feeRows: [
        { category: "Food Vendor", vendorFee: 200, spotsTotal: 12 },
        { category: "Retail Vendor", vendorFee: 150, spotsTotal: 8 },
      ],
      currency: "USD",
      applicationDeadline: "2026-09-01",
      indoor: false,
      outdoor: true,
      requiredDocumentsMode: "platform_defaults",
      applicationInstructions: "Bring your menu and photos of your setup.",
    },
    status: "ready_to_publish",
  }
}

/** Normalize persisted drafts that still use legacy categories/vendorFee/spotsTotal. */
export function normalizeLaunchDraft(raw: unknown): OrganizerLaunchDraft {
  const base = emptyLaunchDraft()
  if (!raw || typeof raw !== "object") return base
  const draft = raw as Partial<OrganizerLaunchDraft> & {
    opportunity?: Partial<OrganizerLaunchDraft["opportunity"]> & {
      categories?: string[]
      vendorFee?: number
      spotsTotal?: number
      feeRows?: OrganizerLaunchFeeRow[]
    }
  }

  const event = { ...base.event, ...(draft.event ?? {}) }
  const opp = draft.opportunity
  let feeRows: OrganizerLaunchFeeRow[] = []

  if (opp && Array.isArray(opp.feeRows) && opp.feeRows.length > 0) {
    feeRows = opp.feeRows.map((row) => ({
      category: String(row?.category ?? "").trim(),
      vendorFee:
        typeof row?.vendorFee === "number" && Number.isFinite(row.vendorFee)
          ? row.vendorFee
          : 250,
      spotsTotal:
        typeof row?.spotsTotal === "number" && Number.isFinite(row.spotsTotal)
          ? row.spotsTotal
          : 10,
    }))
  } else if (opp && Array.isArray(opp.categories) && opp.categories.length > 0) {
    const fee =
      typeof opp.vendorFee === "number" && Number.isFinite(opp.vendorFee) ? opp.vendorFee : 250
    const spots =
      typeof opp.spotsTotal === "number" && Number.isFinite(opp.spotsTotal) ? opp.spotsTotal : 10
    feeRows = opp.categories.map((category) => ({
      category: String(category ?? "").trim(),
      vendorFee: fee,
      spotsTotal: spots,
    }))
  } else {
    feeRows = [emptyFeeRow()]
  }

  return {
    event,
    opportunity: {
      feeRows,
      currency: opp?.currency,
      applicationDeadline: opp?.applicationDeadline,
      indoor: opp?.indoor,
      outdoor: opp?.outdoor,
      requiredDocumentsMode: opp?.requiredDocumentsMode ?? "platform_defaults",
      applicationInstructions: opp?.applicationInstructions,
    },
    status: draft.status ?? "draft",
    serverEventId: typeof draft.serverEventId === "string" ? draft.serverEventId : undefined,
    clientToken: typeof draft.clientToken === "string" ? draft.clientToken : undefined,
  }
}

/** Has the organizer entered anything worth resuming? */
export function draftHasContent(draft: OrganizerLaunchDraft): boolean {
  const e = draft.event
  return Boolean(
    draft.serverEventId ||
      e.title?.trim() ||
      e.date ||
      e.city?.trim() ||
      e.venueName?.trim() ||
      draft.opportunity.feeRows.some((row) => row.category.trim()),
  )
}

export function validateLaunchDraft(
  draft: OrganizerLaunchDraft,
): Array<{ field: string; message: string }> {
  const errors: Array<{ field: string; message: string }> = []
  if (!draft.event.title?.trim()) {
    errors.push({ field: "event.title", message: "Event title is required" })
  }
  if (!draft.event.date?.trim()) {
    errors.push({ field: "event.date", message: "Event date is required" })
  } else if (!isValidIsoDate(draft.event.date.trim())) {
    errors.push({ field: "event.date", message: "Event date must be YYYY-MM-DD" })
  }
  if (
    draft.opportunity.applicationDeadline?.trim() &&
    !isValidIsoDate(draft.opportunity.applicationDeadline.trim())
  ) {
    errors.push({
      field: "opportunity.applicationDeadline",
      message: "Application deadline must be YYYY-MM-DD",
    })
  }
  if (!draft.event.city?.trim()) {
    errors.push({ field: "event.city", message: "City is required" })
  }
  if (!draft.event.state?.trim()) {
    errors.push({ field: "event.state", message: "State is required" })
  }
  const deadline = draft.opportunity.applicationDeadline?.trim()
  const date = draft.event.date?.trim()
  if (deadline && date && isValidIsoDate(deadline) && isValidIsoDate(date) && deadline > date) {
    errors.push({
      field: "opportunity.applicationDeadline",
      message: "The application deadline must be on or before the event date",
    })
  }
  const start = draft.event.startTime?.trim()
  const end = draft.event.endTime?.trim()
  if (start && end && /^\d{2}:\d{2}$/.test(start) && /^\d{2}:\d{2}$/.test(end) && end <= start) {
    errors.push({ field: "event.time", message: "End time must be after the start time" })
  }

  const rows = draft.opportunity.feeRows ?? []
  const validRows = rows.filter(
    (row) =>
      Boolean(row.category?.trim()) &&
      typeof row.vendorFee === "number" &&
      row.vendorFee >= 0 &&
      typeof row.spotsTotal === "number" &&
      row.spotsTotal >= 1,
  )
  if (validRows.length < 1) {
    errors.push({
      field: "opportunity.feeRows",
      message: "Add at least one vendor type with a fee and spots",
    })
  } else {
    rows.forEach((row, index) => {
      if (!row.category?.trim()) {
        errors.push({
          field: `opportunity.feeRows.${index}.category`,
          message: `Vendor type ${index + 1} needs a category`,
        })
      }
      if (typeof row.vendorFee !== "number" || row.vendorFee < 0) {
        errors.push({
          field: `opportunity.feeRows.${index}.vendorFee`,
          message: `Vendor type ${index + 1} needs a fee (0 for free)`,
        })
      }
      if (typeof row.spotsTotal !== "number" || row.spotsTotal < 1) {
        errors.push({
          field: `opportunity.feeRows.${index}.spotsTotal`,
          message: `Vendor type ${index + 1} needs at least 1 spot`,
        })
      }
    })
  }
  return errors
}

export const LAUNCH_STEPS = [
  "Event details",
  "Date and location",
  "Vendor types",
  "Booth pricing",
  "Requirements",
  "Preview and publish",
] as const

/** Fields each step owns (errors outside the step are checked when the organizer gets there). */
const STEP_FIELDS: Array<(field: string) => boolean> = [
  (f) => f === "event.title",
  (f) => f === "event.date" || f === "event.city" || f === "event.state" || f === "event.time",
  (f) => f === "opportunity.feeRows" || /^opportunity\.feeRows\.\d+\.category$/.test(f),
  (f) => /^opportunity\.feeRows\.\d+\.(vendorFee|spotsTotal)$/.test(f),
  (f) => f.startsWith("opportunity.applicationDeadline"),
  () => true,
]

export function validateLaunchStep(
  draft: OrganizerLaunchDraft,
  step: number,
): Array<{ field: string; message: string }> {
  const all = validateLaunchDraft(draft)
  const owns = STEP_FIELDS[Math.min(Math.max(step, 0), STEP_FIELDS.length - 1)]
  return all.filter((e) => owns(e.field))
}

/** First step that has a problem, so publish can jump straight to it. */
export function firstInvalidStep(errors: Array<{ field: string }>): number {
  for (let step = 0; step < STEP_FIELDS.length - 1; step += 1) {
    if (errors.some((e) => STEP_FIELDS[step](e.field))) return step
  }
  return STEP_FIELDS.length - 1
}

export function draftToWpPayload(draft: OrganizerLaunchDraft): Record<string, unknown> {
  const rows = (draft.opportunity.feeRows ?? []).filter((row) => row.category?.trim())
  const first = rows[0]
  const eventTitle = draft.event.title?.trim() ?? ""

  const opportunities = rows.map((row) => {
    const category = row.category.trim()
    return {
      categories: [category],
      vendor_category: category,
      vendor_fee: row.vendorFee,
      spots_total: row.spotsTotal,
      opportunity_title: eventTitle ? `${category} — ${eventTitle}` : category,
    }
  })

  return {
    event_name: draft.event.title,
    event_date: draft.event.date,
    start_time: draft.event.startTime,
    end_time: draft.event.endTime,
    city: draft.event.city,
    state: draft.event.state,
    venue_name: draft.event.venueName,
    venue_address: draft.event.venueAddress,
    event_type: draft.event.eventType,
    expected_attendance: draft.event.expectedAttendance,
    description: draft.event.description,
    ticketing_url: draft.event.externalUrl,
    // Back-compat: first row at top level for older WP handlers.
    vendor_fee: first?.vendorFee,
    spots_total: first?.spotsTotal,
    categories: first ? [first.category.trim()] : undefined,
    opportunities,
    application_deadline: draft.opportunity.applicationDeadline,
    indoor: draft.opportunity.indoor,
    outdoor: draft.opportunity.outdoor,
    organizer_notes: draft.opportunity.applicationInstructions,
    opportunity_title: eventTitle ? `Vendors for ${eventTitle}` : undefined,
    document_mode: draft.opportunity.requiredDocumentsMode ?? "platform_defaults",
  }
}

export async function loadDraft(): Promise<OrganizerLaunchDraft | null> {
  try {
    const raw = await AsyncStorage.getItem(DRAFT_KEY)
    if (!raw) return null
    return normalizeLaunchDraft(JSON.parse(raw))
  } catch {
    return null
  }
}

export async function saveDraft(draft: OrganizerLaunchDraft): Promise<void> {
  await AsyncStorage.setItem(DRAFT_KEY, JSON.stringify({ ...draft, status: "draft" }))
}

export async function clearDraft(): Promise<void> {
  await AsyncStorage.removeItem(DRAFT_KEY)
}

function mockPublishResult(draft: OrganizerLaunchDraft): OrganizerLaunchPublishResult {
  return {
    status: "published",
    eventId: "101",
    opportunityId: "201",
    applicationUrl: "https://thelnkup.com/find-events/?opportunity=201",
    message: `Published ${draft.event.title}`,
  }
}

type WpPublishResponse = {
  event_id?: number | string
  opportunity_id?: number | string
  application_url?: string
  message?: string
  partial?: boolean
}

export async function publishLaunch(
  draft: OrganizerLaunchDraft,
  auth?: WpAuth,
  clientToken?: string,
): Promise<OrganizerLaunchPublishResult> {
  const validationErrors = validateLaunchDraft(draft)
  if (validationErrors.length > 0) {
    return {
      status: "validation_error",
      message: validationErrors[0]?.message ?? "Fix the highlighted fields.",
      validationErrors,
    }
  }

  if (!isLiveApiEnabled()) {
    await clearDraft()
    return mockPublishResult(draft)
  }

  if (!auth) {
    return { status: "auth_required", message: "Sign in as an organizer to continue." }
  }

  try {
    const data = await wpFetchJson<WpPublishResponse>("/organizer/launch/publish", {
      method: "POST",
      auth,
      body: { ...draftToWpPayload(draft), ...(clientToken ? { client_token: clientToken } : {}) },
    })
    invalidateOrganizerEvents()
    if (data.partial) {
      return {
        status: "partial",
        eventId: data.event_id != null ? String(data.event_id) : undefined,
        message: data.message ?? "Your event was saved, but vendor spots were not published yet.",
      }
    }
    await clearDraft()
    return {
      status: "published",
      eventId: data.event_id != null ? String(data.event_id) : undefined,
      opportunityId: data.opportunity_id != null ? String(data.opportunity_id) : undefined,
      applicationUrl:
        typeof data.application_url === "string" && data.application_url
          ? data.application_url
          : undefined,
      message: data.message ?? `Published ${draft.event.title}`,
    }
  } catch (err) {
    if (err instanceof WpFetchError) {
      if (err.status === 401) {
        return { status: "auth_required", message: err.message }
      }
      if (err.status === 403) {
        return { status: "forbidden", message: err.message }
      }
      return { status: "error", message: err.message }
    }
    return {
      status: "error",
      message: err instanceof Error ? err.message : "Could not publish event.",
    }
  }
}

/**
 * Save the event on WordPress without vendor slots (listing stays draft, invisible to vendors).
 * Retrying with the same token returns the same event.
 */
export async function saveServerDraft(
  draft: OrganizerLaunchDraft,
  auth: WpAuth | undefined,
  clientToken: string,
): Promise<{ eventId: string; message?: string }> {
  if (!draft.event.title?.trim()) throw new Error("Add an event name before saving a draft.")
  if (!isLiveApiEnabled()) return { eventId: "draft-demo", message: "Draft saved (demo)." }
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  const data = await wpFetchJson<{ event_id?: number | string; message?: string }>("/organizer/launch/publish", {
    method: "POST",
    auth,
    body: { ...draftToWpPayload(draft), opportunities: [], save_mode: "draft", client_token: clientToken },
  })
  invalidateOrganizerEvents()
  if (data.event_id == null) throw new Error("Draft could not be saved.")
  return { eventId: String(data.event_id), message: data.message }
}

/**
 * Publish a draft that already exists on WordPress: sync the event fields, then add its vendor
 * slots (idempotent via the token), which takes the listing live.
 */
export async function publishServerDraft(
  draft: OrganizerLaunchDraft,
  eventId: string,
  auth: WpAuth | undefined,
  clientToken: string,
): Promise<OrganizerLaunchPublishResult> {
  const validationErrors = validateLaunchDraft(draft)
  if (validationErrors.length > 0) {
    return { status: "validation_error", message: validationErrors[0]?.message, validationErrors }
  }
  if (!auth) return { status: "auth_required", message: "Sign in as an organizer to continue." }
  const payload = draftToWpPayload(draft)
  try {
    await wpFetchJson(`/organizer/events/${eventId}`, {
      method: "PATCH",
      auth,
      body: {
        event_name: payload.event_name,
        event_date: payload.event_date,
        start_time: payload.start_time ?? "",
        end_time: payload.end_time ?? "",
        city: payload.city,
        state: payload.state,
        venue_name: payload.venue_name ?? "",
        venue_address: payload.venue_address ?? "",
        description: payload.description ?? "",
      },
    })
    const data = await wpFetchJson<{ opportunities?: Array<{ id?: number; opportunity_id?: number }>; message?: string }>(
      `/organizer/events/${eventId}/opportunities`,
      {
        method: "POST",
        auth,
        body: {
          opportunities: payload.opportunities,
          application_deadline: payload.application_deadline,
          indoor: payload.indoor,
          outdoor: payload.outdoor,
          organizer_notes: payload.organizer_notes,
          document_mode: payload.document_mode,
          client_token: clientToken,
        },
      },
    )
    invalidateOrganizerEvents()
    await clearDraft()
    const first = data.opportunities?.[0]
    const oppId = first?.id ?? first?.opportunity_id
    return {
      status: "published",
      eventId,
      opportunityId: oppId != null ? String(oppId) : undefined,
      message: data.message ?? `Published ${draft.event.title}`,
    }
  } catch (err) {
    if (err instanceof WpFetchError) {
      if (err.status === 401) return { status: "auth_required", message: err.message }
      if (err.status === 403) return { status: "forbidden", message: err.message }
      return { status: "error", message: err.message }
    }
    return { status: "error", message: err instanceof Error ? err.message : "Could not publish event." }
  }
}

export { getOrganizerDashboardUrl }
