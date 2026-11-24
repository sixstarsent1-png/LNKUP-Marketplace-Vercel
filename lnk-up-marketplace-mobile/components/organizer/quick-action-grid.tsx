import { Pressable, StyleSheet, View } from "react-native"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, fontScaleCaps, layout, radii, spacing, typeScale } from "@/lib/theme"

export type QuickAction = {
  key: string
  label: string
  icon: AppIconName
  badge?: number
  onPress: () => void
}

/** Up to four state-driven shortcuts. The caller decides which actions apply right now. */
export function QuickActionGrid({ actions }: { actions: QuickAction[] }) {
  if (!actions.length) return null
  return (
    <View style={styles.grid} accessibilityRole="toolbar">
      {actions.slice(0, 4).map((action) => (
        <Pressable
          key={action.key}
          onPress={action.onPress}
          accessibilityRole="button"
          accessibilityLabel={action.badge ? `${action.label}, ${action.badge} waiting` : action.label}
          testID={`org-quick-${action.key}`}
          style={({ pressed }) => [styles.tile, pressed && styles.pressed]}
        >
          <View style={styles.iconWrap}>
            <AppIcon name={action.icon} size={20} color={colors.black} />
            {action.badge ? (
              <View style={styles.badge}>
                <Text style={styles.badgeText} maxFontSizeMultiplier={fontScaleCaps.tab}>
                  {action.badge > 99 ? "99+" : action.badge}
                </Text>
              </View>
            ) : null}
          </View>
          <Text style={styles.label} numberOfLines={2} maxFontSizeMultiplier={fontScaleCaps.label}>
            {action.label}
          </Text>
        </Pressable>
      ))}
    </View>
  )
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  tile: {
    flex: 1,
    minHeight: layout.hitTargetMin + 40,
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.xs,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xs,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: radii.pill,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
  },
  badge: {
    position: "absolute",
    top: -4,
    right: -8,
    minWidth: 20,
    height: 20,
    borderRadius: radii.pill,
    paddingHorizontal: 5,
    backgroundColor: colors.black,
    alignItems: "center",
    justifyContent: "center",
  },
  badgeText: {
    fontFamily: fonts.bodyBold,
    fontSize: typeScale.nano,
    color: colors.lime,
  },
  label: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.caption,
    color: colors.black,
    textAlign: "center",
  },
})
