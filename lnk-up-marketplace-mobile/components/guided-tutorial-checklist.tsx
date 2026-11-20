import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { Surface } from "@/components/ui/surface"
import { Body, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import type { RoleTutorial, TutorialStepId } from "@/lib/tutorials/tutorial-contract"
import { colors, radii, spacing } from "@/lib/theme"

export function GuidedTutorialChecklist({
  tutorial,
  onStepClick,
  onDismiss,
  onPushClick,
  testID,
}: {
  tutorial: RoleTutorial
  onStepClick: (stepId: TutorialStepId) => void
  onDismiss?: () => void
  onPushClick?: () => void
  testID: string
}) {
  const pct = Math.round((tutorial.completedCount / tutorial.totalCount) * 100)

  return (
    <Surface accentBorderTop style={styles.card} testID={testID}>
      <View style={styles.header}>
        <View style={styles.headerCopy}>
          <SectionTitle>{tutorial.title}</SectionTitle>
          <Body>{tutorial.subtitle}</Body>
        </View>
        <View style={styles.headerMeta}>
          <Eyebrow>
            {tutorial.completedCount} of {tutorial.totalCount}
          </Eyebrow>
          {onDismiss ? (
            <Pressable
              onPress={onDismiss}
              hitSlop={8}
              accessibilityRole="button"
              accessibilityLabel="Dismiss tutorial"
            >
              <Meta>Dismiss</Meta>
            </Pressable>
          ) : null}
        </View>
      </View>

      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, { width: `${pct}%` }]} />
      </View>

      {tutorial.steps.map((step) => (
        <View
          key={step.id}
          style={[styles.step, step.done && styles.stepDone]}
          testID={`${testID}-step-${step.id}`}
        >
          <View style={styles.stepBody}>
            {step.done ? (
              <View style={styles.checkWrap}>
                <AppIcon name="checkmark" size={16} color={colors.black} />
              </View>
            ) : (
              <View style={styles.iconWrap}>
                <AppIcon name={step.icon ?? "ellipse-outline"} size={18} color={colors.black} />
              </View>
            )}
            <View style={styles.stepCopy}>
              <SectionTitle style={styles.stepLabel}>{step.label}</SectionTitle>
              <Body style={styles.stepDescription}>{step.description}</Body>
            </View>
          </View>
          {!step.done ? (
            <Pressable
              style={styles.cta}
              onPress={() => {
                onStepClick(step.id)
                router.push(step.href as Href)
              }}
              accessibilityRole="button"
              testID={`${testID}-cta-${step.id}`}
            >
              <SectionTitle style={styles.ctaText}>{step.ctaLabel}</SectionTitle>
            </Pressable>
          ) : null}
        </View>
      ))}

      <Pressable
        style={styles.pushCta}
        onPress={() => {
          onPushClick?.()
          router.push(tutorial.pushHref as Href)
        }}
        accessibilityRole="button"
        testID={`${testID}-push-cta`}
      >
        <SectionTitle style={styles.pushCtaText}>Enable push alerts</SectionTitle>
        <Meta>Stay on top of applications and payments</Meta>
      </Pressable>
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.md,
  },
  header: {
    flexDirection: "row",
    justifyContent: "space-between",
    gap: spacing.md,
  },
  headerCopy: {
    flex: 1,
    gap: 4,
  },
  headerMeta: {
    alignItems: "flex-end",
    gap: 4,
  },
  progressTrack: {
    height: 6,
    borderRadius: 999,
    backgroundColor: colors.gray100,
    overflow: "hidden",
  },
  progressFill: {
    height: 6,
    backgroundColor: colors.lime,
  },
  step: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: spacing.sm,
    backgroundColor: colors.white,
  },
  stepDone: {
    backgroundColor: colors.gray50,
    borderColor: colors.limeMuted,
  },
  stepBody: {
    flexDirection: "row",
    gap: spacing.sm,
    alignItems: "flex-start",
  },
  iconWrap: {
    width: 28,
    height: 28,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.gray100,
  },
  checkWrap: {
    width: 28,
    height: 28,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.lime,
  },
  stepCopy: {
    flex: 1,
    gap: 2,
  },
  stepLabel: {
    fontSize: 14,
  },
  stepDescription: {
    fontSize: 12,
    lineHeight: 16,
  },
  cta: {
    alignSelf: "flex-start",
    backgroundColor: colors.lime,
    borderRadius: radii.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  ctaText: {
    fontSize: 13,
  },
  pushCta: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    backgroundColor: colors.gray50,
    gap: 2,
  },
  pushCtaText: {
    fontSize: 14,
  },
})
