import type { VendorRequest } from "@/lib/api/vendor-requests"
import { formatShortDate } from "@/lib/format/date"
import { isEventPast } from "@/lib/vendor/vendor-stage"

export type TimelineState = "done" | "current" | "upcoming" | "stopped"

export type TimelineStep = {
  key: "applied" | "review" | "info" | "decision" | "payment" | "event"
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

  const checkedIn = serverStep(request, "checked_in")
  steps.push({
    key: "event",
    label: checkedIn?.state === "done" ? "Checked in" : s === "completed" || (paid && past) ? "Event completed" : "Event day check-in",
    detail: checkedIn?.at ? formatStamp(checkedIn.at) : request.eventDate,
    state: checkedIn?.state === "done" || s === "completed" || (paid && past) ? "done" : paid ? "current" : "upcoming",
  })
  return withServerTimestamps(steps, request)
}

/** Server timeline (single-booking endpoint) step by key. */
function serverStep(request: VendorRequest, key: string) {
  return request.timeline?.find((step) => step.key === key)
}

function formatStamp(at: string): string | undefined {
  const day = at.slice(0, 10)
  return formatShortDate(day) ?? undefined
}

const SERVER_KEY: Partial<Record<TimelineStep["key"], string>> = { applied: "submitted", decision: "approved", payment: "paid" }

/** Add recorded dates to completed steps. Steps without a stored timestamp keep their text. */
function withServerTimestamps(steps: TimelineStep[], request: VendorRequest): TimelineStep[] {
  if (!request.timeline) return steps
  return steps.map((step) => {
    const key = SERVER_KEY[step.key]
    const server = key ? serverStep(request, key) : undefined
    if (step.state !== "done" || !server?.at) return step
    const stamp = formatStamp(server.at)
    return stamp ? { ...step, detail: step.key === "applied" ? `Submitted ${stamp}` : stamp } : step
  })
}
