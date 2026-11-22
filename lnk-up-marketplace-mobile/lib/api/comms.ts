import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  normalizeNotificationType,
  type InboxTab,
  type LnkupNotification,
  type NotificationFilter,
} from "@/lib/notifications/types"

/**
 * lnkup-communications REST client. Every call degrades to `null` / a typed
 * "unavailable" result when the plugin is not installed (404 rest_no_route),
 * so screens can fall back to the older composed feed.
 */

export type CommsRole = "vendor" | "organizer"

export function isCommsUnavailable(error: unknown): boolean {
  return error instanceof WpFetchError && (error.status === 404 || error.code === "rest_no_route")
}

type WpInboxItem = {
  id?: string
  type?: string
  title?: string
  message?: string
  createdAt?: string
  readAt?: string | null
  severity?: string
  role?: string
  href?: string
  actionRequired?: boolean
  requestId?: string
  tab?: string
  category?: string
  deepLink?: string
  applicationId?: string
  eventId?: string
  opportunityId?: string
  notificationId?: number
  priority?: string
}

const TABS = new Set<InboxTab>(["all", "applications", "payments", "events", "opportunities", "operations"])
const SEVERITIES = new Set(["info", "success", "warning", "urgent"])
const PRIORITIES = new Set(["critical", "high", "normal", "promotional"])

/** Map a server inbox row to the app's notification shape. */
export function normalizeInboxItem(raw: WpInboxItem): LnkupNotification | null {
  if (!raw || !raw.id || !raw.title) return null
  const role = raw.role === "organizer" || raw.role === "vendor" ? raw.role : undefined
  return {
    id: String(raw.id),
    type: normalizeNotificationType(raw.type),
    title: String(raw.title),
    message: raw.message ? String(raw.message) : undefined,
    createdAt: raw.createdAt || undefined,
    readAt: raw.readAt || undefined,
    severity: SEVERITIES.has(String(raw.severity)) ? (raw.severity as LnkupNotification["severity"]) : "info",
    role,
    href: raw.href || undefined,
    actionRequired: raw.actionRequired === true,
    requestId: raw.requestId || undefined,
    applicationId: raw.applicationId || raw.requestId || undefined,
    eventId: raw.eventId || undefined,
    opportunityId: raw.opportunityId || undefined,
    deepLink: raw.deepLink && raw.deepLink.startsWith("/") ? raw.deepLink : undefined,
    notificationId: typeof raw.notificationId === "number" && raw.notificationId > 0 ? raw.notificationId : undefined,
    tab: TABS.has(raw.tab as InboxTab) ? (raw.tab as InboxTab) : "all",
    category: raw.category || undefined,
    priority: PRIORITIES.has(String(raw.priority)) ? (raw.priority as LnkupNotification["priority"]) : "",
  }
}

export type InboxPage = {
  items: LnkupNotification[]
  page: number
  hasMore: boolean
  total: number
}

export async function fetchInboxPage(
  role: CommsRole,
  options: { tab: InboxTab; page: number; filter?: NotificationFilter; auth?: WpAuth },
): Promise<InboxPage> {
  const res = await wpFetchJson<{ items?: WpInboxItem[]; page?: number; hasMore?: boolean; total?: number }>(
    "/comms/inbox",
    {
      auth: options.auth,
      query: { role, tab: options.tab, page: options.page, per_page: 20, filter: options.filter ?? "all" },
    },
  )
  const items = (res.items ?? []).map(normalizeInboxItem).filter((n): n is LnkupNotification => n !== null)
  return { items, page: res.page ?? options.page, hasMore: res.hasMore === true, total: res.total ?? items.length }
}

export type InboxCount = { unread: number; actionRequired: number; byTab: Partial<Record<InboxTab, number>> }

export async function fetchInboxCount(role: CommsRole, auth?: WpAuth): Promise<InboxCount | null> {
  if (!isLiveApiEnabled() || !auth) return null
  try {
    const res = await wpFetchJson<InboxCount>("/comms/inbox/count", { auth, query: { role } })
    return { unread: res.unread ?? 0, actionRequired: res.actionRequired ?? 0, byTab: res.byTab ?? {} }
  } catch {
    return null
  }
}

export async function markTabRead(role: CommsRole, tab: InboxTab, auth?: WpAuth): Promise<void> {
  if (!auth || !isLiveApiEnabled()) return
  await wpFetchJson("/comms/inbox/read-all", { method: "POST", auth, body: { role, tab } })
}

