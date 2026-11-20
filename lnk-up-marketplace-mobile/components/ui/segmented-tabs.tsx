import { Pressable, ScrollView, StyleSheet, View } from "react-native"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, fontScaleCaps, layout, radii, spacing } from "@/lib/theme"

export type SegmentedTab<T extends string> = {
  value: T
  label: string
  icon?: AppIconName
  count?: number
  /** Highlight the count (something needs action). */
  attention?: boolean
}

/**
 * Horizontally scrollable tab strip for workspaces with more sections than fit a phone width
 * (use SegmentedControl for 2–3 equal options). Underline marks the active tab.
 */
export function SegmentedTabs<T extends string>({
  tabs,
  value,
  onChange,
  testID,
}: {
  tabs: ReadonlyArray<SegmentedTab<T>>
  value: T
  onChange: (value: T) => void
  testID?: string
}) {
  return (
    <View style={styles.wrap} testID={testID}>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.row}
        accessibilityRole="tablist"
      >
        {tabs.map((tab) => {
          const selected = tab.value === value
          return (
            <Pressable
              key={tab.value}
              onPress={() => onChange(tab.value)}
              accessibilityRole="tab"
              accessibilityState={{ selected }}
              accessibilityLabel={tab.count ? `${tab.label}, ${tab.count}` : tab.label}
              style={({ pressed }) => [styles.tab, selected && styles.tabSelected, pressed && styles.pressed]}
            >
              {tab.icon ? (
                <AppIcon name={tab.icon} size={16} color={selected ? colors.black : colors.gray500} />
              ) : null}
              <Text
                style={[styles.label, selected && styles.labelSelected]}
                maxFontSizeMultiplier={fontScaleCaps.control}
              >
                {tab.label}
              </Text>
              {tab.count ? (
                <View style={[styles.count, tab.attention && styles.countAttention]}>
                  <Text style={styles.countText} maxFontSizeMultiplier={fontScaleCaps.tab}>
                    {tab.count}
                  </Text>
                </View>
              ) : null}
            </Pressable>
          )
        })}
      </ScrollView>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { borderBottomWidth: 1, borderBottomColor: colors.gray200 },
  row: { gap: spacing.xs, paddingRight: spacing.lg },
  tab: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.md,
    borderBottomWidth: 2,
    borderBottomColor: "transparent",
  },
  tabSelected: { borderBottomColor: colors.black },
  pressed: { opacity: 0.7 },
  label: { fontFamily: fonts.bodySemi, fontSize: 14, color: colors.gray500 },
  labelSelected: { color: colors.black },
  count: {
    minWidth: 20,
    height: 20,
    paddingHorizontal: 6,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  countAttention: { backgroundColor: colors.lime },
  countText: { fontFamily: fonts.bodyBold, fontSize: 11, color: colors.black },
})
