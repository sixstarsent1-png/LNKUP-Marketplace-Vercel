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
  }
}

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
    body: {
      opportunity_id: input.opportunityId,
      selected_category: input.selectedCategory ?? "",
      application_message: input.message ?? "",
      setup_notes: input.setupNotes ?? "",
      accepted_terms: input.acceptedTerms,
    },
  })
  invalidateVendorRequests()

  return {
    success: Boolean(data.success ?? data.application_id),
    applicationId: data.application_id ? String(data.application_id) : undefined,
    status: data.status,
    message: data.message ?? "Application submitted.",
    redirectUrl: data.redirect_url,
    warnings: data.warnings,
  }
}
