import { isLiveApiEnabled } from "@/lib/api/config"
import { invalidateOrganizerHome } from "@/lib/api/organizer-home"
import {
  cachedRequest,
  invalidateCache,
  subscribeCache,
  type CachedRequestOptions,
} from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { WpFetchError } from "@/lib/api/errors"
import { MOCK_ORGANIZER_REQUESTS } from "@/lib/data/fixtures"
import { formatShortDate } from "@/lib/format/date"
import {
  applicationStatusLabel,
  applicationStatusTone,
  type ApplicationStatus,
} from "@/lib/status/application-status"
import type { StatusTone } from "@/lib/theme"

export type OrganizerVendorRequestStatus = ApplicationStatus

export type OrganizerVendorRequest = {
  id: string
  vendorBusinessName: string
  vendorCategory?: string
  vendorProfileId?: string
  eventId?: string
  eventName: string
  opportunityName?: string
  /** Display-formatted date */
  submittedAt?: string
  /** ISO/raw timestamp for sorting */
  submittedAtIso?: string
  status: OrganizerVendorRequestStatus
  amountDue?: number
  currency?: string
  message?: string
}

export type OrganizerReviewAction = "approve" | "waitlist" | "reject"

const KNOWN: OrganizerVendorRequestStatus[] = [
  "submitted",
  "under_review",
  "info_requested",
  "approved",
  "payment_required",
  "paid",
  "waitlisted",
  "rejected",
  "cancelled",
  "completed",
]

export function normalizeOrganizerRequestStatus(value?: string): OrganizerVendorRequestStatus {
  const s = (value ?? "").trim().toLowerCase().replace(/-/g, "_")
  if (KNOWN.includes(s as OrganizerVendorRequestStatus)) {
    return s as OrganizerVendorRequestStatus
  }
  return "unknown"
}

export function organizerRequestStatusLabel(status: OrganizerVendorRequestStatus): string {
  return applicationStatusLabel(status, "organizer")
}

export function organizerRequestStatusTone(status: OrganizerVendorRequestStatus): StatusTone {
  return applicationStatusTone(status, "organizer")
}

const formatDate = formatShortDate

type WpOrganizerApplicationItem = {
  application_id?: number
  event_id?: number
  event_name?: string
  opportunity_name?: string
  vendor_business_name?: string
  vendor_profile_id?: number
  category?: string
  status?: string
  submitted_at?: string
  amount_due?: number
  currency?: string
  message?: string
}

export function mapWpOrganizerApplication(item: WpOrganizerApplicationItem): OrganizerVendorRequest {
  return {
    id: String(item.application_id ?? ""),
    vendorBusinessName: item.vendor_business_name || "Vendor",
    vendorCategory: item.category,
    vendorProfileId: item.vendor_profile_id ? String(item.vendor_profile_id) : undefined,
    eventId: item.event_id ? String(item.event_id) : undefined,
    eventName: item.event_name || "Event",
    opportunityName: item.opportunity_name,
    submittedAt: formatDate(item.submitted_at) ?? item.submitted_at,
    submittedAtIso: item.submitted_at,
    status: normalizeOrganizerRequestStatus(item.status),
    amountDue: item.amount_due,
    currency: item.currency ?? "USD",
    message: item.message,
  }
}

export function mapOrganizerRequestList(data: unknown): OrganizerVendorRequest[] {
  const d = (data && typeof data === "object" ? data : {}) as { items?: WpOrganizerApplicationItem[] }
  const items = Array.isArray(data) ? (data as WpOrganizerApplicationItem[]) : d.items ?? []
  return items.map(mapWpOrganizerApplication).filter((item) => item.id)
}

export type OrganizerRequestsResult = {
  items: OrganizerVendorRequest[]
  total: number
}

const REQUESTS_CACHE_PREFIX = "organizer.requests:"

export function organizerRequestsCacheKey(auth?: WpAuth): string {
  return `${REQUESTS_CACHE_PREFIX}${auth?.username ?? "anon"}`
}

