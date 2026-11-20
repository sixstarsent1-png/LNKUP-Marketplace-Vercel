import { Pressable, StyleSheet } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import type { Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { safeBack } from "@/lib/navigation/safe-back"
import { colors, fonts, layout, spacing, typeScale } from "@/lib/theme"

/**
 * Consistent back control for secondary screens inside `Screen`. Goes back when there is history
 * (normal push) and otherwise to `fallbackHref` (opened from a push or deep link on cold start).
 */
export function BackLink({ label, fallbackHref }: { label: string; fallbackHref: Href }) {
  return (
    <Pressable
      onPress={() => safeBack(fallbackHref)}
      accessibilityRole="button"
      accessibilityLabel={`Back to ${label}`}
      hitSlop={8}
      style={({ pressed }) => [styles.back, pressed && styles.pressed]}
    >
      <AppIcon name="chevron-back" size={20} color={colors.gray700} />
      <Text style={styles.text} maxFontSizeMultiplier={1.4}>
        {label}
      </Text>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  back: {
    flexDirection: "row",
    alignItems: "center",
    alignSelf: "flex-start",
    minHeight: layout.hitTargetMin,
    paddingRight: spacing.md,
    marginLeft: -spacing.xs,
    gap: 2,
  },
  pressed: { opacity: 0.6 },
  text: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.gray700 },
})
