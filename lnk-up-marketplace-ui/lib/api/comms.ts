import { WpFetchError } from "@/lib/api/wp-fetch"
import type { LnkupNotification, LnkupNotificationType } from "@/lib/api/notifications"

/** lnkup-communications types shared by BFF routes and client components. */

export type CommsRole = "vendor" | "organizer"
export type EmailMode = "immediate" | "daily" | "weekly" | "off"
export type InboxTab = "all" | "applications" | "payments" | "events" | "opportunities" | "operations"

export type CommsPreferenceCategory = {
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
  categories: CommsPreferenceCategory[]
  pushEnabled: boolean
  quietHours: { enabled: boolean; start: string; end: string }
  timezone: string
  emailModes: EmailMode[]
  roles: CommsRole[]
}

export type CommsPreferencesUpdate = {
  categories?: Array<{ key: string; email?: EmailMode; push?: "on" | "off" }>
  pushEnabled?: boolean
  quietHours?: { enabled: boolean; start: string; end: string }
  timezone?: string
}

export type CommsInboxResponse = {
  available: boolean
  items: LnkupNotification[]
  page: number
  hasMore: boolean
  tabs: InboxTab[]
  role: CommsRole
}

/** True when WordPress does not have lnkup-communications installed. */
export function isCommsUnavailable(error: unknown): boolean {
  return error instanceof WpFetchError && (error.status === 404 || error.code === "rest_no_route")
}

export function inboxTabLabel(tab: InboxTab): string {
  switch (tab) {
    case "all":
      return "All"
    case "applications":
      return "Applications"
    case "payments":
      return "Payments"
    case "events":
      return "Events"
    case "opportunities":
      return "Opportunities"
    case "operations":
      return "Operations"
    default: {
      const exhaustive: never = tab
      return exhaustive
    }
  }
}

const WEB_TYPES = new Set<string>([
  "application_submitted",
  "application_under_review",
  "application_approved",
  "application_waitlisted",
  "application_rejected",
  "payment_required",
  "payment_due_soon",
  "payment_paid",
  "packet_unlocked",
  "event_day_reminder",
  "document_missing",
  "document_expiring",
  "document_approved",
  "document_rejected",
  "saved_search_match",
  "favorite_deadline",
  "review_received",
  "review_requested",
  "organizer_new_vendor_request",
  "organizer_payment_received",
  "organizer_vendor_missing_documents",
  "organizer_event_needs_attention",
  "system",
])

type WpCommsItem = {
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
  eventId?: string
  opportunityId?: string
  applicationId?: string
}

/** Server inbox row → web notification (href is already a website URL). */
export function mapCommsItem(raw: WpCommsItem): LnkupNotification | null {
  if (!raw?.id || !raw.title) return null
  const severity = ["info", "success", "warning", "urgent"].includes(String(raw.severity)) ? (raw.severity as LnkupNotification["severity"]) : "info"
  return {
    id: String(raw.id),
    type: (WEB_TYPES.has(String(raw.type)) ? raw.type : "system") as LnkupNotificationType,
    title: String(raw.title),
    message: raw.message || undefined,
    createdAt: raw.createdAt || undefined,
    readAt: raw.readAt || undefined,
    severity,
    role: raw.role === "organizer" || raw.role === "vendor" ? raw.role : "unknown",
    href: raw.href && /^https?:\/\//.test(raw.href) ? raw.href : undefined,
    eventId: raw.eventId || undefined,
    opportunityId: raw.opportunityId || undefined,
    requestId: raw.applicationId || undefined,
    actionRequired: raw.actionRequired === true,
  }
}
