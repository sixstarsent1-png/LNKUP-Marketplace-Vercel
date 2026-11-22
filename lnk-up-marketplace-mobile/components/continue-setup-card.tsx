import { useCallback, useState } from "react"
import { Linking, Pressable, StyleSheet, View } from "react-native"
import { router, useFocusEffect } from "expo-router"

import { AppText as Text } from "@/components/ui/app-text"
import { fetchOrganizerOnboarding, nativeRouteForStep, type OrganizerOnboarding } from "@/lib/api/organizer-onboarding"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

/**
 * "Continue setup" for organizers with unfinished guided setup. Progress lives in WordPress, so
 * setup started on the web continues here (and the other way round). Hidden when setup is done,
 * dismissed, or guided setup is off.
 */
export function ContinueSetupCard() {
  const { auth } = useAuth()
  const [flow, setFlow] = useState<OrganizerOnboarding | null>(null)

  useFocusEffect(
    useCallback(() => {
      let active = true
      void fetchOrganizerOnboarding(auth).then((data) => {
        if (active) setFlow(data)
      })
      return () => {
        active = false
      }
    }, [auth]),
  )

  const handlePress = useCallback(() => {
    if (!flow?.nextStep) return
    const route = nativeRouteForStep(flow.nextStep.key, flow.formats)
    if (route) {
      router.push(route)
      return
    }
    void Linking.openURL(flow.nextStep.url || flow.resumeUrl)
  }, [flow])

  if (!flow || flow.isComplete || flow.dismissed || !flow.nextStep) return null

  const opensWeb = nativeRouteForStep(flow.nextStep.key, flow.formats) === null

  return (
    <View style={styles.card} testID="continue-setup-card">
      <Text style={styles.eyebrow}>{`Setup ${flow.percent}% complete`}</Text>
      <Text style={styles.heading} accessibilityRole="header">
        {flow.nextStep.title}
      </Text>
      {flow.nextStep.missing.length > 0 ? (
        <Text style={styles.meta}>{`Still needed: ${flow.nextStep.missing.join(", ")}`}</Text>
      ) : null}
      <View style={styles.bar} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: flow.percent }}>
        <View style={[styles.barFill, { width: `${Math.max(4, flow.percent)}%` }]} />
      </View>
      <Pressable
        style={styles.button}
        onPress={handlePress}
        accessibilityRole="button"
        accessibilityHint={opensWeb ? "Opens LNKup on the web" : undefined}
      >
        <Text style={styles.buttonText}>{opensWeb ? "Continue on LNKup web" : "Continue setup"}</Text>
      </Pressable>
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.sm,
    padding: spacing.lg,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
  },
  eyebrow: {
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
  heading: {
    fontSize: typeScale.bodyLarge,
    color: colors.black,
  },
  meta: {
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
  bar: {
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.gray200,
    overflow: "hidden",
  },
  barFill: {
    height: 6,
    backgroundColor: colors.lime,
  },
  button: {
    alignSelf: "flex-start",
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radii.md,
    backgroundColor: colors.black,
  },
  buttonText: {
    color: colors.white,
    fontSize: typeScale.body,
  },
})
