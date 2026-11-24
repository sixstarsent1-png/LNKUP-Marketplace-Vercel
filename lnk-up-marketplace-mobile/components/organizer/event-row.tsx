import { Pressable, StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { EventImage } from "@/components/ui/event-image"
import { StatusBadge } from "@/components/ui/status-badge"
import type { OrgEventCard } from "@/lib/api/organizer-mobile"
import { formatShortDate } from "@/lib/format/date"
import { colors, fonts, layout, radii, spacing, typeScale, typography } from "@/lib/theme"

type EventRowProps = {
  event: OrgEventCard
  onPress: () => void
  /** "row" is the compact list layout; "card" adds a wide image. */
  variant?: "row" | "card"
  testID?: string
}

/** "Mar 13, 2027 · Venue · City, ST" — never a raw ISO date. */
export function eventMetaLine(event: Pick<OrgEventCard, "date" | "dateLabel" | "venue" | "place">): string {
  const date = formatShortDate(event.date) ?? (event.dateLabel || "Date to be announced")
  return [date, event.venue, event.place].filter(Boolean).join(" · ")
}

/** Counts line: "4 to review · 6 of 12 booths filled". */
export function eventCountsLine(event: OrgEventCard): string {
  const parts: string[] = []
  if (event.applications.pending > 0) parts.push(`${event.applications.pending} to review`)
  if (event.capacity.total > 0) {
    parts.push(`${event.capacity.confirmed} of ${event.capacity.total} booths filled`)
  } else if (event.applications.confirmed > 0) {
    parts.push(`${event.applications.confirmed} confirmed`)
  }
  return parts.join(" · ")
}

/** Compact organizer event row with resolved event media and separate publication/chronology chips. */
export function EventRow({ event, onPress, variant = "row", testID }: EventRowProps) {
  const counts = eventCountsLine(event)
  const card = variant === "card"
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${event.title}. ${eventMetaLine(event)}. ${event.publicationLabel}${counts ? `. ${counts}` : ""}`}
      testID={testID}
      style={({ pressed }) => [styles.row, card && styles.card, pressed && styles.pressed]}
    >
      <View style={card ? styles.cardImage : styles.thumb}>
        <EventImage
          uri={card ? event.media?.url : event.media?.mediumUrl}
          fallbackUri={event.fallbackUrl}
          fit={event.media?.fit}
          background={event.media?.background}
          aspectRatio={card ? 16 / 9 : 1}
          radius={card ? radii.md : radii.sm}
          icon="calendar-outline"
        />
      </View>
      <View style={styles.text}>
        <Text style={styles.title} numberOfLines={2}>
          {event.title}
        </Text>
        <Text style={styles.meta} numberOfLines={2}>
          {eventMetaLine(event)}
        </Text>
        <View style={styles.chips}>
          <StatusBadge label={event.publicationLabel || (event.publication === "live" ? "Published" : "Draft")} tone={event.publication === "live" ? "paid" : "neutral"} />
          {event.chronology === "today" ? <StatusBadge label="Today" tone="due" /> : null}
          {event.chronology === "completed" ? <StatusBadge label="Completed" tone="waitlist" /> : null}
        </View>
        {counts ? (
          <Text style={styles.counts} numberOfLines={1}>
            {counts}
          </Text>
        ) : null}
      </View>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    gap: spacing.md,
    padding: spacing.md,
    minHeight: layout.hitTargetMin + 24,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  card: {
    flexDirection: "column",
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  thumb: {
    width: 72,
  },
  cardImage: {
    width: "100%",
  },
  text: {
    flex: 1,
    gap: spacing.xs,
  },
  title: {
    fontFamily: fonts.displaySemi,
    fontSize: typeScale.bodyLarge,
    color: colors.black,
  },
  meta: {
    ...typography.meta,
  },
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.xs,
  },
  counts: {
    ...typography.caption,
    fontSize: typeScale.caption,
    color: colors.gray700,
  },
})
