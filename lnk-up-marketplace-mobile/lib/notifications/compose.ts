import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import { isPaymentDueRequest, type VendorRequest } from "@/lib/api/vendor-requests"
import type { VendorReviewsSnapshot } from "@/lib/api/vendor-reviews"
import {
  isActionRequiredNotification,
  type LnkupNotification,
  type LnkupNotificationType,
} from "@/lib/notifications/types"

const SEVERITY: Partial<Record<LnkupNotificationType, NonNullable<LnkupNotification["severity"]>>> = {
  payment_required: "urgent",
  payment_paid: "success",
  packet_unlocked: "success",
  event_day_reminder: "warning",
  document_missing: "warning",
  document_expiring: "warning",
  review_received: "success",
  review_requested: "warning",
  organizer_new_vendor_request: "warning",
  organizer_event_needs_attention: "urgent",
  organizer_payment_received: "success",
}

function severity(type: LnkupNotificationType): NonNullable<LnkupNotification["severity"]> {
  return SEVERITY[type] ?? "info"
}

function isWithinHours(eventDate: string | undefined, hours: number): boolean {
  if (!eventDate) return false
  const parsed = Date.parse(eventDate)
  if (Number.isNaN(parsed)) return false
  const diff = parsed - Date.now()
  return diff >= 0 && diff <= hours * 60 * 60 * 1000
}

export type VendorNotificationInputs = {
  requests?: VendorRequest[]
  readiness?: VendorReadiness | null
  reviews?: VendorReviewsSnapshot | null
  unreadBroadcasts?: number
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
        severity: severity("application_under_review"),
        role: "vendor",
        href: `/booking/${r.id}`,
        actionLabel: "View request",
        requestId: r.id,
        createdAt: r.submittedAt,
      })
    }
    if (isPaymentDueRequest(r)) {
      items.push({
        id: `pay-req-${r.id}`,
        type: "payment_required",
        title: `Payment due for ${r.eventName}`,
        message: "Complete payment to secure your spot.",
        severity: severity("payment_required"),
        role: "vendor",
        href: `/pass/${r.id}`,
        actionLabel: "Continue payment",
        requestId: r.id,
        actionRequired: true,
        createdAt: r.submittedAt,
      })
    }
    if (r.status === "paid" || r.status === "completed") {
      items.push({
        id: `pay-paid-${r.id}`,
        type: "payment_paid",
        title: `Confirmed for ${r.eventName}`,
        message: "Your event pass is ready.",
        severity: severity("payment_paid"),
        role: "vendor",
        href: `/pass/${r.id}`,
        actionLabel: "View pass",
        requestId: r.id,
        createdAt: r.submittedAt,
      })
      items.push({
        id: `packet-${r.id}`,
        type: "packet_unlocked",
        title: `Your pass is ready — ${r.eventName}`,
        message: "Load-in details and check-in info are available.",
        severity: severity("packet_unlocked"),
        role: "vendor",
        href: `/pass/${r.id}`,
        actionLabel: "Open pass",
        requestId: r.id,
      })
    }
    if (r.status === "waitlisted") {
      items.push({
        id: `waitlist-${r.id}`,
        type: "application_waitlisted",
        title: `Waitlisted for ${r.eventName}`,
        severity: severity("application_waitlisted"),
        role: "vendor",
        href: `/booking/${r.id}`,
        requestId: r.id,
      })
    }
    if (r.status === "rejected") {
      items.push({
        id: `rejected-${r.id}`,
        type: "application_rejected",
        title: `Declined for ${r.eventName}`,
        severity: severity("application_rejected"),
        role: "vendor",
        href: `/booking/${r.id}`,
        requestId: r.id,
      })
    }
    if (
      (r.status === "paid" || r.status === "completed") &&
      isWithinHours(r.eventDate, 72)
    ) {
      items.push({
        id: `event-day-${r.id}`,
        type: "event_day_reminder",
        title: `Event day — ${r.eventName}`,
        message: "Review load-in, parking, and check-in on your pass.",
        severity: severity("event_day_reminder"),
        role: "vendor",
        href: `/pass/${r.id}`,
        actionLabel: "View pass",
        requestId: r.id,
        actionRequired: true,
      })
    }
  }

  for (const item of input.readiness?.missingItems ?? []) {
    const isExpiring = item.documentUrgency === "expiring_soon"
    const isExpired = item.documentUrgency === "expired"
    const type: LnkupNotificationType =
      isExpiring || isExpired ? "document_expiring" : "document_missing"
    items.push({
      id: `doc-${item.id}`,
      type,
      title: item.label,
      severity: isExpired ? "urgent" : severity(type),
      role: "vendor",
      href: item.actionHref?.startsWith("/(vendor)")
        ? item.actionHref
        : "/(vendor)/documents",
      actionLabel: item.actionLabel ?? "Upload",
      actionRequired: item.severity === "required",
    })
  }

  if ((input.reviews?.reviewCount ?? 0) > 0 && (input.reviews?.reviews.length ?? 0) > 0) {
    const latest = input.reviews!.reviews[0]
    items.push({
      id: `review-${latest.id}`,
      type: "review_received",
      title: `Review received for ${latest.eventTitle ?? "your event"}`,
      message: latest.publicText,
      severity: severity("review_received"),
      role: "vendor",
      href: "/(vendor)/reviews",
      actionLabel: "View review",
      createdAt: latest.createdAt,
    })
  }

  if ((input.unreadBroadcasts ?? 0) > 0) {
    items.push({
      id: "ops-broadcasts",
      type: "system",
      title: `${input.unreadBroadcasts} event update${input.unreadBroadcasts === 1 ? "" : "s"}`,
      message: "Check operations for organizer messages.",
      severity: "info",
      role: "vendor",
      href: "/(vendor)/operations",
      actionLabel: "View operations",
    })
  }

  return items.sort((a, b) => {
    const ta = Date.parse(a.createdAt ?? "") || 0
    const tb = Date.parse(b.createdAt ?? "") || 0
    return tb - ta
  })
}

