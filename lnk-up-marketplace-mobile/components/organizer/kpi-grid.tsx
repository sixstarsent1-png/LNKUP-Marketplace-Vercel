import { StyleSheet, View } from "react-native"

import type { AppIconName } from "@/components/ui/app-icon"
import { StatTile } from "@/components/ui/stat-tile"
import { spacing } from "@/lib/theme"

export type KpiItem = {
  key: string
  label: string
  value: string | number
  /** Scope of the figure: "all time", "upcoming events", "current". Always shown. */
  scope: string
  icon?: AppIconName
  tone?: "default" | "accent" | "urgent" | "dark"
  onPress?: () => void
}

/** Business KPIs in two columns, each with the scope it covers so figures are never ambiguous. */
export function KpiGrid({ items }: { items: KpiItem[] }) {
  return (
    <View style={styles.grid}>
      {items.map((item) => (
        <View key={item.key} style={styles.cell}>
          <StatTile
            label={item.label}
            value={item.value}
            hint={item.scope}
            icon={item.icon}
            tone={item.tone}
            onPress={item.onPress}
            accessibilityLabel={`${item.label}: ${item.value}, ${item.scope}`}
          />
        </View>
      ))}
    </View>
  )
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  cell: {
    flexGrow: 1,
    flexBasis: "45%",
    flexDirection: "row",
  },
})
