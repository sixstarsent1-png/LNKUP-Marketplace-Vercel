import { useEffect, useMemo, useState } from "react"
import { Modal, Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { Body, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import type { RoleTutorial, TutorialStepId } from "@/lib/tutorials/tutorial-contract"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

type TutorialPopupModalProps = {
  visible: boolean
  tutorial: RoleTutorial
  onCta: (stepId: TutorialStepId) => void
  onFinish: () => void
  onSkipAll: () => void
  testID: string
}

export function TutorialPopupModal({
  visible,
  tutorial,
  onCta,
  onFinish,
  onSkipAll,
  testID,
}: TutorialPopupModalProps) {
  const incomplete = useMemo(
    () => tutorial.steps.filter((step) => !step.done),
    [tutorial.steps],
  )
  const [index, setIndex] = useState(0)

  useEffect(() => {
    if (visible) setIndex(0)
  }, [visible])

  useEffect(() => {
    if (index >= incomplete.length && incomplete.length > 0) {
      setIndex(incomplete.length - 1)
    }
  }, [index, incomplete.length])

  if (!visible || incomplete.length === 0) return null

  const step = incomplete[Math.min(index, incomplete.length - 1)]
  const stepNumber = index + 1
  const isLast = index >= incomplete.length - 1

  function handleNext() {
    if (isLast) {
      onFinish()
      return
    }
    setIndex((current) => current + 1)
  }

  function handleCta() {
    onCta(step.id)
    router.push(step.href as Href)
    if (isLast) {
      onFinish()
    } else {
      setIndex((current) => current + 1)
    }
  }

  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      onRequestClose={onSkipAll}
      accessibilityViewIsModal
    >
      <View style={styles.backdrop} testID={testID}>
        <View
          style={[styles.card, constrainedColumnStyle(layout.sheetMaxWidth)]}
          accessibilityRole="summary"
          accessibilityLabel={`${step.label}. Step ${stepNumber} of ${incomplete.length}`}
        >
          <View style={styles.limeBar} />

          <View style={styles.header}>
            <Eyebrow>Step {stepNumber} of {incomplete.length}</Eyebrow>
            <Pressable
              onPress={onSkipAll}
              hitSlop={8}
              accessibilityRole="button"
              accessibilityLabel="Close tutorial"
              testID={`${testID}-skip`}
              style={styles.closeBtn}
            >
              <AppIcon name="close" size={20} color={colors.gray500} />
            </Pressable>
          </View>

          <View style={styles.dots} accessibilityElementsHidden>
            {incomplete.map((item, i) => (
              <View
                key={item.id}
                style={[
                  styles.dot,
                  i < index && styles.dotDone,
                  i === index && styles.dotCurrent,
                ]}
              />
            ))}
          </View>

          <View style={styles.headlineRow}>
            {step.icon ? (
              <View style={styles.iconWrap}>
                <AppIcon name={step.icon} size={22} color={colors.black} />
              </View>
            ) : null}
            <SectionTitle style={styles.headline}>{step.label}</SectionTitle>
          </View>
          <Body style={styles.body}>{step.description}</Body>

          <Pressable
            style={({ pressed }) => [styles.primaryCta, pressed && styles.pressed]}
            onPress={handleCta}
            accessibilityRole="button"
            accessibilityLabel={step.ctaLabel}
            testID={`${testID}-cta`}
          >
            <SectionTitle style={styles.primaryCtaText}>{step.ctaLabel}</SectionTitle>
          </Pressable>

          <Pressable
            style={({ pressed }) => [styles.secondaryCta, pressed && styles.pressed]}
            onPress={handleNext}
            accessibilityRole="button"
            accessibilityLabel={isLast ? "Finish tutorial tips" : "Next tip"}
            testID={`${testID}-next`}
          >
            <Body style={styles.secondaryCtaText}>{isLast ? "Done" : "Next"}</Body>
          </Pressable>

          {!isLast ? (
            <Pressable
              style={({ pressed }) => [styles.skipAll, pressed && styles.pressed]}
              onPress={onSkipAll}
              accessibilityRole="button"
              accessibilityLabel="Skip all tutorial tips"
              hitSlop={8}
              testID={`${testID}-skip-all`}
            >
              <Meta style={styles.skipAllText}>Skip all</Meta>
            </Pressable>
          ) : null}
        </View>
      </View>
    </Modal>
  )
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: colors.overlay,
    justifyContent: "center",
    paddingHorizontal: spacing.xl,
  },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.xl,
    padding: spacing.xl,
    gap: spacing.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    overflow: "hidden",
  },
  limeBar: {
    position: "absolute",
    top: 0,
    left: 0,
    right: 0,
    height: 4,
    backgroundColor: colors.lime,
  },
  header: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginTop: spacing.xs,
  },
  closeBtn: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    borderRadius: layout.hitTargetMin / 2,
    marginRight: -spacing.sm,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.gray50,
  },
  dots: {
    flexDirection: "row",
    gap: 6,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.gray200,
  },
  dotDone: {
    backgroundColor: colors.limeDark,
  },
  dotCurrent: {
    width: 18,
    backgroundColor: colors.lime,
  },
  headlineRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
  },
  headline: {
    flex: 1,
  },
  body: {
    marginTop: -4,
  },
  primaryCta: {
    backgroundColor: colors.lime,
    borderRadius: radii.md,
    minHeight: layout.hitTargetMin,
    paddingVertical: spacing.md,
    alignItems: "center",
    justifyContent: "center",
  },
  pressed: {
    opacity: 0.85,
  },
  primaryCtaText: {
    fontSize: typeScale.callout,
  },
  secondaryCta: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    minHeight: layout.hitTargetMin,
    paddingVertical: spacing.md,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  secondaryCtaText: {
    fontFamily: fonts.bodySemi,
    color: colors.black,
  },
  skipAll: {
    alignSelf: "center",
    paddingVertical: spacing.xs,
  },
  skipAllText: {
    fontFamily: fonts.bodySemi,
    color: colors.gray500,
    textDecorationLine: "underline",
  },
})
