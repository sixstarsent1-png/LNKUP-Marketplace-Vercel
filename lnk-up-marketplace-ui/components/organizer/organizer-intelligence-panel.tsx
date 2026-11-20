"use client"

import Link from "next/link"
import { useEffect } from "react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
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

export function OrganizerIntelligencePanel({
  diagnostics,
  experiments,
  recommendations,
}: {
  diagnostics: FunnelSegmentDiagnostic[]
  experiments: ExperimentSnapshot[]
  recommendations: ExperimentRecommendation[]
}) {
  const topExperiment = experiments[0]
  const topRecommendation = recommendations[0]

  useEffect(() => {
    if (!topExperiment) return
    trackLnkupEvent({
      event: "organizer_experiment_viewed",
      source: "organizer_home_intelligence",
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
      source: "organizer_home_intelligence",
      metadata: {
        recommendationId: topRecommendation.id,
        funnelStage: topRecommendation.funnelStage,
        segment: `${topRecommendation.segment.type}:${topRecommendation.segment.value}`,
      },
    })
  }, [topRecommendation])

  return (
    <section data-testid="organizer-intelligence-panel">
      <Card>
        <CardHeader>
          <CardTitle>Intelligence and experiments</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {topExperiment ? (
            <div className="rounded-2xl border p-4" data-testid="organizer-active-experiment">
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
                    {guardrail.label}
                  </Badge>
                ))}
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">No active experiments yet.</p>
          )}

          {diagnostics.length > 0 && (
            <ul className="space-y-2" data-testid="organizer-intelligence-diagnostics">
              {diagnostics.slice(0, 2).map((item) => (
                <li key={item.id} className="rounded-xl border p-3 text-sm">
                  <span className="font-medium">
                    {item.segment.value} {item.stage}
                  </span>
                  <span className="ml-2 text-muted-foreground">
                    drop-off {item.dropoffRate.toFixed(1)}%
                  </span>
                </li>
              ))}
            </ul>
          )}

          {topRecommendation && (
            <div className="rounded-2xl border border-dashed p-4" data-testid="organizer-top-recommendation">
              <p className="font-medium">{topRecommendation.issue}</p>
              <p className="mt-1 text-sm text-muted-foreground">{topRecommendation.candidateAction}</p>
              <p className="mt-1 text-sm">
                Expected shift:{" "}
                <span className="font-medium">{topRecommendation.expectedMetricShift}</span>
              </p>
              <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                <span>Confidence: {topRecommendation.confidenceLabel}</span>
                <span>Decision: {topRecommendation.decision.status}</span>
              </div>
              {topRecommendation.href && (
                <Button
                  className="mt-3"
                  size="sm"
                  nativeButton={false}
                  onClick={() => {
                    trackLnkupEvent({
                      event: "organizer_experiment_recommendation_action_clicked",
                      source: "organizer_home_intelligence",
                      metadata: {
                        experimentId: topRecommendation.experimentId ?? null,
                        funnelStage: topRecommendation.funnelStage,
                        segment: `${topRecommendation.segment.type}:${topRecommendation.segment.value}`,
                        decision: topRecommendation.decision.status,
                      },
                    })
                    trackLnkupEvent({
                      event: "organizer_experiment_decision_recorded",
                      source: "organizer_home_intelligence",
                      metadata: {
                        experimentId: topRecommendation.experimentId ?? null,
                        decision: topRecommendation.decision.status,
                        confidence: topRecommendation.confidenceLabel,
                      },
                    })
                  }}
                  render={<Link href={topRecommendation.href} />}
                >
                  Take next action
                </Button>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </section>
  )
}
