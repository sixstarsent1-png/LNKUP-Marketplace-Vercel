import { useEffect } from "react"
import { StyleSheet, View, type DimensionValue, type ViewStyle } from "react-native"
import Animated, {
  cancelAnimation,
  Easing,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withTiming,
} from "react-native-reanimated"

import { colors, radii, spacing } from "@/lib/theme"

type SkeletonProps = {
  width?: DimensionValue
  height?: number
  radius?: number
  style?: ViewStyle
}

/** Pulsing placeholder block used while first-paint data loads. */
export function Skeleton({ width = "100%", height = 14, radius = radii.sm, style }: SkeletonProps) {
  const reduceMotion = useReducedMotion()
  const opacity = useSharedValue(reduceMotion ? 0.8 : 0.55)

  useEffect(() => {
    if (reduceMotion) {
      opacity.value = 0.8
      return
    }
    opacity.value = withRepeat(
      withTiming(1, { duration: 750, easing: Easing.inOut(Easing.quad) }),
      -1,
      true,
    )
    return () => cancelAnimation(opacity)
  }, [opacity, reduceMotion])

  const animatedStyle = useAnimatedStyle(() => ({ opacity: opacity.value }))

  return (
    <Animated.View
      style={[styles.block, { width, height, borderRadius: radius }, animatedStyle, style]}
    />
  )
}

/** Card-shaped skeleton approximating a list card (title, meta, footer). */
export function SkeletonCard({ withMedia = false }: { withMedia?: boolean }) {
  return (
    <View style={styles.card}>
      {withMedia ? <Skeleton height={120} radius={radii.md} /> : null}
      <Skeleton width="70%" height={18} />
      <Skeleton width="45%" height={12} />
      <View style={styles.footer}>
        <Skeleton width={72} height={22} radius={radii.pill} />
        <Skeleton width={96} height={12} />
      </View>
    </View>
  )
}

/** Stack of skeleton cards. Announced to screen readers as a single loading region. */
export function SkeletonList({
  count = 3,
  withMedia = false,
  label = "Loading",
}: {
  count?: number
  withMedia?: boolean
  label?: string
}) {
  return (
    <View
      style={styles.list}
      accessible
      accessibilityRole="progressbar"
      accessibilityLabel={label}
    >
      {Array.from({ length: count }, (_, index) => (
        <SkeletonCard key={index} withMedia={withMedia} />
      ))}
    </View>
  )
}

/** Row of KPI tile skeletons matching StatGrid. */
export function SkeletonStats({ count = 3 }: { count?: number }) {
  return (
    <View style={styles.stats}>
      {Array.from({ length: count }, (_, index) => (
        <View key={index} style={styles.statTile}>
          <Skeleton width="50%" height={22} />
          <Skeleton width="80%" height={10} />
        </View>
      ))}
    </View>
  )
}

const styles = StyleSheet.create({
  block: {
    backgroundColor: colors.gray200,
  },
  card: {
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  footer: {
    marginTop: spacing.xs,
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },
  list: {
    gap: spacing.md,
  },
  stats: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  statTile: {
    flex: 1,
    minHeight: 80,
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
    gap: spacing.sm,
    justifyContent: "center",
  },
})
