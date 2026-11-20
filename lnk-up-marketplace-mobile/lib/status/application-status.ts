import type { StatusTone } from "@/lib/theme"

/** Canonical WordPress application statuses (lnkup_application `_lnkup_application_status`). */
export type ApplicationStatus =
  | "submitted"
  | "under_review"
  | "info_requested"
  | "approved"
  | "payment_required"
  | "paid"
  | "waitlisted"
  | "rejected"
  | "cancelled"
  | "completed"
  | "unknown"

export type StatusAudience = "vendor" | "organizer"

type StatusCopy = { label: string; tone: StatusTone }

/**
 * One wording table for both apps, mirroring the web dashboards (Organizer inbox: To review,
 * More info needed, Awaiting payment, Confirmed, Declined). Vendors see their next step;
 * organizers see what is waiting on whom.
 */
const COPY: Record<ApplicationStatus, Record<StatusAudience, StatusCopy>> = {
  submitted: {
    vendor: { label: "Submitted", tone: "review" },
    organizer: { label: "To review", tone: "review" },
  },
  under_review: {
    vendor: { label: "In review", tone: "review" },
    organizer: { label: "In review", tone: "review" },
  },
  info_requested: {
    vendor: { label: "More info needed", tone: "due" },
    organizer: { label: "Waiting on vendor", tone: "review" },
  },
  approved: {
    vendor: { label: "Approved · payment due", tone: "due" },
    organizer: { label: "Approved", tone: "approved" },
  },
  payment_required: {
    vendor: { label: "Payment due", tone: "due" },
    organizer: { label: "Awaiting payment", tone: "due" },
  },
  paid: {
    vendor: { label: "Confirmed", tone: "paid" },
    organizer: { label: "Confirmed", tone: "paid" },
  },
  waitlisted: {
    vendor: { label: "Waitlisted", tone: "waitlist" },
    organizer: { label: "Waitlisted", tone: "waitlist" },
  },
  rejected: {
    vendor: { label: "Declined", tone: "rejected" },
    organizer: { label: "Declined", tone: "rejected" },
  },
  cancelled: {
    vendor: { label: "Cancelled", tone: "neutral" },
    organizer: { label: "Cancelled", tone: "neutral" },
  },
  completed: {
    vendor: { label: "Completed", tone: "neutral" },
    organizer: { label: "Completed", tone: "neutral" },
  },
  unknown: {
    vendor: { label: "Status pending", tone: "neutral" },
    organizer: { label: "Status pending", tone: "neutral" },
  },
}

export function applicationStatusLabel(status: ApplicationStatus, audience: StatusAudience): string {
  return COPY[status][audience].label
}

export function applicationStatusTone(status: ApplicationStatus, audience: StatusAudience): StatusTone {
  return COPY[status][audience].tone
}

/** One plain sentence telling a vendor what happens next for a booking in this status. */
export function vendorStatusExplainer(status: ApplicationStatus): string {
  switch (status) {
    case "submitted":
    case "under_review":
      return "The organizer is reviewing your application. You only pay if they approve you."
    case "info_requested":
      return "The organizer asked a question. Reply to keep your application moving."
    case "approved":
    case "payment_required":
      return "You're approved. Pay the booth fee to lock in your spot."
    case "paid":
      return "You're booked. Your event pass has arrival and check-in details."
    case "waitlisted":
      return "You're on the waitlist. We'll tell you if a spot opens."
    case "rejected":
      return "The organizer passed on this one. Keep exploring — new events open every week."
    case "cancelled":
      return "This booking was cancelled."
    case "completed":
      return "Event complete. Thanks for vending!"
    case "unknown":
      return "We're checking the latest status with the organizer."
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

/** Vendor lifecycle buckets used by Bookings tabs and Home. */
export type VendorLifecycleBucket = "action" | "review" | "confirmed" | "past" | "closed"

export function vendorLifecycleBucket(status: ApplicationStatus, eventIsPast: boolean): VendorLifecycleBucket {
  switch (status) {
    case "info_requested":
    case "approved":
    case "payment_required":
      return eventIsPast ? "closed" : "action"
    case "submitted":
    case "under_review":
    case "waitlisted":
    case "unknown":
      return eventIsPast ? "closed" : "review"
    case "paid":
      return eventIsPast ? "past" : "confirmed"
    case "completed":
      return "past"
    case "rejected":
    case "cancelled":
      return "closed"
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}
