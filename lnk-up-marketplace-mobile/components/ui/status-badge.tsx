import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { fonts, radii, spacing, statusToneColors, type StatusTone } from "@/lib/theme"

/** Colored status pill. Pass a tone from the domain helpers (e.g. organizerRequestStatusTone). */
export function StatusBadge({ label, tone = "neutral" }: { label: string; tone?: StatusTone }) {
  const palette = statusToneColors[tone]
  return (
    <View
      style={[styles.badge, { backgroundColor: palette.bg, borderColor: palette.border }]}
      accessibilityLabel={`Status: ${label}`}
    >
      <View style={[styles.dot, { backgroundColor: palette.text }]} />
      <Text style={[styles.text, { color: palette.text }]} numberOfLines={1}>
        {label}
      </Text>
    </View>
  )
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: "flex-start",
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: spacing.sm,
    paddingVertical: 3,
    borderRadius: radii.pill,
    borderWidth: 1,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  text: {
    fontFamily: fonts.bodySemi,
    fontSize: 11,
    letterSpacing: 0.2,
  },
})
