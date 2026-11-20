import type { LaunchOptimizationAlert } from "@/lib/ops/types"
import type { ExperimentRecommendation } from "@/lib/ops/experimentation-contract"

export type AdminOpsAlert = LaunchOptimizationAlert & {
  recommendationIds?: string[]
}

export type AdminQueueSnapshotForAlerts = {
  externalEventsPendingReview: number
  verificationPending: number
  connectorsPendingApproval: number
  partial?: boolean
  unavailableReasons?: string[]
}

/**
 * Build Admin Lite ops alerts and optionally link Phase 19 recommendations by funnel/issue keywords.
 */
export function buildAdminOpsAlerts(
  queue: AdminQueueSnapshotForAlerts,
  recommendations: ExperimentRecommendation[] = [],
): AdminOpsAlert[] {
  const alerts: AdminOpsAlert[] = []

  const linkRecs = (...needles: string[]) =>
    recommendations
      .filter((rec) => {
        const hay = `${rec.issue} ${rec.funnelStage} ${rec.candidateAction}`.toLowerCase()
        return needles.some((n) => hay.includes(n.toLowerCase()))
      })
      .map((rec) => rec.id)
      .slice(0, 3)

  if (queue.externalEventsPendingReview > 0) {
    alerts.push({
      id: "admin-external-events-queue",
      severity: queue.externalEventsPendingReview >= 10 ? "urgent" : "warning",
      title: `${queue.externalEventsPendingReview} external events awaiting review`,
      description: "Imported events need publish or reject before vendors can discover them.",
      suggestedAction: "Open the external events queue and review high-fit items first.",
      href: "/admin/external-events",
      source: "api_health",
      recommendationIds: linkRecs("supply", "opportunity", "city", "market"),
    })
  }

  if (queue.verificationPending > 0) {
    alerts.push({
      id: "admin-verification-queue",
      severity: queue.verificationPending >= 8 ? "urgent" : "warning",
      title: `${queue.verificationPending} documents pending verification`,
      description: "Vendors may be blocked from approvals until documents are reviewed.",
      suggestedAction: "Approve or reject pending documents in the verification queue.",
      href: "/admin/verification",
      source: "vendor_activation",
      recommendationIds: linkRecs("document", "verification", "readiness", "profile"),
    })
  }

  if (queue.connectorsPendingApproval > 0) {
    alerts.push({
      id: "admin-connectors-queue",
      severity: "info",
      title: `${queue.connectorsPendingApproval} connector approvals pending`,
      description: "City connector applicants are waiting for network approval.",
      suggestedAction: "Approve ready connectors or pause incomplete applications.",
      href: "/admin/connectors",
      source: "market",
      recommendationIds: linkRecs("referral", "connector", "growth"),
    })
  }

  if (queue.partial && (queue.unavailableReasons?.length ?? 0) > 0) {
    alerts.push({
      id: "admin-queue-partial",
      severity: "warning",
      title: "Some admin queues are partial",
      description: queue.unavailableReasons!.join(" "),
      suggestedAction: "Check Admin health and WordPress connectivity.",
      href: "/admin/more",
      source: "api_health",
      recommendationIds: linkRecs("health", "api", "bff"),
    })
  }

  return alerts
}

export function recommendationsForAlert(
  alert: AdminOpsAlert,
  recommendations: ExperimentRecommendation[],
): ExperimentRecommendation[] {
  if (!alert.recommendationIds?.length) return []
  const set = new Set(alert.recommendationIds)
  return recommendations.filter((rec) => set.has(rec.id))
}
