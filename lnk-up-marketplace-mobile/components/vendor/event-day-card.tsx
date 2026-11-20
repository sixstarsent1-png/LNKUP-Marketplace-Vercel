import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { isEventToday } from "@/lib/vendor/vendor-stage"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

/** Event-day mode for confirmed vendors: today/tomorrow's market with the pass one tap away. */
export function EventDayCard({ booking }: { booking: VendorRequest }) {
  const today = isEventToday(booking)
  return (
    <View style={styles.card} testID="vendor-event-day-card" accessibilityRole="summary">
      <View style={styles.row}>
        <View style={styles.badge}>
          <AppIcon name={today ? "flash" : "calendar"} size={16} color={colors.black} />
          <Text style={styles.badgeText}>{today ? "Event day" : "Tomorrow"}</Text>
        </View>
      </View>
      <Text style={styles.title} numberOfLines={2} maxFontSizeMultiplier={1.4}>
        {booking.eventName}
      </Text>
      <Text style={styles.meta}>
        {[booking.eventDate, booking.city].filter(Boolean).join(" · ")}
      </Text>
      <Text style={styles.hint}>
        {today
          ? "Show your pass at check-in. Your booth, arrival window and organizer contact are on it."
          : "Check your arrival window and booth before you pack up."}
      </Text>
      <View style={styles.actions}>
        <Button
          label="Open event pass"
          icon="qr-code-outline"
          variant="accent"
          style={styles.flex}
          onPress={() => router.push(`/pass/${booking.id}` as Href)}
        />
        <Button
          label="Day-of info"
          variant="secondary"
          onPress={() => router.push("/(vendor)/operations" as Href)}
        />
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.xs,
    borderTopWidth: 3,
    borderTopColor: colors.lime,
  },
  row: { flexDirection: "row" },
  badge: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    backgroundColor: colors.lime,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: 4,
  },
  badgeText: { fontFamily: fonts.bodyBold, fontSize: typeScale.caption, color: colors.black, textTransform: "uppercase" },
  title: { fontFamily: fonts.display, fontSize: typeScale.title, lineHeight: 28, color: colors.white, marginTop: spacing.sm },
  meta: { fontFamily: fonts.bodySemi, fontSize: typeScale.body, color: colors.gray200 },
  hint: { fontFamily: fonts.body, fontSize: typeScale.body, lineHeight: 20, color: colors.gray200, marginTop: spacing.xs },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.md },
  flex: { flex: 1 },
})
