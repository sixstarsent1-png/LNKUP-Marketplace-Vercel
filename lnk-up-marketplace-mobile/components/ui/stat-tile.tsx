import type { ReactNode } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { colors, fonts, radii, spacing, typography } from "@/lib/theme"

type StatTileProps = {
  label: string
  value: string | number
  hint?: string
  icon?: AppIconName
  tone?: "default" | "accent" | "urgent" | "dark"
  onPress?: () => void
  accessibilityLabel?: string
}

const iconColor = {
  default: colors.gray700,
  accent: colors.black,
  urgent: colors.amber700,
  dark: colors.lime,
} as const

/** KPI tile used on Home, Wallet, Money and event detail. Lay out several inside a StatGrid. */
export function StatTile({
  label,
  value,
  hint,
  icon,
  tone = "default",
  onPress,
  accessibilityLabel,
}: StatTileProps) {
  const body = (
    <>
      {icon ? <AppIcon name={icon} size={16} color={iconColor[tone]} /> : null}
      <Text style={[styles.value, tone === "dark" && styles.valueDark]} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      <Text style={[styles.label, tone === "dark" && styles.labelDark]} numberOfLines={2}>
        {label}
      </Text>
      {hint ? (
        <Text style={[styles.hint, tone === "dark" && styles.labelDark]} numberOfLines={1}>
          {hint}
        </Text>
      ) : null}
    </>
  )

  const toneStyle = [
    styles.tile,
    tone === "accent" && styles.tileAccent,
    tone === "urgent" && styles.tileUrgent,
    tone === "dark" && styles.tileDark,
  ]

  if (!onPress) {
    return (
      <View style={toneStyle} accessibilityLabel={accessibilityLabel ?? `${label}: ${value}`}>
        {body}
      </View>
    )
  }

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? `${label}: ${value}`}
      style={({ pressed }) => [...toneStyle, pressed && styles.pressed]}
    >
      {body}
    </Pressable>
  )
}

/** Wrapping row of StatTiles with consistent gaps. */
export function StatGrid({ children }: { children: ReactNode }) {
  return <View style={styles.grid}>{children}</View>
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  tile: {
    flexGrow: 1,
    flexBasis: "30%",
    minWidth: 96,
    minHeight: 80,
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
    gap: 2,
    justifyContent: "center",
  },
  tileAccent: {
    backgroundColor: colors.limeMuted,
    borderColor: colors.lime,
  },
  tileUrgent: {
    backgroundColor: colors.amber50,
    borderColor: colors.amber200,
  },
  tileDark: {
    backgroundColor: colors.black,
    borderColor: colors.black,
  },
  value: {
    fontFamily: fonts.display,
    fontSize: 22,
    color: colors.black,
  },
  valueDark: {
    color: colors.lime,
  },
  label: {
    ...typography.caption,
    fontSize: 12,
    color: colors.gray700,
  },
  labelDark: {
    color: colors.gray200,
  },
  hint: {
    ...typography.caption,
  },
  pressed: {
    opacity: 0.85,
    transform: [{ scale: 0.98 }],
  },
})
