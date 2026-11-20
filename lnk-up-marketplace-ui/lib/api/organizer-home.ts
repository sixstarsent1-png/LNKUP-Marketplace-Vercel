import type { OrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { mapOrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import {
  buildDefaultExperiments,
  buildDefaultRecommendations,
  buildDefaultSegmentDiagnostics,
  mapExperimentRecommendations,
  mapExperimentSnapshots,
  mapFunnelSegmentDiagnostics,
  prioritizeRecommendations,
  type ExperimentRecommendation,
  type ExperimentSnapshot,
  type FunnelSegmentDiagnostic,
} from "@/lib/ops/experimentation-contract"

export type OrganizerHomeSummary = {
  organizerId?: string
  stats: {
    events: number
    openOpportunities: number
    vendorRequests: number
    approvedVendors: number
    paymentRequired: number
    paidVendors: number
  }
  upcomingEvents: Array<{
    id: string
    title: string
    date?: string
    city?: string
    state?: string
    status?: string
    requestsCount?: number
    approvedCount?: number
    paidCount?: number
  }>
  attentionItems: Array<{
    id: string
    label: string
    description?: string
    href?: string
    severity?: "info" | "warning" | "urgent"
  }>
  launchChecklist?: Array<{
    id: string
    label: string
    complete: boolean
    href?: string
  }>
  financial?: OrganizerFinancialSnapshot
  intelligence: {
    diagnostics: FunnelSegmentDiagnostic[]
    experiments: ExperimentSnapshot[]
    recommendations: ExperimentRecommendation[]
  }
  raw?: unknown
}

type WpIntelSummary = {
  organizer_id?: number
  total_events?: number
  active_opportunities?: number
  applications_received?: number
  approved_vendors?: number
  paid_vendors?: number
  recommended_actions?: Array<{ id?: string; label?: string; href?: string; severity?: string }>
  experiment_snapshots?: unknown[]
  funnel_diagnostics?: unknown[]
  recommendations?: unknown[]
}

type WpDashboardWidgets = {
  pending_applications?: number
  upcoming_rosters?: Array<{ event_id?: number; title?: string; total?: number }>
}

type WpLaunchItem = {
  id?: string
  key?: string
  label?: string
  title?: string
  complete?: boolean
  done?: boolean
  status?: string
  href?: string
  cta_url?: string
}

function mapWpChecklistItem(item: WpLaunchItem, index: number) {
  const complete =
    item.complete ??
    item.done ??
    (item.status === "complete" || item.status === "done")
  return {
    id: item.id || item.key || `check-${index}`,
    label: item.label || item.title || "Task",
    complete: Boolean(complete),
    href: item.href || item.cta_url,
  }
}

export function mapOrganizerHomeSummary(
  intel: WpIntelSummary,
  widgets: WpDashboardWidgets,
  checklist: WpLaunchItem[] | { items?: WpLaunchItem[] },
  financial?: unknown,
): OrganizerHomeSummary {
  const checklistItems = Array.isArray(checklist) ? checklist : checklist.items ?? []
  const pending = widgets.pending_applications ?? 0
  const approved = intel.approved_vendors ?? 0
  const paid = intel.paid_vendors ?? 0
  const paymentRequired = Math.max(0, approved - paid)

  const attention: OrganizerHomeSummary["attentionItems"] = []
  if (pending > 0) {
    attention.push({
      id: "pending-requests",
      label: `${pending} vendor request${pending === 1 ? "" : "s"} awaiting review`,
      description: "Review applications to keep your vendor row on track.",
      href: "/organizer/vendors",
      severity: "warning",
    })
  }
  if (paymentRequired > 0) {
    attention.push({
      id: "payment-required",
      label: `${paymentRequired} approved vendor${paymentRequired === 1 ? "" : "s"} need payment`,
      description: "Vendors pay after approval to secure their spot.",
      href: "/organizer/vendors",
      severity: "info",
    })
  }
  for (const action of intel.recommended_actions ?? []) {
    if (action.label) {
      attention.push({
        id: action.id || action.label,
        label: action.label,
        href: action.href || "/organizer",
        severity: (action.severity as "info" | "warning" | "urgent") || "info",
      })
    }
  }

  const upcoming = (widgets.upcoming_rosters ?? []).map((r) => ({
    id: String(r.event_id ?? ""),
    title: r.title || "Event",
    requestsCount: r.total,
  }))
  const diagnostics = mapFunnelSegmentDiagnostics(intel.funnel_diagnostics)
  const experiments = mapExperimentSnapshots(intel.experiment_snapshots)
  const recommendations = prioritizeRecommendations(mapExperimentRecommendations(intel.recommendations))

  return {
    organizerId: intel.organizer_id ? String(intel.organizer_id) : undefined,
    stats: {
      events: intel.total_events ?? 0,
      openOpportunities: intel.active_opportunities ?? 0,
      vendorRequests: intel.applications_received ?? 0,
      approvedVendors: approved,
      paymentRequired,
      paidVendors: paid,
    },
    upcomingEvents: upcoming,
    attentionItems: attention,
    launchChecklist: checklistItems.map(mapWpChecklistItem),
    financial: financial ? mapOrganizerFinancialSnapshot(financial) : undefined,
    intelligence: {
      diagnostics: diagnostics.length > 0 ? diagnostics : buildDefaultSegmentDiagnostics(),
      experiments: experiments.length > 0 ? experiments : buildDefaultExperiments(),
      recommendations: recommendations.length > 0 ? recommendations : buildDefaultRecommendations(),
    },
    raw: { intel, widgets, checklist },
  }
}
