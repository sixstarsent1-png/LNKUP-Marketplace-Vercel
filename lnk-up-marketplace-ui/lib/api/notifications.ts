import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import type { SavedSearch } from "@/lib/api/saved-searches"
import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { filterPaymentRequired, filterBooked } from "@/lib/api/vendor-requests"
import { getOrganizerDashboardUrl, getPublicWpSiteUrl, getVendorDashboardPaymentsUrl } from "@/lib/api/config"
import { isEventDay, isEventWithinHours } from "@/lib/vendor/event-readiness"

export type LnkupNotificationType =
  | "application_submitted"
  | "application_under_review"
  | "application_approved"
  | "application_waitlisted"
  | "application_rejected"
  | "payment_required"
  | "payment_due_soon"
  | "payment_paid"
  | "packet_unlocked"
  | "event_day_reminder"
  | "document_missing"
  | "document_expiring"
  | "document_approved"
  | "document_rejected"
  | "saved_search_match"
  | "favorite_deadline"
  | "review_received"
  | "review_requested"
  | "organizer_new_vendor_request"
  | "organizer_payment_received"
  | "organizer_vendor_missing_documents"
  | "organizer_event_needs_attention"
  | "system"

export type LnkupNotification = {
  id: string
  type: LnkupNotificationType
  title: string
  message?: string
  createdAt?: string
  readAt?: string
  severity?: "info" | "success" | "warning" | "urgent"
  role?: "vendor" | "organizer" | "admin" | "unknown"
  href?: string
  actionLabel?: string
  eventId?: string
  opportunityId?: string
  requestId?: string
  paymentId?: string
  documentId?: string
  actionRequired?: boolean
  raw?: unknown
}

export type NotificationCount = {
  unread: number
  actionRequired?: number
  updatedAt?: string
}

export type NotificationChannel = "email" | "sms" | "in_app" | "push"

export type NotificationPreferenceItem = {
  id: string
  label: string
  description?: string
  channels: Record<NotificationChannel, boolean>
  lockedChannels?: NotificationChannel[]
  required?: boolean
}

export type NotificationPreferences = {
  userId?: string
  role: "vendor" | "organizer" | "unknown"
  items: NotificationPreferenceItem[]
  phoneVerified?: boolean
  emailVerified?: boolean
  pushAvailable?: boolean
  unavailable?: boolean
  handoffUrl?: string
  raw?: unknown
}

export type VendorRetentionCard = {
  id: string
  type:
    | "payment_required"
    | "event_day"
    | "packet_unlocked"
    | "application_update"
    | "document_reminder"
    | "saved_search_match"
    | "review_update"
    | "profile_completion"
    | "pwa_install"
    | "system"
  title: string
  description?: string
  href?: string
  actionLabel?: string
  severity?: "info" | "success" | "warning" | "urgent"
}

export type OrganizerRetentionCard = {
  id: string
  type:
    | "new_vendor_request"
    | "payment_required"
    | "event_operations"
    | "missing_documents"
    | "no_opportunities"
    | "market_demand"
    | "pwa_install"
    | "system"
  title: string
  description?: string
  href?: string
  actionLabel?: string
  severity?: "info" | "success" | "warning" | "urgent"
}

const ACTION_REQUIRED_TYPES = new Set<LnkupNotificationType>([
  "payment_required",
  "payment_due_soon",
  "document_missing",
  "document_expiring",
  "document_rejected",
  "review_requested",
  "organizer_new_vendor_request",
  "organizer_vendor_missing_documents",
  "organizer_event_needs_attention",
])

export function isActionRequiredNotification(n: LnkupNotification): boolean {
  return n.actionRequired === true || ACTION_REQUIRED_TYPES.has(n.type)
}

