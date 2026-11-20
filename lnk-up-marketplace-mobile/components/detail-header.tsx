import type { ReactNode } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon } from "@/components/ui/app-icon"
import { colors, fonts, layout, spacing } from "@/lib/theme"

/** Top bar for pushed detail screens: labeled back button, centered title, optional trailing slot. */
export function DetailHeader({
  title,
  onBack,
  backLabel = "Back",
  rightSlot,
}: {
  title: string
  onBack: () => void
  backLabel?: string
  rightSlot?: ReactNode
}) {
  return (
    <View style={styles.header}>
      <Pressable
        onPress={onBack}
        accessibilityRole="button"
        accessibilityLabel={backLabel}
        hitSlop={8}
        style={({ pressed }) => [styles.back, pressed && styles.pressed]}
      >
        <AppIcon name="chevron-back" size={22} color={colors.black} />
        <Text style={styles.backText}>Back</Text>
      </Pressable>
      <Text style={styles.title} numberOfLines={1} accessibilityRole="header">
        {title}
      </Text>
      <View style={styles.side}>{rightSlot}</View>
    </View>
  )
}

const styles = StyleSheet.create({
  header: {
    flexDirection: "row",
    alignItems: "center",
    minHeight: layout.hitTargetMin + 8,
    paddingHorizontal: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
    backgroundColor: colors.white,
  },
  back: {
    flexDirection: "row",
    alignItems: "center",
    minWidth: 80,
    minHeight: layout.hitTargetMin,
    gap: 2,
  },
  pressed: {
    opacity: 0.6,
  },
  backText: {
    fontFamily: fonts.bodySemi,
    fontSize: 15,
    color: colors.black,
  },
  title: {
    flex: 1,
    textAlign: "center",
    fontFamily: fonts.displaySemi,
    fontSize: 16,
    color: colors.black,
  },
  side: {
    minWidth: 80,
    alignItems: "flex-end",
  },
})
