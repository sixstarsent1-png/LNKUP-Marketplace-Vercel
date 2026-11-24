export const NOTIFICATION_TYPES = [
  // Composed on-device from live state.
  "application_under_review",
  "application_waitlisted",
  "application_rejected",
  "payment_required",
  "payment_paid",
  "packet_unlocked",
  "event_day_reminder",
  "document_missing",
  "document_expiring",
  "review_received",
  "review_requested",
  "organizer_new_vendor_request",
  "organizer_event_needs_attention",
  "organizer_payment_received",
  "system",
  // Server (lnkup-communications) — vendor.
  "application_submitted",
  "application_approved",
  "application_withdrawn",
  "application_cancelled",
  "info_requested",
  "payment_due_soon",
  "payment_failed",
  "receipt_available",
  "refund_completed",
  "document_approved",
  "document_rejected",
  "pass_updated",
  "booth_assigned",
  "event_logistics_changed",
  "event_cancelled",
  "checked_in",
  "event_reminder",
  "organizer_announcement",
  "event_completed",
  "activation_profile",
  "discovery_profile_match",
  "discovery_favorite_organizer",
  "discovery_tour_match",
  "discovery_weekly_matches",
  "discovery_saved_search",
  "discovery_favorite_deadline",
  "discovery_invite",
  "discovery_invite_expiring",
  "discovery_deadline",
  "discovery_almost_full",
  "payment_confirmed",
  "discovery_reactivation",
  "application_draft",
  "digest",
  "passport_verification",
  "passport_verification_expiring",
  "passport_review_received",
  "passport_review_moderated",
  // Server — organizer.
  "activation_create_event",
  "organizer_info_response",
  "organizer_application_withdrawn",
  "organizer_waitlist_spot",
  "organizer_vendor_paid",
  "organizer_payment_failed",
  "organizer_refund_completed",
  "organizer_dispute_opened",
  "organizer_dispute_closed",
  "organizer_payout",
  "organizer_payout_setup",
  "organizer_event_published",
  "organizer_recruit_vendors",
  "organizer_open_spots",
  "organizer_checkin_activity",
  "organizer_event_completed",
  "organizer_ops_summary",
  "organizer_new_vendors_nearby",
  "organizer_invite_response",
  "organizer_event_no_slots",
  "organizer_review_response",
  "organizer_review_moderated",
  "organizer_document_shared",
  "organizer_vendor_missing_documents",
  // Server — partner (City Connector, affiliate, strategic partner).
  "partner_application",
  "partner_referral",
  "partner_commission",
  "partner_payout",
  "partner_lead",
  // Server — vendor/organizer Referral Invites.
  "referral_joined",
  "referral_credit",
  // Shared.
  "platform_announcement",
] as const

export type LnkupNotificationType = (typeof NOTIFICATION_TYPES)[number]

const KNOWN_TYPES = new Set<string>(NOTIFICATION_TYPES)

/** Server types this build doesn't know yet render as a generic update. */
export function normalizeNotificationType(raw: unknown): LnkupNotificationType {
  return typeof raw === "string" && KNOWN_TYPES.has(raw) ? (raw as LnkupNotificationType) : "system"
}

export type InboxTab =
  | "all"
  | "applications"
  | "payments"
  | "events"
  | "opportunities"
  | "operations"
  | "invitations"
  | "account"

export function inboxTabsFor(role: "vendor" | "organizer" | "partner"): ReadonlyArray<{ value: InboxTab; label: string }> {
  if (role === "partner") {
    return [{ value: "all", label: "All" }]
  }
  if (role === "organizer") {
    return [
      { value: "all", label: "All" },
      { value: "applications", label: "Applications" },
      { value: "payments", label: "Payments" },
      { value: "events", label: "Events" },
      { value: "operations", label: "Operations" },
      { value: "invitations", label: "Invitations" },
      { value: "account", label: "Account" },
    ]
  }
  return [
    { value: "all", label: "All" },
    { value: "applications", label: "Applications" },
    { value: "payments", label: "Payments" },
    { value: "events", label: "Events" },
    { value: "opportunities", label: "Opportunities" },
    { value: "invitations", label: "Invitations" },
    { value: "account", label: "Account" },
  ]
}

