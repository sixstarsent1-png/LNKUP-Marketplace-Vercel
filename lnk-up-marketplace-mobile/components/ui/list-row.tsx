import type { ReactNode } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { colors, layout, radii, spacing, typography } from "@/lib/theme"

type ListRowProps = {
  title: string
  meta?: string
  icon?: AppIconName
  iconTone?: "default" | "accent" | "danger"
  rightSlot?: ReactNode
  showChevron?: boolean
  onPress?: () => void
  accessibilityLabel?: string
  accessibilityHint?: string
  destructive?: boolean
}

/** Single tappable (or static) row with optional leading icon, meta line, and trailing slot. */
export function ListRow({
  title,
  meta,
  icon,
  iconTone = "default",
  rightSlot,
  showChevron,
  onPress,
  accessibilityLabel,
  accessibilityHint,
  destructive = false,
}: ListRowProps) {
  const chevron = showChevron ?? Boolean(onPress)
  const content = (
    <>
      {icon ? (
        <View
          style={[
            styles.iconWrap,
            iconTone === "accent" && styles.iconWrapAccent,
            iconTone === "danger" && styles.iconWrapDanger,
          ]}
        >
          <AppIcon
            name={icon}
            size={18}
            color={iconTone === "danger" ? colors.red600 : colors.black}
          />
        </View>
      ) : null}
      <View style={styles.text}>
        <Text style={[styles.title, destructive && styles.titleDestructive]} numberOfLines={2}>
          {title}
        </Text>
        {meta ? (
          <Text style={styles.meta} numberOfLines={2}>
            {meta}
          </Text>
        ) : null}
      </View>
      {rightSlot}
      {chevron ? <AppIcon name="chevron-forward" size={18} color={colors.gray500} /> : null}
    </>
  )

  if (!onPress) {
    return <View style={styles.row}>{content}</View>
  }

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? (meta ? `${title}. ${meta}` : title)}
      accessibilityHint={accessibilityHint}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      {content}
    </Pressable>
  )
}

/** Card container that groups ListRows with hairline dividers. */
export function ListGroup({ title, children }: { title?: string; children: ReactNode }) {
  const items = (Array.isArray(children) ? children : [children]).filter(Boolean)
  return (
    <View style={styles.group}>
      {title ? <Text style={styles.groupTitle}>{title}</Text> : null}
      <View style={styles.groupCard}>
        {items.map((child, index) => (
          <View key={index} style={index > 0 ? styles.divider : undefined}>
            {child}
          </View>
        ))}
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 8,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  iconWrap: {
    width: 36,
    height: 36,
    borderRadius: radii.md,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  iconWrapAccent: {
    backgroundColor: colors.limeMuted,
  },
  iconWrapDanger: {
    backgroundColor: colors.red50,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    ...typography.label,
    fontSize: 15,
  },
  titleDestructive: {
    color: colors.red600,
  },
  meta: {
    ...typography.meta,
  },
  group: {
    gap: spacing.sm,
  },
  groupTitle: {
    ...typography.eyebrow,
    paddingHorizontal: spacing.xs,
  },
  groupCard: {
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    overflow: "hidden",
    backgroundColor: colors.white,
  },
  divider: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.gray200,
  },
})
