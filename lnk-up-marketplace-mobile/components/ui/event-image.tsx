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
  icon?: AppIconName
  height?: number
  aspectRatio?: number
  radius?: number
  style?: StyleProp<ViewStyle>
  accessibilityLabel?: string
}

/**
 * Event/listing image with disk caching, a short fade-in (none with Reduce Motion), and a branded
 * placeholder instead of a broken image when the URL is missing or fails.
 */
export function EventImage({
  uri,
  label,
  icon = "storefront-outline",
  height,
  aspectRatio = 16 / 9,
  radius = radii.lg,
  style,
  accessibilityLabel,
}: EventImageProps) {
  const reduceMotion = useReducedMotion()
  const [failed, setFailed] = useState(false)
  const showImage = Boolean(uri) && !failed
  const sizing = height != null ? { height } : { aspectRatio }

  return (
    <View
      style={[styles.frame, sizing, { borderRadius: radius }, style]}
      accessible={Boolean(accessibilityLabel)}
      accessibilityRole={accessibilityLabel ? "image" : undefined}
      accessibilityLabel={accessibilityLabel}
    >
      {showImage ? (
        <Image
          source={{ uri: uri as string }}
          style={StyleSheet.absoluteFill}
          contentFit="cover"
          contentPosition="top center"
          cachePolicy="memory-disk"
          transition={reduceMotion ? 0 : 180}
          recyclingKey={uri ?? undefined}
          onError={() => setFailed(true)}
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
