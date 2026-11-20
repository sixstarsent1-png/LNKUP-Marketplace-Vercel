import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  composeOrganizerNotifications,
  composeVendorNotifications,
  type LnkupNotification,
  type OrganizerNotificationInputs,
  type VendorNotificationInputs,
} from "@/lib/api/notifications"
import { mapOrganizerHomeSummary } from "@/lib/api/organizer-home"
import { mapOrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { mapOrganizerRequestList } from "@/lib/api/organizer-requests"
import { mapSavedSearch } from "@/lib/api/saved-searches"
import { mapVendorReadiness } from "@/lib/api/vendor-readiness"
import { mapVendorRequestList } from "@/lib/api/vendor-requests"
import type { WpProfileStrength, WpVerificationVendor, WpVendorApplicationsResponse } from "@/lib/api/types"
import type { WpAuth } from "@/lib/api/wp-fetch"
import type { SessionUser } from "@/lib/auth/session"

export async function fetchVendorNotificationInputs(auth: WpAuth): Promise<VendorNotificationInputs> {
  const [applications, savedRaw, verification, strength, eligibility, opsHome] = await Promise.all([
    wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", { auth }).catch(() => ({ items: [] })),
    wpFetchJson<{ items?: unknown[] } | unknown[]>("/marketplace/saved-searches", { auth }).catch(() => ({ items: [] })),
    wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth }).catch(() => null),
    wpFetchJson<WpProfileStrength>("/growth/profile-strength", { auth }).catch(() => undefined),
    wpFetchJson<{ items?: Array<{ application_id?: string; event_name?: string; status?: string; review_id?: string }> }>(
      "/reputation/vendor-dashboard",
      { auth },
    ).catch(() => ({ items: [] })),
    wpFetchJson<{ unread_broadcasts?: number }>("/operations/vendor-home", { auth }).catch(() => null),
  ])

  const requests = mapVendorRequestList(applications)
  const savedList = Array.isArray(savedRaw) ? savedRaw : savedRaw?.items ?? []
  const savedSearches = savedList.map((item) => mapSavedSearch(item as never))

  let readiness = null
  if (verification) {
    readiness = mapVendorReadiness(verification, strength)
  }

  const dash = eligibility as { events?: Array<{ application_id?: number; event_name?: string; review_status?: string }> }
  const reviewEligibility = (dash.events ?? [])
    .filter((e) => e.review_status && e.review_status !== "not_eligible")
    .map((e) => ({
      applicationId: String(e.application_id ?? ""),
      eventName: e.event_name,
      status: e.review_status ?? "unknown",
    }))

  return {
    requests,
    savedSearches,
    readiness,
    reviewEligibility,
    unreadBroadcasts: opsHome?.unread_broadcasts ?? 0,
  }
}

export async function fetchOrganizerNotificationInputs(auth: WpAuth): Promise<OrganizerNotificationInputs> {
  const [intel, widgets, checklist, financial, requestsRaw] = await Promise.all([
    wpFetchJson<Record<string, unknown>>("/intelligence/organizer/summary", { auth }).catch(() => ({})),
    wpFetchJson<Record<string, unknown>>("/crm/dashboard/widgets", { auth }).catch(() => ({})),
    wpFetchJson<unknown>("/crm/launch-checklist", { auth }).catch(() => []),
    wpFetchJson<unknown>("/financial/organizer-balances", { auth }).catch(() => null),
    wpFetchJson<{ items?: unknown[] }>("/applications/organizer", { auth }).catch(() => ({ items: [] })),
  ])

  const summary = mapOrganizerHomeSummary(intel, widgets, checklist as never, financial)
  if (financial) {
    summary.financial = mapOrganizerFinancialSnapshot(financial)
  }

  const pendingRequests = mapOrganizerRequestList(requestsRaw).filter(
    (r) => r.status === "under_review" || r.status === "submitted",
  )

  return {
    homeSummary: summary,
    pendingRequests,
    paymentRequiredCount: summary.stats.paymentRequired,
  }
}

type WpInboxItem = LnkupNotification & { id: string }

export async function fetchWpInbox(auth: WpAuth): Promise<{
  items: LnkupNotification[]
  readIds: Set<string>
}> {
  const payload = await wpFetchJson<{ items?: WpInboxItem[]; readIds?: string[] }>(
    "/notifications/inbox",
    { auth },
  ).catch(() => ({ items: [], readIds: [] }))

  return {
    items: Array.isArray(payload.items) ? payload.items : [],
    readIds: new Set(payload.readIds ?? []),
  }
}

export function mergeInboxState(
  composed: LnkupNotification[],
  inbox: { items: LnkupNotification[]; readIds: Set<string> },
): LnkupNotification[] {
  const byId = new Map<string, LnkupNotification>()
  for (const item of inbox.items) {
    if (item.id) byId.set(item.id, item)
  }
  for (const item of composed) {
    const existing = byId.get(item.id)
    byId.set(item.id, {
      ...item,
      ...existing,
      title: item.title || existing?.title || "",
      readAt: existing?.readAt || (inbox.readIds.has(item.id) ? "read" : item.readAt),
    })
  }
  return Array.from(byId.values()).sort((a, b) => (b.createdAt ?? "").localeCompare(a.createdAt ?? ""))
}

export async function composeNotificationsForSession(session: SessionUser): Promise<LnkupNotification[]> {
  const auth = { username: session.username, appPassword: session.appPassword }
  const isVendor = session.canAccessVendor ?? session.roles?.includes("vendor")
  const isOrganizer = session.canAccessOrganizer ?? session.roles?.includes("organizer")

  const items: LnkupNotification[] = []

  if (isVendor) {
    const input = await fetchVendorNotificationInputs(auth)
    items.push(...composeVendorNotifications(input))
  }

  if (isOrganizer) {
    const input = await fetchOrganizerNotificationInputs(auth)
    items.push(...composeOrganizerNotifications(input))
  }

  const inbox = await fetchWpInbox(auth)
  return mergeInboxState(items, inbox)
}
