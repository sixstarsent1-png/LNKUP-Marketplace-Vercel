import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { SectionTitle } from "@/components/ui/typography"
import { colors, fonts, layout, spacing, typography, typeScale } from "@/lib/theme"

type SectionHeaderProps = {
  title: string
  subtitle?: string
  icon?: AppIconName
  actionLabel?: string
  onAction?: () => void
}

/** Section title with an optional trailing text action ("See all") sized to a 44pt target. */
export function SectionHeader({ title, subtitle, icon, actionLabel, onAction }: SectionHeaderProps) {
  return (
    <View style={styles.row}>
      <View style={styles.text}>
        <View style={styles.titleRow}>
          {icon ? <AppIcon name={icon} size={18} color={colors.black} /> : null}
          <SectionTitle accessibilityRole="header" style={styles.title}>
            {title}
          </SectionTitle>
        </View>
        {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
      </View>
      {actionLabel && onAction ? (
        <Pressable
          onPress={onAction}
          accessibilityRole="button"
          accessibilityLabel={`${actionLabel}: ${title}`}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
          style={({ pressed }) => [styles.action, pressed && styles.pressed]}
        >
          <Text style={styles.actionText}>{actionLabel}</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  titleRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  title: {
    flexShrink: 1,
  },
  subtitle: {
    ...typography.meta,
  },
  action: {
    minHeight: layout.hitTargetMin - 12,
    justifyContent: "center",
    paddingHorizontal: spacing.xs,
  },
  actionText: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.body,
    color: colors.black,
    textDecorationLine: "underline",
  },
  pressed: {
    opacity: 0.6,
  },
})
