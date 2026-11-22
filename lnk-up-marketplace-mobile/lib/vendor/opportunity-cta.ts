import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { localIsoDay } from "@/lib/format/date"

export type OpportunityCta = {
  label: string
  canApply: boolean
  /** Why the vendor can't apply. */
  reason?: "applied" | "declined" | "closed" | "full" | "ended" | "deadline"
}

const APPLIED = ["submitted", "under_review", "info_requested", "waitlisted", "approved", "payment_required", "paid", "completed"]

/** What the primary button should say for a listing, from server status, dates and inventory. */
export function opportunityApplyCta(item: MarketplaceOpportunity, today: string = localIsoDay()): OpportunityCta {
  const status = (item.applicationStatus || "").toLowerCase().replace(/-/g, "_")
  if (APPLIED.includes(status)) return { label: "Applied", canApply: false, reason: "applied" }
  // Withdrawn applications can always re-apply; declined ones only when the server re-opened them.
  const reapplyAllowed = item.canApplyAgain === true || ["cancelled", "withdrawn"].includes(status)
  if (status === "rejected" && !reapplyAllowed) return { label: "Declined", canApply: false, reason: "declined" }
  if (item.eventDateIso && item.eventDateIso < today) return { label: "Event ended", canApply: false, reason: "ended" }
  if (item.deadlineIso && item.deadlineIso < today) {
    return { label: "Applications closed", canApply: false, reason: "deadline" }
  }
  if (item.spotsTotal > 0 && item.spotsRemaining <= 0) return { label: "Full", canApply: false, reason: "full" }
  return { label: status && reapplyAllowed ? "Apply again" : "Apply", canApply: true }
}
