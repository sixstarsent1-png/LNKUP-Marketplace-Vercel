"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { ErrorState, SkeletonList } from "@/components/marketplace/states"
import { useEffect } from "react"
import type { PanelState } from "@/lib/use-panel-state"
import type {
  ExperimentRecommendation,
  ExperimentSnapshot,
  FunnelSegmentDiagnostic,
} from "@/lib/ops/experimentation-contract"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

function guardrailVariant(status: "healthy" | "warning" | "breach" | "unknown") {
  if (status === "breach") return "destructive" as const
  if (status === "warning") return "secondary" as const
  return "outline" as const
}

export function ExperimentInsightsSection({
  experiments,
  diagnostics,
  recommendations,
  state,
  onRetry,
}: {
  experiments: ExperimentSnapshot[]
  diagnostics: FunnelSegmentDiagnostic[]
  recommendations: ExperimentRecommendation[]
  state: PanelState
  onRetry: () => void
}) {
  const topExperiment = experiments[0]
  const topRecommendation = recommendations[0]

  useEffect(() => {
    if (!topExperiment) return
    trackLnkupEvent({
      event: "organizer_experiment_viewed",
      source: "ops_analytics_experiments",
      metadata: {
        experimentId: topExperiment.experimentId,
        variant: topExperiment.variant,
        segment: `${topExperiment.segment.type}:${topExperiment.segment.value}`,
      },
    })
  }, [topExperiment])

  useEffect(() => {
    if (!topRecommendation) return
    trackLnkupEvent({
      event: "organizer_experiment_recommendation_opened",
      source: "ops_analytics_experiments",
      metadata: {
        recommendationId: topRecommendation.id,
        funnelStage: topRecommendation.funnelStage,
        segment: `${topRecommendation.segment.type}:${topRecommendation.segment.value}`,
      },
    })
  }, [topRecommendation])

  if (state === "loading") {
    return (
      <Card data-testid="ops-experiment-insights-section">
        <CardHeader>
          <CardTitle>Experiment diagnostics</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={2} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-experiment-insights-section">
        <CardContent className="pt-6">
          <ErrorState title="Experiment diagnostics unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-experiment-insights-section">
      <CardHeader>
        <CardTitle>Experiment diagnostics</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {topExperiment && (
          <div className="rounded-2xl border p-4" data-testid="ops-active-experiment">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{topExperiment.name}</span>
              <Badge variant="outline">{topExperiment.status}</Badge>
              <Badge variant="secondary">{topExperiment.variant}</Badge>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">
              Segment: {topExperiment.segment.type} / {topExperiment.segment.value}
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
              {topExperiment.guardrails.map((guardrail) => (
                <Badge key={guardrail.id} variant={guardrailVariant(guardrail.status)}>
                  {guardrail.label}: {guardrail.thresholdLabel}
                </Badge>
              ))}
            </div>
          </div>
        )}

        {diagnostics.length > 0 ? (
          <ul className="space-y-2" data-testid="ops-segment-diagnostics">
            {diagnostics.slice(0, 3).map((item) => (
              <li key={item.id} className="rounded-xl border p-3 text-sm">
                <span className="font-medium">
                  {item.segment.type}: {item.segment.value}
                </span>
                <span className="ml-2 text-muted-foreground">
                  {item.stage} conversion {item.conversionRate.toFixed(1)}%
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">No segment diagnostics available.</p>
        )}

        {topRecommendation && (
          <div className="rounded-2xl border border-dashed p-4" data-testid="ops-top-recommendation">
            <p className="font-medium">{topRecommendation.issue}</p>
            <p className="mt-1 text-sm text-muted-foreground">{topRecommendation.candidateAction}</p>
            <p className="mt-1 text-sm">
              Expected shift: <span className="font-medium">{topRecommendation.expectedMetricShift}</span>
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
