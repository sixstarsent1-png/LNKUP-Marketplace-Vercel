import type { TourStop } from "@/lib/api/match"

export type TourStopCta =
  | { kind: "apply"; opportunityId: number; label: string }
  | { kind: "status"; label: string }
  | { kind: "none" }

/**
 * What a tour stop card offers: apply to the first open slot the vendor may apply to (including
 * after a withdrawal or a re-opened decline), otherwise the existing application's status.
 */
export function tourStopCta(stop: TourStop): TourStopCta {
  const open = stop.opportunities.find((o) => o.isOpen && (!o.applicationStatus || o.canApplyAgain))
  const active = stop.opportunities.find((o) => o.applicationStatus && !o.canApplyAgain)
  if (active) return { kind: "status", label: `Application: ${active.applicationStatus.replace(/_/g, " ")}` }
  if (open) {
    return { kind: "apply", opportunityId: open.opportunityId, label: open.applicationStatus ? "Apply again" : "Apply to this stop" }
  }
  return { kind: "none" }
}
