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
