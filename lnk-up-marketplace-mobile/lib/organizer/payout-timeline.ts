import type { OrganizerPayout } from "@/lib/api/organizer-financial"

export type PayoutTimelineStep = { key: string; label: string; at?: string; done: boolean; tone?: "error" }

/** Requested → on the way → paid (or failed / cancelled), from the payout's own timestamps. */
export function payoutTimeline(payout: OrganizerPayout): PayoutTimelineStep[] {
  const steps: PayoutTimelineStep[] = [{ key: "requested", label: "Requested", at: payout.createdAt, done: true }]
  switch (payout.status) {
    case "requested":
    case "approved":
      steps.push({ key: "processing", label: "On the way", done: false }, { key: "paid", label: "Paid", done: false })
      break
    case "processing":
      steps.push({ key: "processing", label: "On the way", at: payout.updatedAt, done: true }, { key: "paid", label: "Paid", done: false })
      break
    case "paid":
      steps.push({ key: "processing", label: "On the way", done: true }, { key: "paid", label: "Paid", at: payout.paidAt ?? payout.updatedAt, done: true })
      break
    case "failed":
      steps.push({ key: "failed", label: "Failed — money returned to your balance", at: payout.updatedAt, done: true, tone: "error" })
      break
    case "cancelled":
      steps.push({ key: "cancelled", label: "Cancelled — money returned to your balance", at: payout.updatedAt, done: true })
      break
    case "unknown":
      break
    default: {
      const exhaustive: never = payout.status
      return exhaustive
    }
  }
  return steps
}
