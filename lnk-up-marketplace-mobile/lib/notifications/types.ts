export type LnkupNotificationType =
  | "application_under_review"
  | "application_waitlisted"
  | "application_rejected"
  | "payment_required"
  | "payment_paid"
  | "packet_unlocked"
  | "event_day_reminder"
  | "document_missing"
  | "document_expiring"
  | "review_received"
  | "review_requested"
  | "organizer_new_vendor_request"
  | "organizer_event_needs_attention"
  | "organizer_payment_received"
  | "system"

export type LnkupNotification = {
  id: string
  type: LnkupNotificationType
  title: string
  message?: string
  createdAt?: string
  readAt?: string
  severity?: "info" | "success" | "warning" | "urgent"
  role?: "vendor" | "organizer"
  href?: string
  actionLabel?: string
  requestId?: string
  /** Set on server inbox items, whose `href` is a web link. */
  applicationId?: string
  actionRequired?: boolean
}

export type NotificationFilter = "all" | "unread" | "action"

const ACTION_REQUIRED_TYPES = new Set<LnkupNotificationType>([
  "payment_required",
  "document_missing",
  "document_expiring",
  "review_requested",
  "organizer_new_vendor_request",
  "organizer_event_needs_attention",
  "event_day_reminder",
])

export function isActionRequiredNotification(n: LnkupNotification): boolean {
  return n.actionRequired === true || ACTION_REQUIRED_TYPES.has(n.type)
}

export function notificationTypeLabel(type: LnkupNotificationType): string {
  const labels: Record<LnkupNotificationType, string> = {
    application_under_review: "Under review",
    application_waitlisted: "Waitlisted",
    application_rejected: "Declined",
    payment_required: "Payment due",
    payment_paid: "Payment complete",
    packet_unlocked: "Pass ready",
    event_day_reminder: "Event day",
    document_missing: "Document needed",
    document_expiring: "Document expiring",
    review_received: "Review received",
    review_requested: "Review pending",
    organizer_new_vendor_request: "New vendor request",
    organizer_event_needs_attention: "Needs attention",
    organizer_payment_received: "Payment received",
    system: "Update",
  }
  return labels[type]
}