export function notificationTypeLabel(type: LnkupNotificationType): string {
  const labels: Record<LnkupNotificationType, string> = {
    application_submitted: "Application submitted",
    application_under_review: "Under review",
    application_approved: "Approved",
    application_waitlisted: "Waitlisted",
    application_rejected: "Not selected",
    payment_required: "Payment required",
    payment_due_soon: "Payment due soon",
    payment_paid: "Payment complete",
    packet_unlocked: "Pass ready",
    event_day_reminder: "Event day",
    document_missing: "Document needed",
    document_expiring: "Document expiring",
    document_approved: "Document approved",
    document_rejected: "Document rejected",
    saved_search_match: "New matches",
    favorite_deadline: "Deadline approaching",
    review_received: "Review received",
    review_requested: "Review pending",
    organizer_new_vendor_request: "New vendor request",
    organizer_payment_received: "Payment received",
    organizer_vendor_missing_documents: "Missing documents",
    organizer_event_needs_attention: "Event needs attention",
    system: "Update",
  }
  return labels[type] ?? "Notification"
}

const NOTIFICATION_SEVERITY: Partial<
  Record<LnkupNotificationType, NonNullable<LnkupNotification["severity"]>>
> = {
  payment_required: "urgent",
  payment_due_soon: "urgent",
  payment_paid: "success",
  packet_unlocked: "success",
  event_day_reminder: "warning",
  document_missing: "warning",
  document_expiring: "warning",
  document_rejected: "urgent",
  review_received: "success",
  organizer_new_vendor_request: "warning",
  organizer_vendor_missing_documents: "warning",
  organizer_event_needs_attention: "warning",
  organizer_payment_received: "success",
}

export function mapNotificationSeverity(
  type: LnkupNotificationType,
  fallback: NonNullable<LnkupNotification["severity"]> = "info",
): NonNullable<LnkupNotification["severity"]> {
  return NOTIFICATION_SEVERITY[type] ?? fallback
}

export type VendorNotificationInputs = {
  requests?: VendorRequest[]
  savedSearches?: SavedSearch[]
  readiness?: VendorReadiness | null
  reviewEligibility?: Array<{ applicationId: string; eventName?: string; status: string; reviewId?: string }>
  unreadBroadcasts?: number
  walletSummary?: {
    availableCredits?: number
    paidBookingsCount?: number
  } | null
  favoritesCount?: number
}

