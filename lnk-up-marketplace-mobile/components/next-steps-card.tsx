import { useCallback, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, useFocusEffect } from "expo-router"

import { AppText as Text } from "@/components/ui/app-text"
import { fetchNextSteps, type NextStep } from "@/lib/api/comms"
import { useAuth } from "@/lib/auth/auth-context"
import { safeDestination } from "@/lib/navigation/pending-destination"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

/**
 * "Your next steps" (vendor) / "Requires attention" (organizer), computed by the server from live
 * marketplace records. Hidden when there is nothing to do or the endpoint is unavailable.
 */
export function NextStepsCard({ role }: { role: "vendor" | "organizer" }) {
  const { auth, session } = useAuth()
  const [steps, setSteps] = useState<NextStep[]>([])

  useFocusEffect(
    useCallback(() => {
      let active = true
      void fetchNextSteps(role, auth).then((data) => {
        if (active) setSteps(data?.steps ?? [])
      })
      return () => {
        active = false
      }
    }, [auth, role]),
  )

  if (steps.length === 0) return null

  return (
    <View style={styles.card}>
      <Text style={styles.heading} accessibilityRole="header">
        {role === "organizer" ? "Requires attention" : "Your next steps"}
      </Text>
      {steps.slice(0, 5).map((step, index) => {
        const href = safeDestination(step.deepLink, session?.user.roles)
        return (
          <Pressable
            key={`${step.key}:${step.context ?? ""}:${index}`}
            style={({ pressed }) => [styles.row, pressed && styles.pressed]}
            onPress={() => {
              if (href) router.push(href as never)
            }}
            disabled={!href}
            accessibilityRole="button"
            accessibilityLabel={step.context ? `${step.title}, ${step.context}` : step.title}
          >
            <View style={[styles.marker, step.priority === "high" && styles.markerHigh]} />
            <View style={styles.text}>
              <Text style={styles.title}>{step.title}</Text>
              {step.context ? <Text style={styles.context}>{step.context}</Text> : null}
            </View>
            {href ? <Text style={styles.chevron}>›</Text> : null}
          </Pressable>
        )
      })}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  heading: {
    fontSize: typeScale.callout,
    fontWeight: "700",
    color: colors.black,
    marginBottom: spacing.xs,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: 48,
    paddingVertical: spacing.xs,
  },
  pressed: {
    opacity: 0.7,
  },
  marker: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.gray200,
  },
  markerHigh: {
    backgroundColor: colors.limeDark,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    fontSize: typeScale.body,
    fontWeight: "600",
    color: colors.black,
  },
  context: {
    fontSize: typeScale.footnote,
    color: colors.gray500,
  },
  chevron: {
    fontSize: typeScale.bodyLarge,
    color: colors.gray500,
  },
})
