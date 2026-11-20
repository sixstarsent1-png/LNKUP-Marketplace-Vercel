import { Platform, Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import type { MarketplaceMapPin } from "@/lib/api/map-pins"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

export function MapPinPreview({
  pin,
  onClose,
  testID = "map-pin-preview",
}: {
  pin: MarketplaceMapPin
  onClose?: () => void
  testID?: string
}) {
  const location = [pin.city, pin.state].filter(Boolean).join(", ")

  return (
    <View style={styles.card} testID={testID}>
      <Text style={styles.title} numberOfLines={2}>
        {pin.title}
      </Text>
      {location ? <Text style={styles.meta}>{location}</Text> : null}
      {pin.eventDate ? <Text style={styles.meta}>{pin.eventDate}</Text> : null}
      <View style={styles.row}>
        {pin.vendorFee != null ? (
          <Text style={styles.badge}>${pin.vendorFee} fee</Text>
        ) : null}
        {pin.category ? <Text style={styles.badgeMuted}>{pin.category}</Text> : null}
        {pin.spotsRemaining != null ? (
          <Text style={styles.badgeMuted}>{pin.spotsRemaining} spots</Text>
        ) : null}
      </View>
      <View style={styles.actions}>
        <Pressable
          style={styles.primary}
          onPress={() => router.push(`/opportunity/${pin.opportunityId}` as Href)}
          accessibilityRole="button"
          testID={`${testID}-view`}
        >
          <Text style={styles.primaryText}>View</Text>
        </Pressable>
        <Pressable
          style={styles.secondary}
          onPress={() => router.push(`/apply/${pin.opportunityId}` as Href)}
          accessibilityRole="button"
          testID={`${testID}-apply`}
        >
          <Text style={styles.secondaryText}>Apply</Text>
        </Pressable>
        {onClose ? (
          <Pressable
            style={styles.close}
            onPress={onClose}
            accessibilityRole="button"
            accessibilityLabel="Close pin preview"
          >
            <Text style={styles.closeText}>Close</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.lg,
    backgroundColor: colors.white,
    padding: spacing.md,
    gap: spacing.sm,
    ...Platform.select({
      web: {
        boxShadow: "0 4px 8px rgba(0, 0, 0, 0.12)",
      },
      default: {
        shadowColor: "#000",
        shadowOpacity: 0.12,
        shadowRadius: 8,
        shadowOffset: { width: 0, height: 4 },
        elevation: 4,
      },
    }),
  },
  title: {
    fontSize: typeScale.callout,
    fontWeight: "700",
    color: colors.black,
  },
  meta: {
    fontSize: typeScale.caption,
    color: colors.gray500,
  },
  row: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  badge: {
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.black,
    backgroundColor: colors.lime,
    overflow: "hidden",
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
  },
  badgeMuted: {
    fontSize: typeScale.caption,
    fontWeight: "600",
    color: colors.gray700,
    backgroundColor: colors.gray100,
    overflow: "hidden",
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  primary: {
    flex: 1,
    backgroundColor: colors.black,
    borderRadius: radii.sm,
    paddingVertical: spacing.sm,
    alignItems: "center",
  },
  primaryText: {
    color: colors.white,
    fontWeight: "700",
    fontSize: typeScale.footnote,
  },
  secondary: {
    flex: 1,
    backgroundColor: colors.lime,
    borderRadius: radii.sm,
    paddingVertical: spacing.sm,
    alignItems: "center",
  },
  secondaryText: {
    color: colors.black,
    fontWeight: "700",
    fontSize: typeScale.footnote,
  },
  close: {
    paddingHorizontal: spacing.sm,
    justifyContent: "center",
  },
  closeText: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray500,
  },
})
