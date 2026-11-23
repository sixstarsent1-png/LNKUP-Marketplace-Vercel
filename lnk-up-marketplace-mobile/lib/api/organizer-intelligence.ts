export type OrganizerExperimentStatus = "draft" | "active" | "paused" | "completed"

export type OrganizerExperimentDecisionStatus = "ship" | "iterate" | "stop"

export type OrganizerExperimentSegmentKey = "role" | "city" | "category"

export type OrganizerGuardrailStatus = "healthy" | "warning" | "breach" | "unknown"

export type OrganizerExperimentGuardrail = {
  id: string
  label: string
  metric: string
  thresholdLabel: string
  currentValue: number | null
  status: OrganizerGuardrailStatus
}

export type OrganizerExperimentSnapshot = {
  experimentId: string
  name: string
  variant: string
  segment: {
    type: OrganizerExperimentSegmentKey
    value: string
  }
  status: OrganizerExperimentStatus
  owner: string
  decisionDueAt?: string
  confidenceLabel?: "low" | "medium" | "high"
  guardrails: OrganizerExperimentGuardrail[]
}

export type OrganizerFunnelDiagnostic = {
  id: string
  segment: {
    type: OrganizerExperimentSegmentKey
    value: string
  }
  stage: string
  conversionRate: number
  dropoffRate: number
  sampleSize: number
}

export type OrganizerRecommendation = {
  id: string
  issue: string
  funnelStage: string
  segment: {
    type: OrganizerExperimentSegmentKey
    value: string
  }
  candidateAction: string
  expectedMetricShift: string
  confidenceLabel: "low" | "medium" | "high"
  decision: {
    status: OrganizerExperimentDecisionStatus
    dueAt: string
  }
  experimentId?: string
  href?: string
  /** "action" = rule-based recommended action (no experiment impact or confidence to show). */
  kind?: "experiment" | "action"
}

export type OrganizerEventHighlight = {
  eventId: string
  title: string
  score: number
  label: string
  tone: "attention" | "strong"
}

function normalizeSegmentType(value: unknown): OrganizerExperimentSegmentKey {
  if (value === "role" || value === "city" || value === "category") return value
  return "city"
}

function normalizeExperimentStatus(value: unknown): OrganizerExperimentStatus {
  if (value === "draft" || value === "active" || value === "paused" || value === "completed") return value
  return "draft"
}

function normalizeDecisionStatus(value: unknown): OrganizerExperimentDecisionStatus {
  if (value === "ship" || value === "iterate" || value === "stop") return value
  return "iterate"
}

function normalizeGuardrailStatus(value: unknown): OrganizerGuardrailStatus {
  if (value === "healthy" || value === "warning" || value === "breach" || value === "unknown") return value
  return "unknown"
}

function normalizeConfidence(value: unknown): "low" | "medium" | "high" {
  if (value === "low" || value === "medium" || value === "high") return value
  return "medium"
}

export function mapOrganizerExperiments(input: unknown): OrganizerExperimentSnapshot[] {
  if (!Array.isArray(input)) return []
  return input
    .map((item, index) => {
      const value = (item ?? {}) as Record<string, unknown>
      const guardrailsRaw = Array.isArray(value.guardrails) ? value.guardrails : []
      return {
        experimentId: String(value.experiment_id ?? value.id ?? `exp-${index + 1}`),
        name: String(value.name ?? value.label ?? "Experiment"),
        variant: String(value.variant ?? "control"),
        segment: {
          type: normalizeSegmentType(value.segment_type),
          value: String(value.segment_value ?? "all"),
        },
        status: normalizeExperimentStatus(value.status),
        owner: String(value.owner ?? "Organizer Ops"),
        decisionDueAt: value.decision_due_at ? String(value.decision_due_at) : undefined,
        confidenceLabel: value.confidence ? normalizeConfidence(value.confidence) : undefined,
        guardrails: guardrailsRaw.map((entry, guardrailIndex) => {
          const guardrail = (entry ?? {}) as Record<string, unknown>
          return {
            id: String(guardrail.id ?? `guardrail-${guardrailIndex + 1}`),
            label: String(guardrail.label ?? "Guardrail"),
            metric: String(guardrail.metric ?? "metric"),
            thresholdLabel: String(guardrail.threshold ?? "n/a"),
            currentValue:
              typeof guardrail.current_value === "number" ? guardrail.current_value : null,
            status: normalizeGuardrailStatus(guardrail.status),
          }
        }),
      }
    })
    .filter((item) => item.experimentId.length > 0)
}

export function mapOrganizerDiagnostics(input: unknown): OrganizerFunnelDiagnostic[] {
  if (!Array.isArray(input)) return []
  return input
    .map((item, index) => {
      const value = (item ?? {}) as Record<string, unknown>
      return {
        id: String(value.id ?? `segment-${index + 1}`),
        segment: {
          type: normalizeSegmentType(value.segment_type),
          value: String(value.segment_value ?? "unknown"),
        },
        stage: String(value.stage ?? "apply_clicked"),
        conversionRate: Number(value.conversion_rate ?? 0),
        dropoffRate: Number(value.dropoff_rate ?? 0),
        sampleSize: Number(value.sample_size ?? 0),
      }
    })
    .filter((item) => item.sampleSize >= 0)
}

