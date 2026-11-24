import { memo } from "react"
import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { EventImage } from "@/components/ui/event-image"
import { formatShortDate } from "@/lib/format/date"
import { PressableScale } from "@/components/ui/pressable-scale"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import {
  eventListingLabel,
  eventListingState,
  type EventListingState,
  type OrganizerEventLite,
} from "@/lib/api/organizer-events"
import { colors, fonts, radii, spacing, type StatusTone, typeScale } from "@/lib/theme"

const STATE_TONE: Record<EventListingState, StatusTone> = {
  draft: "waitlist",
  live: "approved",
  past: "neutral",
  no_date: "review",
}

function Metric({ value, label, attention }: { value: number; label: string; attention?: boolean }) {
  return (
    <View style={styles.metric}>
      <Text style={[styles.metricValue, attention && styles.metricAttention]}>{value}</Text>
      <Text style={styles.metricLabel}>{label}</Text>
    </View>
  )
}

/** Organizer event card: image, listing state, where/when, and the counts that need action. */
export const OrganizerEventCard = memo(function OrganizerEventCard({
  event,
  showStatus = true,
}: {
  event: OrganizerEventLite
  showStatus?: boolean
}) {
  const state = eventListingState(event)
  const toReview = event.toReviewCount ?? 0
  const due = event.paymentRequiredCount ?? 0
  const confirmed = event.paidCount ?? 0
  const applications = event.requestsCount ?? 0
  const actions = toReview + (event.needsInfoCount ?? 0) + due
  const where = [formatShortDate(event.date), [event.city, event.state].filter(Boolean).join(", ")].filter(Boolean).join(" · ")
  const href = `/(organizer)/events/${event.id}` as Href

  return (
    <Surface elevated style={styles.card}>
      <PressableScale
        onPress={() => router.push(href)}
        accessibilityRole="button"
        accessibilityLabel={[
          `Manage ${event.title}`,
          eventListingLabel(state),
          where,
          `${applications} applications`,
          `${confirmed} confirmed`,
          actions > 0 ? `${actions} need action` : "",
        ]
          .filter(Boolean)
          .join(", ")}
      >
        <View style={styles.row}>
          <EventImage
            uri={event.media?.thumbUrl || event.imageUrl}
            fit={event.media?.fit}
            background={event.media?.background}
            height={84}
            radius={radii.md}
            style={styles.thumb}
            icon="calendar-outline"
          />
          <View style={styles.copy}>
            {showStatus ? <StatusBadge label={eventListingLabel(state)} tone={STATE_TONE[state]} /> : null}
            <SectionTitle style={styles.title} numberOfLines={2}>
              {event.title}
            </SectionTitle>
            {where ? (
              <View style={styles.metaRow}>
                <AppIcon name="calendar-outline" size={14} color={colors.gray500} />
                <Meta numberOfLines={1} style={styles.flexShrink}>
                  {where}
                </Meta>
              </View>
            ) : null}
          </View>
          <AppIcon name="chevron-forward" size={18} color={colors.gray400} />
        </View>
        <View style={styles.metrics}>
          <Metric value={applications} label="Applications" />
          <Metric value={toReview} label="To review" attention={toReview > 0} />
          <Metric value={due} label="Awaiting pay" attention={due > 0} />
          <Metric value={confirmed} label="Confirmed" />
        </View>
        {state === "draft" ? <Meta style={styles.draftHint}>Not visible to vendors yet — finish and publish.</Meta> : null}
      </PressableScale>
    </Surface>
  )
})

const styles = StyleSheet.create({
  card: { gap: spacing.md },
  row: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  thumb: { width: 84 },
  copy: { flex: 1, gap: 4, alignItems: "flex-start" },
  title: { fontSize: typeScale.bodyLarge, lineHeight: 21 },
  metaRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  flexShrink: { flexShrink: 1 },
  metrics: {
    flexDirection: "row",
    marginTop: spacing.md,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.gray100,
  },
  metric: { flex: 1, alignItems: "center", gap: 2 },
  metricValue: { fontFamily: fonts.display, fontSize: typeScale.headline, color: colors.black },
  metricAttention: { color: colors.amber700 },
  metricLabel: { fontFamily: fonts.body, fontSize: typeScale.micro, color: colors.gray500, textAlign: "center" },
  draftHint: { marginTop: spacing.sm, color: colors.gray700, fontFamily: fonts.bodySemi },
})
