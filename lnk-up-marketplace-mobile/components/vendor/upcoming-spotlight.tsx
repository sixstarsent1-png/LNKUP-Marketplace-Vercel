import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { canOpenPass, countdownLabel } from "@/lib/vendor/booking-lifecycle"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

/** Next confirmed event: when, where, how soon, and the pass when the server says it is active. */
export function UpcomingSpotlight({ booking }: { booking: VendorRequest }) {
  const countdown = countdownLabel(booking)
  const pass = canOpenPass(booking)
  return (
    <View style={styles.card} testID="vendor-upcoming-spotlight">
      <Pressable
        onPress={() => router.push(`/booking/${booking.id}` as Href)}
        accessibilityRole="button"
        accessibilityLabel={[`Next event ${booking.eventName}`, booking.eventDate, booking.city, countdown].filter(Boolean).join(", ")}
        style={({ pressed }) => [styles.top, pressed && styles.pressed]}
      >
        <EventImage uri={booking.imageUrl} height={72} aspectRatio={1} radius={radii.md} style={styles.thumb} icon="calendar-outline" />
        <View style={styles.text}>
          <Text style={styles.eyebrow}>{countdown ? `Next event · ${countdown}` : "Next event"}</Text>
          <Text style={styles.title} numberOfLines={2}>
            {booking.eventName}
          </Text>
          <Text style={styles.meta} numberOfLines={1}>
            {[booking.eventDate, booking.city].filter(Boolean).join(" · ")}
          </Text>
        </View>
      </Pressable>
      <View style={styles.actions}>
        {pass ? (
          <Button label="Open event pass" icon="qr-code-outline" variant="accent" style={styles.flex} onPress={() => router.push(`/pass/${booking.id}` as Href)} />
        ) : null}
        <Button label="Prepare" variant="secondary" style={pass ? undefined : styles.flex} onPress={() => router.push("/(vendor)/operations" as Href)} />
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  card: { backgroundColor: colors.shell, borderRadius: radii.lg, padding: spacing.md, gap: spacing.md },
  top: { flexDirection: "row", gap: spacing.md, alignItems: "center" },
  pressed: { opacity: 0.85 },
  thumb: { width: 72 },
  text: { flex: 1, gap: 2 },
  eyebrow: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.lime, textTransform: "uppercase", letterSpacing: 0.5 },
  title: { fontFamily: fonts.displaySemi, fontSize: typeScale.headline, lineHeight: 22, color: colors.white },
  meta: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray200 },
  actions: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
})
