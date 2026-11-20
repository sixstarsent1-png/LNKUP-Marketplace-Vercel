import { StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, radii, spacing } from "@/lib/theme"

/** "Step 2 of 6 · Date and location" with a segmented bar; announced as a progress bar. */
export function StepProgress({ steps, current }: { steps: readonly string[]; current: number }) {
  const total = steps.length
  const index = Math.min(Math.max(current, 0), total - 1)
  return (
    <View
      style={styles.wrap}
      accessibilityRole="progressbar"
      accessibilityLabel={`Step ${index + 1} of ${total}: ${steps[index]}`}
      accessibilityValue={{ min: 1, max: total, now: index + 1 }}
    >
      <Text style={styles.label}>
        Step {index + 1} of {total} · <Text style={styles.labelStrong}>{steps[index]}</Text>
      </Text>
      <View style={styles.bar}>
        {steps.map((step, i) => (
          <View key={step} style={[styles.segment, i <= index && styles.segmentDone]} />
        ))}
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.sm },
  label: { fontFamily: fonts.body, fontSize: 13, color: colors.gray500 },
  labelStrong: { fontFamily: fonts.bodySemi, color: colors.black },
  bar: { flexDirection: "row", gap: 4 },
  segment: { flex: 1, height: 4, borderRadius: radii.pill, backgroundColor: colors.gray200 },
  segmentDone: { backgroundColor: colors.black },
})