/** Push tap / inbox open → server-side opened_at (only for the signed-in user's own messages). */
export async function trackNotificationOpened(notificationId: unknown, auth?: WpAuth): Promise<void> {
  const id = typeof notificationId === "string" ? Number.parseInt(notificationId, 10) : notificationId
  if (!auth || !isLiveApiEnabled() || typeof id !== "number" || !Number.isFinite(id) || id <= 0) return
  await wpFetchJson("/comms/opened", { method: "POST", auth, body: { notificationId: id } }).catch(() => undefined)
}

// ---------------------------------------------------------------- Preferences.

export type EmailMode = "immediate" | "daily" | "weekly" | "off"

export type PreferenceCategory = {
  key: string
  label: string
  description: string
  roles: CommsRole[]
  promotional: boolean
  locked: boolean
  email: EmailMode
  push: "on" | "off"
}

export type CommsPreferences = {
  categories: PreferenceCategory[]
  pushEnabled: boolean
  quietHours: { enabled: boolean; start: string; end: string }
  timezone: string
  emailModes: EmailMode[]
}

export type PreferencesUpdate = {
  categories?: Array<{ key: string; email?: EmailMode; push?: "on" | "off" }>
  pushEnabled?: boolean
  quietHours?: { enabled: boolean; start: string; end: string }
  timezone?: string
}

export async function fetchCommsPreferences(auth?: WpAuth): Promise<CommsPreferences | null> {
  try {
    return await wpFetchJson<CommsPreferences>("/comms/preferences", { auth })
  } catch (error) {
    if (isCommsUnavailable(error)) return null
    throw error
  }
}

export async function updateCommsPreferences(update: PreferencesUpdate, auth?: WpAuth): Promise<CommsPreferences> {
  return wpFetchJson<CommsPreferences>("/comms/preferences", { method: "PATCH", auth, body: update })
}

// ---------------------------------------------------------------- Next steps.

export type NextStep = {
  key: string
  title: string
  context: string
  priority: "high" | "normal"
  count: number
  deepLink: string
}

export type UpcomingItem = {
  eventId: number
  applicationId?: number
  title: string
  date: string
  daysAway: number | null
  passReady?: boolean
  pending?: number
  unpaid?: number
  confirmed?: number
  missingDocuments?: number
  deepLink: string
}

export async function fetchNextSteps(
  role: CommsRole,
  auth?: WpAuth,
): Promise<{ steps: NextStep[]; upcoming: UpcomingItem[] } | null> {
  if (!isLiveApiEnabled() || !auth) return null
  try {
    const res = await wpFetchJson<{ steps?: NextStep[]; upcoming?: UpcomingItem[] }>("/comms/next-steps", {
      auth,
      query: { role },
    })
    return { steps: res.steps ?? [], upcoming: res.upcoming ?? [] }
  } catch {
    return null
  }
}

// ---------------------------------------------------------------- Organizer event communications.

export type EventGroup = { key: string; label: string; count: number }
export type EventTemplate = { key: string; label: string; priority: string; groups: string[]; title: string; body: string }
export type EventPreview = {
  recipients: number
  inbox: number
  email: number
  push: number
  excluded: Record<string, number>
  sample: string[]
  rateLimit: string
  allowed: boolean
}
export type EventHistoryItem = {
  id: number
  type: string
  title: string
  body: string
  group: string
  status: string
  recipients: number
  sent: number
  opened: number
  sentBy: string
  createdAt: string
}
export type EventSendInput = { group: string; template: string; title: string; body: string; channels: string[] }

export async function fetchEventAudiences(eventId: string, auth?: WpAuth) {
  return wpFetchJson<{ eventId: number; eventName: string; groups: EventGroup[] }>(
    `/comms/events/${encodeURIComponent(eventId)}/audiences`,
    { auth },
  )
}

export async function fetchEventTemplates(eventId: string, auth?: WpAuth) {
  const res = await wpFetchJson<{ templates?: EventTemplate[] }>(`/comms/events/${encodeURIComponent(eventId)}/templates`, { auth })
  return res.templates ?? []
}

export async function previewEventMessage(eventId: string, input: EventSendInput, auth?: WpAuth) {
  return wpFetchJson<EventPreview>(`/comms/events/${encodeURIComponent(eventId)}/preview`, { method: "POST", auth, body: input })
}

export async function sendEventMessage(eventId: string, input: EventSendInput, auth?: WpAuth) {
  return wpFetchJson<{ ok: boolean; id: number; recipients: number }>(`/comms/events/${encodeURIComponent(eventId)}/send`, {
    method: "POST",
    auth,
    body: input,
  })
}

export async function fetchEventHistory(eventId: string, auth?: WpAuth) {
  const res = await wpFetchJson<{ items?: EventHistoryItem[] }>(`/comms/events/${encodeURIComponent(eventId)}/history`, { auth })
  return res.items ?? []
}
