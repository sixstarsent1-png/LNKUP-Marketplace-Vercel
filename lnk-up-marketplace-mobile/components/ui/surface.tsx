import type { ReactNode } from "react"
import { StyleSheet, View, type ViewProps, type ViewStyle } from "react-native"

import { colors, radii, shadow, spacing, surfaceStyle, type SurfaceVariant } from "@/lib/theme"

type SurfaceProps = ViewProps & {
  children: ReactNode
  variant?: SurfaceVariant
  accentBorderTop?: boolean
  elevated?: boolean
  style?: ViewStyle
}

export function Surface({
  children,
  variant = "default",
  accentBorderTop = false,
  elevated = false,
  style,
  ...rest
}: SurfaceProps) {
  const resolvedVariant = elevated ? "elevated" : variant

  return (
    <View
      style={[
        styles.base,
        surfaceStyle(resolvedVariant),
        accentBorderTop && styles.accentTop,
        style,
      ]}
      {...rest}
    >
      {children}
    </View>
  )
}

const styles = StyleSheet.create({
  base: {
    borderRadius: radii.lg,
    borderWidth: 1,
    padding: spacing.lg,
    gap: spacing.sm,
    ...shadow("sm"),
  },
  accentTop: {
    borderTopWidth: 3,
    borderTopColor: colors.lime,
  },
})
