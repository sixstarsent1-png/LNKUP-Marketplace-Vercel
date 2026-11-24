import { StyleSheet, View } from "react-native"

import { colors, radii } from "@/lib/theme"

/** Thin determinate progress bar (0–100). */
export function ProgressBar({ percent, label }: { percent: number; label: string }) {
  const clamped = Math.max(0, Math.min(100, Math.round(percent)))
  return (
    <View
      style={styles.track}
      accessibilityRole="progressbar"
      accessibilityLabel={label}
      accessibilityValue={{ min: 0, max: 100, now: clamped }}
    >
      <View style={[styles.fill, { width: `${clamped}%` }]} />
    </View>
  )
}

const styles = StyleSheet.create({
  track: {
    height: 8,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    overflow: "hidden",
  },
  fill: {
    height: "100%",
    borderRadius: radii.pill,
    backgroundColor: colors.lime,
  },
})
