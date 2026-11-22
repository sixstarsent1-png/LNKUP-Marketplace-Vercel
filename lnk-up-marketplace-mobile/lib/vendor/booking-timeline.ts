import type { VendorRequest } from "@/lib/api/vendor-requests"
import { isEventPast } from "@/lib/vendor/vendor-stage"

export type TimelineState = "done" | "current" | "upcoming" | "stopped"

export type TimelineStep = {
  /** Local steps use applied/review/info/decision/payment/event; server timelines use their own keys. */
  key: string
  label: string
  detail?: string
  state: TimelineState
}

/**
 * Application → review → (question) → decision → payment → event day, showing only the steps
 * that apply to this booking. Derived purely from the server status and event date.
 */
export function bookingTimeline(request: VendorRequest, now: Date = new Date()): TimelineStep[] {
  const s = request.status
  const past = isEventPast(request, now)
  const decided = ["approved", "payment_required", "paid", "completed"].includes(s)
  const steps: TimelineStep[] = [
    { key: "applied", label: "Applied", detail: request.submittedAt, state: "done" },
  ]

  if (s === "info_requested") {
    steps.push({ key: "review", label: "In review", state: "done" })
    steps.push({
      key: "info",
      label: "Organizer asked a question",
      detail: "Reply below — they'll decide after your answer.",
      state: "current",
    })
  } else {
    steps.push({
      key: "review",
      label: "In review",
      detail: s === "submitted" || s === "under_review" ? "The organizer is reviewing your application." : undefined,
      state: s === "submitted" || s === "under_review" || s === "unknown" ? "current" : "done",
    })
  }

  if (s === "rejected") {
    steps.push({ key: "decision", label: "Declined", detail: "This organizer chose other vendors.", state: "stopped" })
    return steps
  }
  if (s === "cancelled") {
    steps.push({ key: "decision", label: "Cancelled", state: "stopped" })
    return steps
  }
  if (s === "waitlisted") {
    steps.push({
      key: "decision",
      label: "Waitlisted",
      detail: "You'll be offered a spot if one opens up.",
      state: "current",
    })
    return steps
  }

  steps.push({ key: "decision", label: "Approved", state: decided ? "done" : "upcoming" })

  const paid = s === "paid" || s === "completed"
  steps.push({
    key: "payment",
    label: paid ? "Paid · booking confirmed" : "Pay booth fee to confirm",
    detail: !paid && (s === "approved" || s === "payment_required") ? "Your spot is held until you pay." : undefined,
    state: paid ? "done" : s === "approved" || s === "payment_required" ? "current" : "upcoming",
  })

  steps.push({
    key: "event",
    label: s === "completed" || (paid && past) ? "Event completed" : "Event day check-in",
    detail: request.eventDate,
    state: s === "completed" || (paid && past) ? "done" : paid ? "current" : "upcoming",
  })
  return steps
}