export function composeVendorNotifications(input: VendorNotificationInputs): LnkupNotification[] {
  const items: LnkupNotification[] = []
  const requests = input.requests ?? []

  for (const r of requests) {
    if (r.status === "submitted" || r.status === "under_review") {
      items.push({
        id: `app-review-${r.id}`,
        type: "application_under_review",
        title: `${r.eventName} is under review`,
        message: "We'll notify you when the organizer responds.",
        severity: mapNotificationSeverity("application_under_review"),
        role: "vendor",
        href: `/vendor/requests`,
        actionLabel: "View request",
        requestId: r.id,
        eventId: r.eventId,
        createdAt: r.submittedAt,
      })
    }
    if (r.status === "approved" || r.status === "payment_required") {
      items.push({
        id: `pay-req-${r.id}`,
        type: "payment_required",
        title: `Payment required for ${r.eventName}`,
        message: r.paymentDueAt ? `Due ${r.paymentDueAt}` : "Complete payment to secure your spot.",
        severity: mapNotificationSeverity("payment_required"),
        role: "vendor",
        href: `/vendor/requests?pay=${r.id}`,
        actionLabel: "Continue payment",
        requestId: r.id,
        paymentId: r.paymentId,
        actionRequired: true,
        createdAt: r.approvedAt ?? r.submittedAt,
      })
    }
    if (r.status === "paid" || r.status === "completed") {
      items.push({
        id: `pay-paid-${r.id}`,
        type: "payment_paid",
        title: `Booked for ${r.eventName}`,
        message: "Your event pass is ready.",
        severity: mapNotificationSeverity("payment_paid"),
        role: "vendor",
        href: `/vendor/pass/${r.id}`,
        actionLabel: "View pass",
        requestId: r.id,
        createdAt: r.submittedAt,
      })
      items.push({
        id: `packet-${r.id}`,
        type: "packet_unlocked",
        title: `Your pass is ready — ${r.eventName}`,
        message: "Load-in details and check-in info are available.",
        severity: mapNotificationSeverity("packet_unlocked"),
        role: "vendor",
        href: `/vendor/pass/${r.id}`,
        actionLabel: "Open pass",
        requestId: r.id,
      })
    }
    if (r.status === "waitlisted") {
      items.push({
        id: `waitlist-${r.id}`,
        type: "application_waitlisted",
        title: `Waitlisted for ${r.eventName}`,
        severity: mapNotificationSeverity("application_waitlisted"),
        role: "vendor",
        href: `/vendor/requests`,
        requestId: r.id,
      })
    }
    if (r.status === "rejected") {
      items.push({
        id: `rejected-${r.id}`,
        type: "application_rejected",
        title: `Not selected for ${r.eventName}`,
        severity: mapNotificationSeverity("application_rejected"),
        role: "vendor",
        href: `/vendor/requests`,
        requestId: r.id,
      })
    }
    if (
      (r.status === "paid" || r.status === "completed") &&
      (isEventDay(r.eventDate) || isEventWithinHours(r.eventDate, 72))
    ) {
      items.push({
        id: `event-day-${r.id}`,
        type: "event_day_reminder",
        title: `Event day — ${r.eventName}`,
        message: "Review load-in, parking, and check-in on your pass.",
        severity: mapNotificationSeverity("event_day_reminder"),
        role: "vendor",
        href: `/vendor/pass/${r.id}`,
        actionLabel: "View pass",
        requestId: r.id,
        actionRequired: true,
      })
    }
  }

  for (const search of input.savedSearches ?? []) {
    if ((search.newMatches ?? 0) > 0) {
      items.push({
        id: `saved-search-${search.id}`,
        type: "saved_search_match",
        title: `New opportunities match "${search.name}"`,
        message: `${search.newMatches} new match${search.newMatches === 1 ? "" : "es"}`,
        severity: mapNotificationSeverity("saved_search_match"),
        role: "vendor",
        href: `/vendor/find?saved=${search.id}`,
        actionLabel: "View matches",
        actionRequired: false,
      })
    }
  }

  for (const item of input.readiness?.missingItems ?? []) {
    const isExpiring = item.documentUrgency === "expiring_soon"
    const isExpired = item.documentUrgency === "expired"
    items.push({
      id: `doc-${item.id}`,
      type: isExpiring || isExpired ? "document_expiring" : "document_missing",
      title: item.label,
      message: item.description,
      severity: isExpired
        ? "urgent"
        : item.severity === "required"
          ? mapNotificationSeverity("document_missing", "urgent")
          : mapNotificationSeverity(isExpiring ? "document_expiring" : "document_missing", "warning"),
      role: "vendor",
      href: item.actionHref ?? "/vendor/documents",
      actionLabel: item.actionLabel ?? "Upload",
      documentId: item.id,
      actionRequired: item.severity === "required",
    })
  }

  for (const item of input.reviewEligibility ?? []) {
    if (item.status === "review_received") {
      items.push({
        id: `review-${item.applicationId}`,
        type: "review_received",
        title: `Review received for ${item.eventName ?? "your event"}`,
        severity: mapNotificationSeverity("review_received"),
        role: "vendor",
        href: "/vendor/reviews",
        actionLabel: "View review",
        requestId: item.applicationId,
      })
    }
    if (item.status === "awaiting_organizer_review" || item.status === "vendor_feedback_available") {
      items.push({
        id: `review-pending-${item.applicationId}`,
        type: "review_requested",
        title: `Review pending for ${item.eventName ?? "your event"}`,
        severity: mapNotificationSeverity("review_requested"),
        role: "vendor",
        href: "/vendor/reviews",
        requestId: item.applicationId,
      })
    }
  }

  if ((input.unreadBroadcasts ?? 0) > 0) {
    items.push({
      id: "ops-broadcasts",
      type: "system",
      title: `${input.unreadBroadcasts} event update${input.unreadBroadcasts === 1 ? "" : "s"}`,
      message: "Check operations for organizer messages.",
      severity: "info",
      role: "vendor",
      href: "/vendor/operations",
      actionLabel: "View operations",
    })
  }

  return items.sort((a, b) => (b.createdAt ?? "").localeCompare(a.createdAt ?? ""))
}

export type OrganizerNotificationInputs = {
  homeSummary?: OrganizerHomeSummary | null
  pendingRequests?: OrganizerVendorRequest[]
  paymentRequiredCount?: number
}

