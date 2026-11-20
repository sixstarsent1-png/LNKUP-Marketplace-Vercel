export type ExperimentStatus = "draft" | "active" | "paused" | "completed"

export type ExperimentDecisionStatus = "ship" | "iterate" | "stop"

export type ExperimentSegmentKey = "role" | "city" | "category"

export type ExperimentGuardrailStatus = "healthy" | "warning" | "breach" | "unknown"

export type ExperimentWindow = {
  startAt: string
  endAt?: string
}

export type ExperimentGuardrail = {
  id: string
  label: string
  metric: string
  thresholdLabel: string
  currentValue: number | null
  status: ExperimentGuardrailStatus
}

export type ExperimentSnapshot = {
  experimentId: string
  name: string
  variant: string
  segment: {
    type: ExperimentSegmentKey
    value: string
  }
  status: ExperimentStatus
  window: ExperimentWindow
  owner: string
  decisionDueAt?: string
  confidenceLabel?: "low" | "medium" | "high"
  guardrails: ExperimentGuardrail[]
}

export type FunnelSegmentDiagnostic = {
  id: string
  segment: {
    type: ExperimentSegmentKey
    value: string
  }
  stage: string
  conversionRate: number
  dropoffRate: number
  sampleSize: number
}

export type RecommendationDecision = {
  status: ExperimentDecisionStatus
  dueAt: string
}

export type ExperimentRecommendation = {
  id: string
  issue: string
  funnelStage: string
  segment: {
    type: ExperimentSegmentKey
    value: string
  }
  candidateAction: string
  expectedMetricShift: string
  confidenceLabel: "low" | "medium" | "high"
  decision: RecommendationDecision
  experimentId?: string
  href?: string
}

function normalizeExperimentStatus(value: unknown): ExperimentStatus {
  if (value === "draft" || value === "active" || value === "paused" || value === "completed") return value
  return "draft"
}

function normalizeDecisionStatus(value: unknown): ExperimentDecisionStatus {
  if (value === "ship" || value === "iterate" || value === "stop") return value
  return "iterate"
}

function normalizeGuardrailStatus(value: unknown): ExperimentGuardrailStatus {
  if (value === "healthy" || value === "warning" || value === "breach" || value === "unknown") return value
  return "unknown"
}

function normalizeSegmentType(value: unknown): ExperimentSegmentKey {
  if (value === "role" || value === "city" || value === "category") return value
  return "city"
}

function normalizeConfidence(value: unknown): "low" | "medium" | "high" {
  if (value === "low" || value === "medium" || value === "high") return value
  return "medium"
}

export function mapExperimentSnapshots(input: unknown): ExperimentSnapshot[] {
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
        window: {
          startAt: String(value.window_start ?? new Date().toISOString()),
          endAt: value.window_end ? String(value.window_end) : undefined,
        },
        owner: String(value.owner ?? "Ops"),
        decisionDueAt: value.decision_due_at ? String(value.decision_due_at) : undefined,
        confidenceLabel: value.confidence ? normalizeConfidence(value.confidence) : undefined,
        guardrails: guardrailsRaw.map((guardrail, guardrailIndex) => {
          const g = (guardrail ?? {}) as Record<string, unknown>
          return {
            id: String(g.id ?? `guardrail-${guardrailIndex + 1}`),
            label: String(g.label ?? "Guardrail"),
            metric: String(g.metric ?? "metric"),
            thresholdLabel: String(g.threshold ?? "n/a"),
            currentValue: typeof g.current_value === "number" ? g.current_value : null,
            status: normalizeGuardrailStatus(g.status),
          }
        }),
      }
    })
    .filter((item) => item.experimentId.length > 0)
}

export function mapFunnelSegmentDiagnostics(input: unknown): FunnelSegmentDiagnostic[] {
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

export function mapExperimentRecommendations(input: unknown): ExperimentRecommendation[] {
  if (!Array.isArray(input)) return []
  return input
    .map((item, index) => {
      const value = (item ?? {}) as Record<string, unknown>
      return {
        id: String(value.id ?? `recommendation-${index + 1}`),
        issue: String(value.issue ?? "Drop-off detected"),
        funnelStage: String(value.funnel_stage ?? "apply_clicked"),
        segment: {
          type: normalizeSegmentType(value.segment_type),
          value: String(value.segment_value ?? "all"),
        },
        candidateAction: String(value.candidate_action ?? "Review listing and application friction."),
        expectedMetricShift: String(value.expected_metric_shift ?? "Improve apply conversion."),
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

const confidenceRank: Record<ExperimentRecommendation["confidenceLabel"], number> = {
  high: 3,
  medium: 2,
  low: 1,
}

export function prioritizeRecommendations(
  recommendations: ExperimentRecommendation[],
): ExperimentRecommendation[] {
  return [...recommendations].sort((a, b) => {
    const confidenceDelta = confidenceRank[b.confidenceLabel] - confidenceRank[a.confidenceLabel]
    if (confidenceDelta !== 0) return confidenceDelta
    const dueA = new Date(a.decision.dueAt).getTime()
    const dueB = new Date(b.decision.dueAt).getTime()
    return dueA - dueB
  })
}

export function buildDefaultExperiments(): ExperimentSnapshot[] {
  return [
    {
      experimentId: "exp-city-apply-cta",
      name: "City-specific apply CTA copy",
      variant: "city-proof-cta",
      segment: { type: "city", value: "Charlotte, NC" },
      status: "active",
      owner: "Organizer Ops",
      window: { startAt: new Date().toISOString() },
      decisionDueAt: new Date(Date.now() + 5 * 24 * 60 * 60 * 1000).toISOString(),
      confidenceLabel: "medium",
      guardrails: [
        {
          id: "refund-rate",
          label: "Refund rate",
          metric: "refund_rate",
          thresholdLabel: "<= 3%",
          currentValue: 1.2,
          status: "healthy",
        },
        {
          id: "apply-error-rate",
          label: "Apply error rate",
          metric: "apply_error_rate",
          thresholdLabel: "<= 2%",
          currentValue: 1.8,
          status: "warning",
        },
      ],
    },
  ]
}

export function buildDefaultSegmentDiagnostics(): FunnelSegmentDiagnostic[] {
  return [
    {
      id: "segment-city-charlotte",
      segment: { type: "city", value: "Charlotte, NC" },
      stage: "apply_clicked",
      conversionRate: 43.2,
      dropoffRate: 56.8,
      sampleSize: 84,
    },
    {
      id: "segment-category-beauty",
      segment: { type: "category", value: "Beauty & Wellness" },
      stage: "application_submitted",
      conversionRate: 28.4,
      dropoffRate: 71.6,
      sampleSize: 52,
    },
  ]
}

export function buildDefaultRecommendations(): ExperimentRecommendation[] {
  return prioritizeRecommendations([
    {
      id: "rec-charlotte-apply-dropoff",
      issue: "High apply-click drop-off in Charlotte opportunities.",
      funnelStage: "application_submitted",
      segment: { type: "city", value: "Charlotte, NC" },
      candidateAction: "A/B test shorter apply copy and upfront fee transparency.",
      expectedMetricShift: "+8-12% application submitted rate",
      confidenceLabel: "medium",
      decision: {
        status: "iterate",
        dueAt: new Date(Date.now() + 4 * 24 * 60 * 60 * 1000).toISOString(),
      },
      experimentId: "exp-city-apply-cta",
      href: "/organizer/events",
    },
  ])
}
