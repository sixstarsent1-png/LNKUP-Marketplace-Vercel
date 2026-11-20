import { Pressable, StyleSheet, View } from "react-native"

import type {
  OrganizerExperimentSnapshot,
  OrganizerFunnelDiagnostic,
  OrganizerRecommendation,
} from "@/lib/api/organizer-intelligence"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { decisionLabel, experimentStatusLabel, humanizeKey, segmentLabel } from "@/lib/format/labels"
import { colors, fonts, radii, spacing } from "@/lib/theme"

type RecommendationAction = {
  label: string
  onPress: () => void
}

function guardrailStyle(status: "healthy" | "warning" | "breach" | "unknown") {
  if (status === "breach") return styles.guardrailBreach
  if (status === "warning") return styles.guardrailWarning
  return styles.guardrailDefault
}

export function OrganizerIntelligenceCard({
  experiments,
  diagnostics,
  recommendations,
  onRecommendationPress,
}: {
  experiments: OrganizerExperimentSnapshot[]
  diagnostics: OrganizerFunnelDiagnostic[]
  recommendations: OrganizerRecommendation[]
  onRecommendationPress: (recommendation: OrganizerRecommendation) => RecommendationAction
}) {
  const experiment = experiments[0]
  const recommendation = recommendations[0]
  const diagnosticRows = diagnostics.slice(0, 2)

  if (!experiment && !recommendation && diagnosticRows.length === 0) return null

  const recommendationAction = recommendation ? onRecommendationPress(recommendation) : null

  return (
    <Surface elevated style={styles.card}>
      <SectionTitle>Insights</SectionTitle>

      {experiment ? (
        <Surface variant="hero" style={styles.panel} testID="organizer-mobile-active-experiment">
          <View style={styles.row}>
            <Meta style={styles.panelTitle}>{experiment.name}</Meta>
            <Meta style={styles.badge}>{experimentStatusLabel(experiment.status)}</Meta>
          </View>
          <Meta>
            Version {experiment.variant} · {segmentLabel(experiment.segment.type, experiment.segment.value)}
          </Meta>
          <View style={styles.guardrailWrap}>
            {experiment.guardrails.map((guardrail) => (
              <Meta
                key={guardrail.id}
                style={[styles.guardrail, guardrailStyle(guardrail.status)]}
              >
                {guardrail.label}
              </Meta>
            ))}
          </View>
        </Surface>
      ) : null}

      {diagnosticRows.length > 0 ? (
        <Surface variant="hero" style={styles.panel} testID="organizer-mobile-diagnostics">
          <Meta style={styles.panelTitle}>Where vendors drop off</Meta>
          {diagnosticRows.map((item) => (
            <Meta key={item.id}>
              {humanizeKey(item.segment.value)} · {humanizeKey(item.stage)} · {item.dropoffRate.toFixed(0)}% stop here
            </Meta>
          ))}
        </Surface>
      ) : null}

      {recommendation && recommendationAction ? (
        <Surface variant="hero" style={styles.panel} testID="organizer-mobile-top-recommendation">
          <Meta style={styles.panelTitle}>{recommendation.issue}</Meta>
          <Meta>{recommendation.candidateAction}</Meta>
          <Meta>Expected impact: {recommendation.expectedMetricShift}</Meta>
          <Meta>
            Confidence: {humanizeKey(recommendation.confidenceLabel)} · {decisionLabel(recommendation.decision.status)}
          </Meta>
          <Pressable
            style={styles.actionButton}
            onPress={recommendationAction.onPress}
            accessibilityRole="button"
          >
            <Meta style={styles.actionText}>{recommendationAction.label}</Meta>
          </Pressable>
        </Surface>
      ) : null}
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.md,
  },
  panel: {
    padding: spacing.md,
    gap: spacing.xs,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.sm,
  },
  panelTitle: {
    flex: 1,
    fontFamily: fonts.bodySemi,
    color: colors.black,
    fontSize: 14,
  },
  badge: {
    fontFamily: fonts.bodySemi,
    color: colors.black,
    backgroundColor: colors.lime,
    borderRadius: 999,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    overflow: "hidden",
    fontSize: 11,
  },
  guardrailWrap: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.xs,
  },
  guardrail: {
    fontFamily: fonts.bodySemi,
    borderRadius: 999,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    overflow: "hidden",
    fontSize: 11,
  },
  guardrailDefault: {
    backgroundColor: colors.gray100,
    color: colors.gray700,
  },
  guardrailWarning: {
    backgroundColor: colors.amber50,
    color: colors.amber700,
  },
  guardrailBreach: {
    backgroundColor: colors.red50,
    color: colors.red600,
  },
  actionButton: {
    marginTop: spacing.sm,
    alignSelf: "flex-start",
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  actionText: {
    color: colors.white,
    fontFamily: fonts.bodySemi,
    fontSize: 13,
  },
})