export type OrganizerNotificationInputs = {
  homeSummary?: OrganizerHomeSummary | null
  pendingRequests?: OrganizerVendorRequest[]
}

export function composeOrganizerNotifications(
  input: OrganizerNotificationInputs,
): LnkupNotification[] {
  const items: LnkupNotification[] = []

  for (const req of input.pendingRequests ?? []) {
    if (req.status === "under_review" || req.status === "submitted") {
      items.push({
        id: `org-req-${req.id}`,
        type: "organizer_new_vendor_request",
        title: `New request from ${req.vendorBusinessName}`,
        message: req.eventName,
        severity: severity("organizer_new_vendor_request"),
        role: "organizer",
        href: "/(organizer)/vendors?tab=requests",
        actionLabel: "Review",
        requestId: req.id,
        actionRequired: true,
        createdAt: req.submittedAtIso ?? req.submittedAt,
      })
    }
  }

  const paymentRequired = input.homeSummary?.stats.paymentRequired ?? 0
  if (paymentRequired > 0) {
    items.push({
      id: "org-payment-required",
      type: "organizer_event_needs_attention",
      title:
        paymentRequired === 1
          ? "1 vendor needs to pay"
          : `${paymentRequired} vendors need to pay`,
      message: "Follow up on outstanding vendor payments.",
      severity: severity("organizer_event_needs_attention"),
      role: "organizer",
      href: "/(organizer)/money",
      actionLabel: "View money",
      actionRequired: true,
    })
  }

  const pendingApps = input.homeSummary?.stats.vendorRequests ?? 0
  if (pendingApps > 0 && (input.pendingRequests?.length ?? 0) === 0) {
    items.push({
      id: "org-pending-apps",
      type: "organizer_new_vendor_request",
      title: `${pendingApps} pending request${pendingApps === 1 ? "" : "s"}`,
      severity: severity("organizer_new_vendor_request"),
      role: "organizer",
      href: "/(organizer)/vendors?tab=requests",
      actionLabel: "Review",
      actionRequired: true,
    })
  }

  if ((input.homeSummary?.stats.paidVendors ?? 0) > 0) {
    items.push({
      id: "org-paid-vendors",
      type: "organizer_payment_received",
      title: "Vendor payments received",
      message: `${input.homeSummary!.stats.paidVendors} paid booking${input.homeSummary!.stats.paidVendors === 1 ? "" : "s"} on your events.`,
      severity: severity("organizer_payment_received"),
      role: "organizer",
      href: "/(organizer)/money",
      actionLabel: "View money",
    })
  }

  return items.sort((a, b) => {
    const ta = Date.parse(a.createdAt ?? "") || 0
    const tb = Date.parse(b.createdAt ?? "") || 0
    return tb - ta
  })
}

export function countActionRequired(items: LnkupNotification[]): number {
  return items.filter((n) => isActionRequiredNotification(n) && !n.readAt).length
}