/** Vendor requests. Shared by Home, Vendors, event detail, notifications and the tab badge. */
export async function fetchOrganizerRequests(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<OrganizerRequestsResult> {
  if (!isLiveApiEnabled()) {
    return { items: MOCK_ORGANIZER_REQUESTS, total: MOCK_ORGANIZER_REQUESTS.length }
  }

  if (!auth) {
    return { items: [], total: 0 }
  }

  return cachedRequest(
    organizerRequestsCacheKey(auth),
    async () => {
      const data = await wpFetchJson<{ items?: WpOrganizerApplicationItem[]; total?: number }>(
        "/applications/organizer",
        { auth },
      )
      const items = mapOrganizerRequestList(data)
      return { items, total: data.total ?? items.length }
    },
    options,
  )
}

export function invalidateOrganizerRequests(): void {
  invalidateCache(REQUESTS_CACHE_PREFIX)
  invalidateOrganizerHome()
}

export function subscribeOrganizerRequests(
  auth: WpAuth | undefined,
  listener: (result: OrganizerRequestsResult) => void,
): () => void {
  return subscribeCache<OrganizerRequestsResult>(organizerRequestsCacheKey(auth), listener)
}

/**
 * Whether the server has the review workspace (`/applications/{id}/decision`): unknown until the
 * first decision, then remembered for the session so a site without it costs one extra call.
 */
let reviewWorkspaceAvailable: boolean | undefined

export async function reviewOrganizerRequest(
  request: OrganizerVendorRequest,
  action: OrganizerReviewAction,
  auth?: WpAuth,
  reason?: string,
): Promise<{ success: boolean }> {
  if (!isLiveApiEnabled()) {
    return { success: true }
  }

  if (!auth) {
    throw new Error("Sign in to review requests.")
  }

  if (!request.eventId) {
    throw new Error("Review actions are available in the LNKup web dashboard.")
  }

  if (action === "reject" && !reason?.trim()) {
    throw new Error("Please provide a reason when rejecting.")
  }

  if (reviewWorkspaceAvailable !== false) {
    try {
      await wpFetchJson(`/applications/${encodeURIComponent(request.id)}/decision`, {
        method: "POST",
        auth,
        body: { action, rejection_reason: reason ?? "" },
      })
      reviewWorkspaceAvailable = true
      invalidateOrganizerRequests()
      invalidateCache("organizer.events:")
      return { success: true }
    } catch (error) {
      if (!(error instanceof WpFetchError) || error.code !== "lnkup_feature_off") throw error
      reviewWorkspaceAvailable = false
    }
  }

  const payload: Record<string, unknown> = {
    application_ids: [Number(request.id)],
    action,
  }
  if (reason) {
    payload.rejection_reason = reason
  }

  const result = await wpFetchJson<{ failed?: { message?: string; reason?: string }[] }>(
    `/crm/events/${request.eventId}/roster/bulk`,
    {
      method: "POST",
      auth,
      body: payload,
    },
  )
  invalidateOrganizerRequests()
  invalidateCache("organizer.events:")
  const failure = result?.failed?.[0]
  if (failure) {
    throw new Error(failure.message || "This request could not be updated.")
  }
  return { success: true }
}

/**
 * Ask the vendor for more information. The server moves the request to info_requested;
 * callers re-read the list for the new status.
 */
export async function requestMoreInfo(
  request: OrganizerVendorRequest,
  message: string,
  auth?: WpAuth,
  documents: string[] = [],
): Promise<void> {
  if (!isLiveApiEnabled()) return
  if (!auth) {
    throw new Error("Sign in to message vendors.")
  }
  const trimmed = message.trim()
  if (trimmed.length < 3) {
    throw new Error("Tell the vendor what you need.")
  }
  await wpFetchJson(`/applications/${encodeURIComponent(request.id)}/request-info`, {
    method: "POST",
    auth,
    body: { message: trimmed, documents },
  })
  invalidateOrganizerRequests()
}

export type ApplicationIntel = {
  applicationId: string
  message?: string
  status?: string
  readinessLabel?: string
  paymentLikelihood?: string | number | null
  noShowLikelihood?: string | number | null
  businessName?: string
}

export async function fetchApplicationIntel(
  applicationId: string,
  auth?: WpAuth,
): Promise<ApplicationIntel | null> {
  if (!applicationId) return null
  if (!isLiveApiEnabled()) {
    return {
      applicationId,
      message: "Mock application note.",
      readinessLabel: "Ready",
      paymentLikelihood: "High",
      noShowLikelihood: "Low",
    }
  }
  if (!auth) return null

  const data = await wpFetchJson<Record<string, unknown>>(
    `/crm/applications/${applicationId}/intel`,
    { auth },
  )
  const profile =
    data.profile && typeof data.profile === "object"
      ? (data.profile as Record<string, unknown>)
      : {}
  return {
    applicationId: String(data.application_id ?? applicationId),
    message: typeof data.message === "string" ? data.message : undefined,
    status: typeof data.status === "string" ? data.status : undefined,
    readinessLabel:
      typeof data.readiness_label === "string" ? data.readiness_label : undefined,
    paymentLikelihood: (data.payment_likelihood as string | number | null | undefined) ?? null,
    noShowLikelihood: (data.no_show_likelihood as string | number | null | undefined) ?? null,
    businessName:
      typeof profile.business_name === "string"
        ? profile.business_name
        : typeof profile.name === "string"
          ? profile.name
          : undefined,
  }
}