export function composeOrganizerNotifications(input: OrganizerNotificationInputs): LnkupNotification[] {
  const items: LnkupNotification[] = []

  for (const req of input.pendingRequests ?? []) {
    if (req.status === "under_review" || req.status === "submitted") {
      items.push({
        id: `org-req-${req.id}`,
        type: "organizer_new_vendor_request",
        title: `New request from ${req.vendorBusinessName ?? "a vendor"}`,
        message: req.eventName ? `${req.eventName}` : undefined,
        severity: mapNotificationSeverity("organizer_new_vendor_request"),
        role: "organizer",
        href: `/organizer/vendors?request=${req.id}`,
        actionLabel: "Review",
        requestId: req.id,
        eventId: req.eventId,
        actionRequired: true,
      })
    }
  }

  const paymentRequired = input.paymentRequiredCount ?? input.homeSummary?.stats.paymentRequired ?? 0
  if (paymentRequired > 0) {
    items.push({
      id: "org-payment-required",
      type: "organizer_event_needs_attention",
      title:
        paymentRequired === 1
          ? "1 vendor needs to pay"
          : `${paymentRequired} vendors need to pay`,
      message: "Follow up on outstanding vendor payments.",
      severity: mapNotificationSeverity("organizer_event_needs_attention", "urgent"),
      role: "organizer",
      href: "/organizer/money",
      actionLabel: "View money",
      actionRequired: true,
    })
  }

  for (const item of input.homeSummary?.attentionItems ?? []) {
    items.push({
      id: `org-attn-${item.id}`,
      type: "organizer_event_needs_attention",
      title: item.label,
      message: item.description,
      severity: (item.severity as LnkupNotification["severity"]) ?? mapNotificationSeverity("organizer_event_needs_attention"),
      role: "organizer",
      href: item.href ?? "/organizer",
      actionLabel: "Review",
      actionRequired: item.severity === "urgent" || item.severity === "warning",
    })
  }

  const paid = input.homeSummary?.stats.paidVendors ?? 0
  if (paid > 0) {
    items.push({
      id: "org-payment-received",
      type: "organizer_payment_received",
      title: `${paid} vendor${paid === 1 ? "" : "s"} paid and booked`,
      severity: mapNotificationSeverity("organizer_payment_received"),
      role: "organizer",
      href: "/organizer/money",
      actionLabel: "View finances",
    })
  }

  return items
}

