import { useReducedMotion } from "react-native-reanimated"
import type { ReactNode } from "react"
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  View,
  type PressableProps,
  type StyleProp,
  type ViewStyle,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { colors, fonts, fontScaleCaps, layout, radii, spacing, typeScale } from "@/lib/theme"

export type ButtonVariant = "primary" | "secondary" | "accent" | "danger" | "ghost"
export type ButtonSize = "md" | "sm"

type ButtonProps = Omit<PressableProps, "style" | "children"> & {
  label: string
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
  loadingLabel?: string
  icon?: AppIconName
  fullWidth?: boolean
  style?: StyleProp<ViewStyle>
  rightSlot?: ReactNode
}

const variantStyles: Record<ButtonVariant, { container: ViewStyle; text: string; spinner: string }> = {
  primary: {
    container: { backgroundColor: colors.black, borderColor: colors.black },
    text: colors.white,
    spinner: colors.white,
  },
  secondary: {
    container: { backgroundColor: colors.white, borderColor: colors.gray200 },
    text: colors.black,
    spinner: colors.black,
  },
  accent: {
    container: { backgroundColor: colors.lime, borderColor: colors.lime },
    text: colors.black,
    spinner: colors.black,
  },
  danger: {
    container: { backgroundColor: colors.white, borderColor: colors.red200 },
    text: colors.red600,
    spinner: colors.red600,
  },
  ghost: {
    container: { backgroundColor: "transparent", borderColor: "transparent" },
    text: colors.black,
    spinner: colors.black,
  },
}

/** Shared tappable button with 44pt minimum height, pressed feedback, and loading state. */
export function Button({
  label,
  variant = "primary",
  size = "md",
  loading = false,
  loadingLabel,
  icon,
  fullWidth = false,
  disabled,
  style,
  rightSlot,
  accessibilityLabel,
  ...rest
}: ButtonProps) {
  const tone = variantStyles[variant]
  const isDisabled = Boolean(disabled) || loading
  const reduceMotion = useReducedMotion()

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      disabled={isDisabled}
      hitSlop={size === "sm" ? 6 : undefined}
      style={({ pressed }) => [
        styles.base,
        size === "sm" ? styles.sm : styles.md,
        tone.container,
        fullWidth && styles.fullWidth,
        pressed && !isDisabled && (reduceMotion ? styles.pressedStill : styles.pressed),
        isDisabled && styles.disabled,
        style,
      ]}
      {...rest}
    >
      <View style={styles.content}>
        {loading ? (
          <ActivityIndicator size="small" color={tone.spinner} />
        ) : icon ? (
          <AppIcon name={icon} size={size === "sm" ? 16 : 18} color={tone.text} />
        ) : null}
        <Text
          style={[styles.label, size === "sm" && styles.labelSm, { color: tone.text }]}
          numberOfLines={1}
          maxFontSizeMultiplier={fontScaleCaps.control}
        >
          {loading && loadingLabel ? loadingLabel : label}
        </Text>
        {rightSlot}
      </View>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  base: {
    borderRadius: radii.md,
    borderWidth: 1,
    alignItems: "center",
    justifyContent: "center",
  },
  md: {
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm + 2,
  },
  sm: {
    minHeight: 36,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs + 2,
  },
  fullWidth: {
    alignSelf: "stretch",
  },
  content: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.sm,
  },
  label: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.callout,
  },
  labelSm: {
    fontSize: typeScale.footnote,
  },
  pressed: {
    opacity: 0.82,
    transform: [{ scale: 0.98 }],
  },
  pressedStill: {
    opacity: 0.82,
  },
  disabled: {
    opacity: 0.5,
  },
})
