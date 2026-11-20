import type { ReactNode } from "react"
import { StyleSheet, View } from "react-native"
import { useSafeAreaInsets } from "react-native-safe-area-context"

import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, layout, shadow, spacing } from "@/lib/theme"

/**
 * Bottom-pinned primary action area for detail and form screens. Clears the home indicator;
 * inside tab screens pass `insideTabs` (the tab bar already handles the inset).
 */
export function StickyActionBar({
  children,
  caption,
  insideTabs = false,
}: {
  children: ReactNode
  caption?: string
  insideTabs?: boolean
}) {
  const insets = useSafeAreaInsets()
  return (
    <View style={[styles.bar, { paddingBottom: insideTabs ? spacing.md : Math.max(insets.bottom, spacing.md) }]}>
      {caption ? (
        <Text style={styles.caption} numberOfLines={2}>
          {caption}
        </Text>
      ) : null}
      <View style={[styles.row, { maxWidth: layout.contentMaxWidth }]}>{children}</View>
    </View>
  )
}

const styles = StyleSheet.create({
  bar: {
    backgroundColor: colors.white,
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    gap: spacing.sm,
    alignItems: "center",
    ...shadow("sm"),
  },
  row: { width: "100%", flexDirection: "row", gap: spacing.sm },
  caption: { fontFamily: fonts.bodyMedium, fontSize: 13, color: colors.gray700, textAlign: "center" },
})
