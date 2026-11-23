import { useState } from "react"
import { StyleSheet, View, type StyleProp, type ViewStyle } from "react-native"
import { Image } from "expo-image"
import { useReducedMotion } from "react-native-reanimated"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type EventImageProps = {
  uri?: string | null
  /** Shown on the branded placeholder when there is no image or it fails to load. */
  label?: string
  /** Event title; its initials become the placeholder poster (same as the web cards). */
  title?: string
  icon?: AppIconName
  height?: number
  aspectRatio?: number
  radius?: number
  /** "cover" fills the frame; "contain" shows the whole flyer over a blurred copy. */
  fit?: "cover" | "contain"
  style?: StyleProp<ViewStyle>
  accessibilityLabel?: string
}

function initialsOf(title?: string): string {
  return (title ?? "")
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join("")
}

/**
 * Event/listing image with disk caching, a short fade-in (none with Reduce Motion), and an LNKup
 * poster instead of a broken image when the URL is missing or fails.
 */
export function EventImage({
  uri,
  label,
  title,
  icon = "storefront-outline",
  height,
  aspectRatio = 16 / 9,
  radius = radii.lg,
  fit = "cover",
  style,
  accessibilityLabel,
}: EventImageProps) {
  const reduceMotion = useReducedMotion()
  const [failed, setFailed] = useState(false)
  const showImage = Boolean(uri) && !failed
  const sizing = height != null ? { height } : { aspectRatio }
  const initials = initialsOf(title)

  return (
    <View
      style={[styles.frame, sizing, { borderRadius: radius }, style]}
      accessible={Boolean(accessibilityLabel)}
      accessibilityRole={accessibilityLabel ? "image" : undefined}
      accessibilityLabel={accessibilityLabel}
    >
      {showImage ? (
        <>
          {fit === "contain" ? (
            <Image
              source={{ uri: uri as string }}
              style={StyleSheet.absoluteFill}
              contentFit="cover"
              blurRadius={24}
              cachePolicy="memory-disk"
              accessibilityIgnoresInvertColors
            />
          ) : null}
          <Image
            source={{ uri: uri as string }}
            style={StyleSheet.absoluteFill}
            contentFit={fit}
            contentPosition={fit === "cover" ? "top center" : "center"}
            cachePolicy="memory-disk"
            transition={reduceMotion ? 0 : 180}
            recyclingKey={uri ?? undefined}
            onError={() => setFailed(true)}
            accessibilityIgnoresInvertColors
          />
        </>
      ) : (
        <View style={styles.placeholder}>
          {initials ? (
            <Text
              style={[styles.initials, height != null && height < 110 && styles.initialsSmall]}
              accessibilityElementsHidden
              importantForAccessibility="no"
            >
              {initials}
            </Text>
          ) : (
            <View style={styles.placeholderIcon}>
              <AppIcon name={icon} size={22} color={colors.lime} />
            </View>
          )}
          {label ? (
            <Text style={styles.placeholderLabel} numberOfLines={1}>
              {label}
            </Text>
          ) : null}
          <Text style={styles.brand} accessibilityElementsHidden importantForAccessibility="no">
            LNKUP
          </Text>
        </View>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  frame: {
    width: "100%",
    overflow: "hidden",
    backgroundColor: colors.black,
  },
  placeholder: {
    position: "absolute",
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.xs,
    paddingHorizontal: spacing.lg,
    backgroundColor: colors.black,
  },
  initials: {
    fontFamily: fonts.display,
    fontSize: 44,
    lineHeight: 48,
    letterSpacing: -1.5,
    color: colors.lime,
  },
  initialsSmall: {
    fontSize: 24,
    lineHeight: 28,
    letterSpacing: -0.5,
  },
  placeholderIcon: {
    width: 44,
    height: 44,
    borderRadius: radii.pill,
    backgroundColor: "rgba(255,255,255,0.08)",
    alignItems: "center",
    justifyContent: "center",
  },
  placeholderLabel: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: "rgba(255,255,255,0.7)",
  },
  brand: {
    position: "absolute",
    right: spacing.md,
    bottom: spacing.sm,
    fontFamily: fonts.bodyBold,
    fontSize: typeScale.micro,
    letterSpacing: 1.5,
    color: "rgba(255,255,255,0.45)",
  },
})