export function mapOrganizerRecommendations(input: unknown): OrganizerRecommendation[] {
  if (!Array.isArray(input)) return []
  return input
    .map((item, index) => {
      const value = (item ?? {}) as Record<string, unknown>
      return {
        id: String(value.id ?? `recommendation-${index + 1}`),
        issue: String(value.issue ?? "Drop-off detected."),
        funnelStage: String(value.funnel_stage ?? "apply_clicked"),
        segment: {
          type: normalizeSegmentType(value.segment_type),
          value: String(value.segment_value ?? "all"),
        },
        candidateAction: String(value.candidate_action ?? "Review vendor funnel friction."),
        expectedMetricShift: String(value.expected_metric_shift ?? "Improve conversion."),
        confidenceLabel: normalizeConfidence(value.confidence),
        decision: {
          status: normalizeDecisionStatus(value.decision_status),
          dueAt: String(value.decision_due_at ?? new Date().toISOString()),
        },
        experimentId: value.experiment_id ? String(value.experiment_id) : undefined,
        href: value.href ? String(value.href) : undefined,
      }
    })
    .filter((item) => item.id.length > 0)
}

const ACTION_STAGE: Record<string, string> = {
  send_payment_reminder: "payment",
  view_payment_funnel: "payment",
  approve_waitlisted: "application",
  improve_opportunity: "apply",
}

function priorityConfidence(priority: unknown): OrganizerRecommendation["confidenceLabel"] {
  if (priority === "critical" || priority === "high") return "high"
  if (priority === "medium") return "medium"
  return "low"
}

/**
 * Rule-based actions from /intelligence/organizer/summary (`recommended_actions`):
 * { priority, action_key, title, reason, deadline, cta: { label, url } }.
 */
export function mapOrganizerRecommendedActions(input: unknown): OrganizerRecommendation[] {
  if (!Array.isArray(input)) return []
  return input
    .map((item, index) => {
      const value = (item ?? {}) as Record<string, unknown>
      const key = String(value.action_key ?? "")
      const cta = (value.cta ?? {}) as Record<string, unknown>
      const title = String(value.title ?? "").trim()
      const reason = String(value.reason ?? "").trim()
      return {
        id: `${key || "action"}-${index + 1}`,
        issue: title || reason,
        funnelStage: ACTION_STAGE[key] ?? key,
        segment: { type: "city" as const, value: "all" },
        candidateAction: reason && reason !== title ? reason : "",
        expectedMetricShift: "",
        confidenceLabel: priorityConfidence(value.priority),
        decision: {
          status: "iterate" as const,
          dueAt: typeof value.deadline === "string" && value.deadline ? value.deadline : new Date().toISOString(),
        },
        href: typeof cta.url === "string" && cta.url ? cta.url : undefined,
        kind: "action" as const,
      }
    })
    .filter((item) => item.issue.length > 0)
}

function mapEventRows(input: unknown): Array<{ eventId: string; title: string; score: number; label: string }> {
  if (!Array.isArray(input)) return []
  return input
    .map((item) => {
      const value = (item ?? {}) as Record<string, unknown>
      return {
        eventId: String(value.event_id ?? ""),
        title: String(value.title ?? ""),
        score: Number(value.score ?? 0) || 0,
        label: String(value.label ?? ""),
      }
    })
    .filter((row) => row.eventId && row.title)
}

/**
 * Event health from `weak_events` / `top_events`: up to two events that need attention (score below 70),
 * otherwise the strongest event.
 */
export function mapOrganizerEventHighlights(weak: unknown, top: unknown): OrganizerEventHighlight[] {
  const attention = mapEventRows(weak)
    .filter((row) => row.score < 70)
    .slice(0, 2)
    .map((row) => ({ ...row, tone: "attention" as const }))
  if (attention.length) return attention
  const best = mapEventRows(top)[0]
  return best ? [{ ...best, tone: "strong" as const }] : []
}

const confidenceRank: Record<OrganizerRecommendation["confidenceLabel"], number> = {
  high: 3,
  medium: 2,
  low: 1,
}

export function prioritizeOrganizerRecommendations(
  recommendations: OrganizerRecommendation[],
): OrganizerRecommendation[] {
  return [...recommendations].sort((a, b) => {
    const confidenceDelta = confidenceRank[b.confidenceLabel] - confidenceRank[a.confidenceLabel]
    if (confidenceDelta !== 0) return confidenceDelta
    const dueA = Date.parse(a.decision.dueAt)
    const dueB = Date.parse(b.decision.dueAt)
    if (Number.isNaN(dueA) || Number.isNaN(dueB)) return 0
    return dueA - dueB
  })
}