export function composeVendorRetentionCards(input: VendorNotificationInputs): VendorRetentionCard[] {
  const cards: VendorRetentionCard[] = []
  const paymentRequired = filterPaymentRequired(input.requests ?? [])
  const booked = filterBooked(input.requests ?? [])

  if (paymentRequired.length > 0) {
    const first = paymentRequired[0]
    cards.push({
      id: "ret-pay",
      type: "payment_required",
      title:
        paymentRequired.length === 1
          ? `Payment required for ${first.eventName}`
          : `${paymentRequired.length} events need payment`,
      description: "Your packet unlocks after payment.",
      href: `/vendor/requests?pay=${first.id}`,
      actionLabel: "Continue payment",
      severity: "urgent",
    })
  }

  const eventDay = booked.find(
    (r) => isEventDay(r.eventDate) || isEventWithinHours(r.eventDate, 72),
  )
  if (eventDay) {
    cards.push({
      id: "ret-event-day",
      type: "event_day",
      title: `Event day — ${eventDay.eventName}`,
      description: "Review load-in and check-in on your pass.",
      href: `/vendor/pass/${eventDay.id}`,
      actionLabel: "View pass",
      severity: "warning",
    })
  }

  const unlocked = booked[0]
  if (unlocked && !eventDay) {
    cards.push({
      id: "ret-packet",
      type: "packet_unlocked",
      title: `Your pass is ready — ${unlocked.eventName}`,
      href: `/vendor/pass/${unlocked.id}`,
      actionLabel: "Open pass",
      severity: "success",
    })
  }

  const docItem = input.readiness?.missingItems.find((m) => m.severity === "required")
  if (docItem) {
    cards.push({
      id: "ret-doc",
      type: "document_reminder",
      title: docItem.label,
      description: docItem.description,
      href: docItem.actionHref ?? "/vendor/documents",
      actionLabel: "Upload",
      severity: "warning",
    })
  }

  const searchMatch = (input.savedSearches ?? []).find((s) => (s.newMatches ?? 0) > 0)
  if (searchMatch) {
    cards.push({
      id: "ret-search",
      type: "saved_search_match",
      title: `New matches for "${searchMatch.name}"`,
      href: `/vendor/find?saved=${searchMatch.id}`,
      actionLabel: "View matches",
      severity: "info",
    })
  }

  const review = (input.reviewEligibility ?? []).find((r) => r.status === "review_received")
  if (review) {
    cards.push({
      id: "ret-review",
      type: "review_update",
      title: `Review received for ${review.eventName ?? "your event"}`,
      href: "/vendor/reviews",
      actionLabel: "View review",
      severity: "success",
    })
  }

  if ((input.readiness?.profileCompletion ?? 100) < 80) {
    cards.push({
      id: "ret-profile",
      type: "profile_completion",
      title: "Complete your vendor profile",
      description: "Organizers approve faster with a complete profile.",
      href: input.readiness?.nextAction?.href ?? "/vendor/profile",
      actionLabel: "Complete profile",
      severity: "info",
    })
  }

  if ((input.walletSummary?.availableCredits ?? 0) > 0) {
    cards.push({
      id: "ret-credits",
      type: "system",
      title: "Credits available",
      description: "Use referral credits on your next booking payment.",
      href: "/vendor/wallet",
      actionLabel: "View credits",
      severity: "info",
    })
  }

  if ((input.walletSummary?.paidBookingsCount ?? 0) > 0 && paymentRequired.length === 0) {
    cards.push({
      id: "ret-receipt",
      type: "system",
      title: "View your receipt",
      description: "Your latest booking payment is on file.",
      href: "/vendor/wallet",
      actionLabel: "View receipt",
      severity: "info",
    })
  }

  if ((input.favoritesCount ?? 0) > 0) {
    cards.push({
      id: "ret-favorites",
      type: "saved_search_match",
      title: `${input.favoritesCount} saved opportunit${input.favoritesCount === 1 ? "y" : "ies"}`,
      description: "Review saved events when you're ready to apply.",
      href: "/vendor/favorites",
      actionLabel: "View saved",
      severity: "info",
    })
  }

  return cards
}

export function composeOrganizerRetentionCards(input: OrganizerNotificationInputs): OrganizerRetentionCard[] {
  const cards: OrganizerRetentionCard[] = []
  const pending = (input.pendingRequests ?? []).filter(
    (r) => r.status === "under_review" || r.status === "submitted",
  )

  if (pending.length > 0) {
    cards.push({
      id: "org-ret-req",
      type: "new_vendor_request",
      title:
        pending.length === 1
          ? `New request from ${pending[0].vendorBusinessName ?? "a vendor"}`
          : `${pending.length} vendor requests waiting`,
      href: "/organizer/vendors",
      actionLabel: "Review requests",
      severity: "urgent",
    })
  }

  const paymentRequired = input.paymentRequiredCount ?? input.homeSummary?.stats.paymentRequired ?? 0
  if (paymentRequired > 0) {
    cards.push({
      id: "org-ret-pay",
      type: "payment_required",
      title:
        paymentRequired === 1
          ? "1 vendor needs to pay"
          : `${paymentRequired} vendors need to pay`,
      href: "/organizer/money",
      actionLabel: "View money",
      severity: "urgent",
    })
  }

  for (const item of input.homeSummary?.attentionItems ?? []) {
    cards.push({
      id: `org-ret-${item.id}`,
      type: "event_operations",
      title: item.label,
      description: item.description,
      href: item.href ?? "/organizer",
      actionLabel: "Review",
      severity: (item.severity as OrganizerRetentionCard["severity"]) ?? "warning",
    })
  }

  if ((input.homeSummary?.stats.openOpportunities ?? 0) === 0 && (input.homeSummary?.stats.events ?? 0) > 0) {
    cards.push({
      id: "org-ret-opp",
      type: "no_opportunities",
      title: "Add a vendor opportunity",
      description: "Vendors can't apply until an opportunity is live.",
      href: "/organizer/launch",
      actionLabel: "Create opportunity",
      severity: "info",
    })
  }

  return cards
}