export type LnkupNotification = {
  id: string
  type: LnkupNotificationType
  title: string
  message?: string
  createdAt?: string
  readAt?: string
  severity?: "info" | "success" | "warning" | "urgent"
  role?: "vendor" | "organizer" | "partner"
  href?: string
  actionLabel?: string
  requestId?: string
  /** Set on server inbox items, whose `href` is a web link. */
  applicationId?: string
  actionRequired?: boolean
  /** In-app route chosen by the server (validated against the allowlist before use). */
  deepLink?: string
  eventId?: string
  opportunityId?: string
  /** lnkup-communications message id, used for open tracking. */
  notificationId?: number
  tab?: InboxTab
  category?: string
  priority?: "critical" | "high" | "normal" | "promotional" | ""
}

export type NotificationFilter = "all" | "unread" | "action"

const ACTION_REQUIRED_TYPES = new Set<LnkupNotificationType>([
  "payment_required",
  "payment_due_soon",
  "payment_failed",
  "info_requested",
  "document_missing",
  "document_expiring",
  "document_rejected",
  "review_requested",
  "organizer_new_vendor_request",
  "organizer_event_needs_attention",
  "event_day_reminder",
])

export function isActionRequiredNotification(n: LnkupNotification): boolean {
  return n.actionRequired === true || ACTION_REQUIRED_TYPES.has(n.type)
}

const TYPE_LABELS: Record<LnkupNotificationType, string> = {
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
  application_submitted: "Application",
  application_approved: "Approved",
  application_withdrawn: "Withdrawn",
  application_cancelled: "Cancelled",
  info_requested: "Action needed",
  payment_due_soon: "Payment due",
  payment_failed: "Payment issue",
  receipt_available: "Receipt",
  refund_completed: "Refund",
  document_approved: "Document approved",
  document_rejected: "Document needs fix",
  pass_updated: "Pass updated",
  booth_assigned: "Booth assigned",
  event_logistics_changed: "Event change",
  event_cancelled: "Event cancelled",
  checked_in: "Checked in",
  event_reminder: "Event reminder",
  organizer_announcement: "Announcement",
  event_completed: "Event wrap-up",
  activation_profile: "Getting started",
  discovery_profile_match: "New opportunity",
  discovery_favorite_organizer: "New opportunity",
  discovery_tour_match: "Tour match",
  discovery_weekly_matches: "Top matches",
  discovery_saved_search: "Saved search",
  discovery_favorite_deadline: "Deadline soon",
  discovery_invite: "Invitation",
  discovery_invite_expiring: "Invitation expiring",
  discovery_deadline: "Deadline soon",
  discovery_almost_full: "Almost full",
  payment_confirmed: "Payment complete",
  discovery_reactivation: "Opportunities for you",
  application_draft: "Finish application",
  digest: "Summary",
  passport_verification: "Verification",
  passport_verification_expiring: "Verification expiring",
  passport_review_received: "New review",
  passport_review_moderated: "Review update",
  activation_create_event: "Getting started",
  organizer_info_response: "Vendor replied",
  organizer_application_withdrawn: "Withdrawn",
  organizer_waitlist_spot: "Spot opened",
  organizer_vendor_paid: "Vendor paid",
  organizer_payment_failed: "Payment issue",
  organizer_refund_completed: "Refund",
  organizer_dispute_opened: "Dispute",
  organizer_dispute_closed: "Dispute closed",
  organizer_payout: "Cash out",
  organizer_payout_setup: "Payouts",
  organizer_event_published: "Event live",
  organizer_recruit_vendors: "Recruit vendors",
  organizer_open_spots: "Open spots",
  organizer_checkin_activity: "Check-in",
  organizer_event_completed: "Event wrap-up",
  organizer_ops_summary: "Summary",
  organizer_new_vendors_nearby: "New vendors nearby",
  organizer_invite_response: "Invitation reply",
  organizer_event_no_slots: "Add vendor slots",
  organizer_review_response: "Review reply",
  organizer_review_moderated: "Review update",
  organizer_document_shared: "Document shared",
  organizer_vendor_missing_documents: "Documents needed",
  partner_application: "Partner account",
  partner_referral: "Referral",
  partner_commission: "Commission",
  partner_payout: "Payout",
  partner_lead: "Lead update",
  referral_joined: "Invite joined",
  referral_credit: "Referral credit",
  platform_announcement: "LNKup",
}

export function notificationTypeLabel(type: LnkupNotificationType): string {
  return TYPE_LABELS[type]
}
