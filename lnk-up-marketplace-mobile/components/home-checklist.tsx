import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import type { VendorReadiness, VendorReadinessMissingItem } from "@/lib/api/vendor-readiness"
import { colors, fonts, layout, radii, spacing, typography } from "@/lib/theme"

const DEFAULT_STEPS: VendorReadinessMissingItem[] = [
  {
    id: "profile",
    label: "Complete your vendor profile",
    actionHref: "/(vendor)/profile",
    actionLabel: "Profile",
  },
  {
    id: "documents",
    label: "Upload required documents",
    actionHref: "/(vendor)/documents",
    actionLabel: "Documents",
  },
  {
    id: "apply",
    label: "Apply to your first event",
    actionHref: "/(vendor)/find",
    actionLabel: "Find",
  },
]

function resolveHref(href?: string): Href {
  if (!href) return "/(vendor)/profile"
  if (href.startsWith("/")) return href as Href
  return "/(vendor)/profile"
}

export function HomeChecklist({ readiness }: { readiness: VendorReadiness }) {
  const fromApi = readiness.missingItems.slice(0, 3)
  const steps = fromApi.length > 0 ? fromApi : DEFAULT_STEPS
  const pct = Math.max(0, Math.min(100, Math.round(readiness.profileCompletion)))

  return (
    <Surface style={styles.card}>
      <SectionTitle accessibilityRole="header">Getting started</SectionTitle>
      <Meta>{pct}% complete — finish these to get approved faster.</Meta>
      <View
        style={styles.progressTrack}
        accessibilityRole="progressbar"
        accessibilityValue={{ min: 0, max: 100, now: pct }}
      >
        <View style={[styles.progressFill, { width: `${pct}%` }]} />
      </View>
      <View style={styles.list}>
        {steps.map((step, index) => (
          <Pressable
            key={step.id || `step-${index}`}
            style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
            onPress={() => router.push(resolveHref(step.actionHref))}
            accessibilityRole="button"
            accessibilityLabel={`Step ${index + 1}: ${step.label}`}
          >
            <View style={styles.bullet}>
              <Text style={styles.bulletText}>{index + 1}</Text>
            </View>
            <Text style={styles.label} numberOfLines={2}>
              {step.label}
              {step.documentUrgency === "expired"
                ? " (expired)"
                : step.documentUrgency === "expiring_soon"
                  ? " (expiring soon)"
                  : ""}
            </Text>
            <Text style={styles.action}>{step.actionLabel ?? "Go"}</Text>
            <AppIcon name="chevron-forward" size={16} color={colors.gray500} />
          </Pressable>
        ))}
      </View>
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.sm,
  },
  progressTrack: {
    height: 6,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    overflow: "hidden",
    marginBottom: spacing.xs,
  },
  progressFill: {
    height: "100%",
    borderRadius: radii.pill,
    backgroundColor: colors.lime,
  },
  list: {
    gap: 0,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin,
    paddingVertical: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.gray100,
  },
  rowPressed: {
    opacity: 0.7,
  },
  bullet: {
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
  },
  bulletText: {
    fontSize: 12,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
  label: {
    ...typography.label,
    flex: 1,
  },
  action: {
    fontSize: 13,
    fontFamily: fonts.bodySemi,
    color: colors.gray500,
  },
})
