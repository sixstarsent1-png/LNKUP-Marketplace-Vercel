import { useState } from "react"
import { StyleSheet, View, type StyleProp, type ViewStyle } from "react-native"
import { Image } from "expo-image"
import { useReducedMotion } from "react-native-reanimated"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type EventImageProps = {
  uri?: string | null
  /**
   * Approved fallback art from WordPress (category illustration) used when `uri` is missing or
   * fails, before the branded placeholder.
   */
  fallbackUri?: string | null
  /** Shown on the branded placeholder when there is no image or it fails to load. */
  label?: string
  icon?: AppIconName
  height?: number
  aspectRatio?: number
  radius?: number
  style?: StyleProp<ViewStyle>
  accessibilityLabel?: string
  /** "contain" shows the whole artwork (flyers, logos) instead of cropping. */
  fit?: "cover" | "contain"
  /**
   * Frame color behind contained artwork (from WordPress media.background). Without one, the
   * same image is shown blurred behind the flyer so portrait art does not float in empty space.
   */
  background?: string
}

/**
 * Event/listing image with disk caching, a short fade-in (none with Reduce Motion), WordPress'
 * resolved media first, its category fallback next, and a branded placeholder last.
 */
export function EventImage({
  uri,
  fallbackUri,
  label,
  icon = "storefront-outline",
  height,
  aspectRatio = 16 / 9,
  radius = radii.lg,
  style,
  accessibilityLabel,
  fit = "cover",
  background,
}: EventImageProps) {
  const reduceMotion = useReducedMotion()
  const [failed, setFailed] = useState<ReadonlySet<string>>(() => new Set())
  const candidates = [uri, fallbackUri].filter((u): u is string => typeof u === "string" && u.length > 0)
  const source = candidates.find((u) => !failed.has(u))
  const usingFallback = Boolean(source) && source !== uri
  const sizing = height != null ? { height } : { aspectRatio }
  const contain = fit === "contain" && !usingFallback
  const markFailed = (failedUri: string) =>
    setFailed((prev) => {
      const next = new Set(prev)
      next.add(failedUri)
      return next
    })

  return (
    <View
      style={[styles.frame, sizing, { borderRadius: radius }, source && contain && background ? { backgroundColor: background } : null, style]}
      accessible={Boolean(accessibilityLabel)}
      accessibilityRole={accessibilityLabel ? "image" : undefined}
      accessibilityLabel={accessibilityLabel}
    >
      {source && contain && !background ? (
        <Image
          source={{ uri: source }}
          style={StyleSheet.absoluteFill}
          contentFit="cover"
          blurRadius={24}
          cachePolicy="memory-disk"
          recyclingKey={`${source}:backdrop`}
          accessibilityIgnoresInvertColors
        />
      ) : null}
      {source && contain && !background ? <View style={styles.scrim} /> : null}
      {source ? (
        <Image
          source={{ uri: source }}
          style={contain ? styles.contained : StyleSheet.absoluteFill}
          contentFit={contain ? "contain" : "cover"}
          contentPosition={contain ? "center" : "top center"}
          cachePolicy="memory-disk"
          transition={reduceMotion ? 0 : 180}
          recyclingKey={source}
          onError={() => markFailed(source)}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <View style={styles.placeholder}>
          <View style={styles.placeholderIcon}>
            <AppIcon name={icon} size={22} color={colors.black} />
          </View>
          {label ? (
            <Text style={styles.placeholderLabel} numberOfLines={1}>
              {label}
            </Text>
          ) : null}
        </View>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  frame: {
    width: "100%",
    overflow: "hidden",
    backgroundColor: colors.limeMuted,
  },
  scrim: {
    position: "absolute",
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    backgroundColor: "rgba(10, 10, 10, 0.18)",
  },
  contained: {
    position: "absolute",
    top: spacing.sm,
    right: spacing.sm,
    bottom: spacing.sm,
    left: spacing.sm,
  },
  placeholder: {
    position: "absolute",
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.sm,
    paddingHorizontal: spacing.lg,
  },
  placeholderIcon: {
    width: 44,
    height: 44,
    borderRadius: radii.pill,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  placeholderLabel: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
})