export function countNotifications(notifications: LnkupNotification[]): NotificationCount {
  const unread = notifications.filter((n) => !n.readAt).length
  const actionRequired = notifications.filter((n) => !n.readAt && isActionRequiredNotification(n)).length
  return { unread, actionRequired, updatedAt: new Date().toISOString() }
}

export const mockVendorNotifications: LnkupNotification[] = [
  {
    id: "mock-pay-1",
    type: "payment_required",
    title: "Payment required for Atlanta Food Fest",
    message: "Due Aug 1, 2026",
    severity: "urgent",
    role: "vendor",
    href: "/vendor/requests?pay=req-2",
    actionLabel: "Continue payment",
    requestId: "req-2",
    actionRequired: true,
    createdAt: "Jul 2026",
  },
  {
    id: "mock-packet-1",
    type: "packet_unlocked",
    title: "Your pass is ready — Charlotte Night Market",
    severity: "success",
    role: "vendor",
    href: "/vendor/pass/req-1",
    actionLabel: "Open pass",
    requestId: "req-1",
    createdAt: "Jun 2026",
  },
  {
    id: "mock-search-1",
    type: "saved_search_match",
    title: 'New opportunities match "Charlotte food events"',
    message: "1 new match",
    severity: "info",
    role: "vendor",
    href: "/vendor/find",
    actionLabel: "View matches",
    createdAt: "Jun 2026",
  },
  {
    id: "mock-doc-1",
    type: "document_expiring",
    title: "Insurance certificate expiring soon",
    severity: "warning",
    role: "vendor",
    href: "/vendor/documents",
    actionLabel: "Upload",
    actionRequired: true,
    createdAt: "Jun 2026",
  },
  {
    id: "mock-review-1",
    type: "review_received",
    title: "Review received for Charlotte Night Market",
    severity: "success",
    role: "vendor",
    href: "/vendor/reviews",
    actionLabel: "View review",
    createdAt: "Jun 2026",
  },
]

export const mockOrganizerNotifications: LnkupNotification[] = [
  {
    id: "mock-org-req-1",
    type: "organizer_new_vendor_request",
    title: "New request from Blue Ridge BBQ Co.",
    message: "Charlotte Night Market",
    severity: "warning",
    role: "organizer",
    href: "/organizer/vendors",
    actionLabel: "Review",
    actionRequired: true,
    createdAt: "Jul 2026",
  },
  {
    id: "mock-org-pay-1",
    type: "organizer_event_needs_attention",
    title: "2 vendors need to pay",
    severity: "urgent",
    role: "organizer",
    href: "/organizer/money",
    actionLabel: "View money",
    actionRequired: true,
    createdAt: "Jul 2026",
  },
]

export const mockNotificationPreferences: NotificationPreferences = {
  role: "vendor",
  pushAvailable: false,
  emailVerified: true,
  items: [
    {
      id: "application_updates",
      label: "Application updates",
      description: "When organizers review your applications",
      channels: { email: true, sms: false, in_app: true, push: false },
      lockedChannels: ["in_app"],
      required: true,
    },
    {
      id: "payment_reminders",
      label: "Payment reminders",
      description: "When payment is required after approval",
      channels: { email: true, sms: false, in_app: true, push: false },
    },
    {
      id: "saved_search_alerts",
      label: "Saved search alerts",
      description: "When new opportunities match your searches",
      channels: { email: true, sms: false, in_app: true, push: false },
    },
    {
      id: "document_reminders",
      label: "Document reminders",
      channels: { email: true, sms: false, in_app: true, push: false },
    },
    {
      id: "growth_tips",
      label: "Growth tips",
      channels: { email: false, sms: false, in_app: false, push: false },
    },
  ],
}

export function getWordPressSettingsHandoffUrl(role: "vendor" | "organizer"): string {
  const base = getPublicWpSiteUrl()
  if (!base) return ""
  if (role === "organizer") {
    const dash = getOrganizerDashboardUrl()
    return dash ? `${dash}?view=settings` : `${base}/organizer-dashboard/`
  }
  return `${base}/vendor-dashboard/?view=profile`
}

export function getWordPressNotificationHandoffUrl(): string {
  return getWordPressSettingsHandoffUrl("vendor")
}
