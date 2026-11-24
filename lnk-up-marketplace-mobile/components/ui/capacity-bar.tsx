import { StyleSheet, View, type ViewStyle } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import type { EventCapacity } from "@/lib/api/organizer-portfolio"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

/** Confirmed / held / open booth spots, matching the web workspace capacity bar. */
export function CapacityBar({ capacity }: { capacity: EventCapacity }) {
  const { total, confirmed, held, open } = capacity
  const summary =
    total > 0
      ? `${confirmed} confirmed, ${held} held, ${open} open of ${total} spots`
      : `${confirmed} confirmed, ${held} held · no spot limit set`

  return (
    <View accessible accessibilityLabel={`Capacity: ${summary}`} style={styles.wrap}>
      {total > 0 ? (
        <View style={styles.track}>
          <View style={[styles.segment, styles.confirmed, { flex: confirmed }]} />
          <View style={[styles.segment, styles.held, { flex: held }]} />
          <View style={[styles.segment, { flex: Math.max(open, 0) }]} />
        </View>
      ) : null}
      <View style={styles.legend}>
        <Legend swatch={styles.confirmed} label={`${confirmed} confirmed`} />
        <Legend swatch={styles.held} label={`${held} held`} />
        <Legend swatch={styles.openSwatch} label={total > 0 ? `${open} open of ${total}` : "No spot limit"} />
      </View>
    </View>
  )
}

function Legend({ swatch, label }: { swatch: ViewStyle; label: string }) {
  return (
    <View style={styles.legendItem}>
      <View style={[styles.swatch, swatch]} />
      <Text style={styles.legendText}>{label}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.sm },
  track: {
    flexDirection: "row",
    height: 8,
    borderRadius: radii.pill,
    overflow: "hidden",
    backgroundColor: colors.gray100,
  },
  segment: { height: "100%" },
  confirmed: { backgroundColor: colors.black },
  held: { backgroundColor: colors.lime },
  openSwatch: { backgroundColor: colors.gray200 },
  legend: { flexDirection: "row", flexWrap: "wrap", gap: spacing.md },
  legendItem: { flexDirection: "row", alignItems: "center", gap: 6 },
  swatch: { width: 10, height: 10, borderRadius: 3 },
  legendText: { fontFamily: fonts.body, fontSize: typeScale.caption, color: colors.gray700 },
})
