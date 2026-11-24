import { memo } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { EventImage } from "@/components/ui/event-image"
import { Meta } from "@/components/ui/typography"
import type { EventGroup } from "@/lib/api/event-groups"
import {
  deadlineLabel,
  groupAccessibilityLabel,
  groupAvailability,
  groupCategoryLabel,
  groupPriceLabel,
} from "@/lib/vendor/event-group-presentation"
import { normalizeVendorRequestStatus, vendorRequestStatusLabel } from "@/lib/api/vendor-requests"
import { colors, fonts, layout, radii, shadow, spacing, typeScale } from "@/lib/theme"

export type EventCardVariant = "featured" | "standard" | "compact" | "row"

type EventCardProps = {
  group: EventGroup
  variant?: EventCardVariant
  saved?: boolean
  onToggleSave?: () => void
  onPress: () => void
  testID?: string
}

/**
 * One card per event. The whole card opens the event; vendor options, prices and rules stay on
 * the event screen. At most one status note per card; everything else is plain metadata.
 */
export const EventCard = memo(function EventCard({
  group,
  variant = "standard",
  saved,
  onToggleSave,
  onPress,
  testID,
}: EventCardProps) {
  if (variant === "row") return <EventRow group={group} onPress={onPress} saved={saved} onToggleSave={onToggleSave} testID={testID} />
  const compact = variant === "compact"
  const price = groupPriceLabel(group)
  const categories = compact && price ? null : groupCategoryLabel(group.categories, compact ? 1 : 2)
  const note = statusNote(group)
  const meta = [group.dateLabel, group.place].filter(Boolean).join(" · ")
  const imageHeight = variant === "featured" ? 200 : compact ? 116 : 156

  return (
    <View style={[styles.card, compact && styles.cardCompact, variant === "featured" && styles.cardFeatured]} testID={testID}>
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={groupAccessibilityLabel(group)}
        accessibilityHint="Opens the event and its vendor options"
        style={({ pressed }) => [pressed && styles.pressed]}
      >
        <EventImage
          uri={variant === "featured" ? group.media?.largeUrl || group.media?.url : group.media?.url}
          fallbackUri={group.fallbackImageUrl}
          fit={group.media?.fit}
          background={group.media?.background}
          label={group.categories[0] ?? group.eventTypes[0] ?? "Event"}
          height={imageHeight}
          radius={0}
        />
        <View style={[styles.body, compact && styles.bodyCompact]}>
          <Text style={[styles.title, compact && styles.titleCompact]} numberOfLines={2}>
            {group.title}
          </Text>
          {meta ? (
            <Meta numberOfLines={1} style={styles.meta}>
              {meta}
            </Meta>
          ) : null}
          {!compact && group.organizerName ? (
            <View style={styles.organizerRow}>
              {group.organizerVerified ? <AppIcon name="shield-checkmark" size={13} color={colors.green700} /> : null}
              <Meta numberOfLines={1} style={styles.organizer}>
                {group.organizerName}
                {group.organizerVerified ? " · Verified" : ""}
              </Meta>
            </View>
          ) : null}
          {price || categories ? (
            <View style={styles.priceRow}>
              {price ? <Text style={styles.price}>{price}</Text> : null}
              {categories ? (
                <Meta numberOfLines={1} style={styles.categories}>
                  {price ? "· " : ""}
                  {categories}
                </Meta>
              ) : null}
            </View>
          ) : null}
          {note && !compact ? (
            <Text style={[styles.note, note.tone === "urgent" && styles.noteUrgent, note.tone === "muted" && styles.noteMuted]} numberOfLines={1}>
              {note.label}
            </Text>
          ) : null}
        </View>
      </Pressable>
      {onToggleSave ? (
        <SaveButton saved={Boolean(saved)} title={group.title} onPress={onToggleSave} style={styles.saveOverlay} />
      ) : null}
    </View>
  )
})

function EventRow({ group, onPress, saved, onToggleSave, testID }: Omit<EventCardProps, "variant">) {
  const price = groupPriceLabel(group)
  const note = statusNote(group)
  return (
    <View style={styles.row} testID={testID}>
      <Pressable
        onPress={onPress}
        style={({ pressed }) => [styles.rowPress, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={groupAccessibilityLabel(group)}
      >
        <EventImage
          uri={group.media?.thumbUrl || group.media?.url}
          fallbackUri={group.fallbackImageUrl}
          fit={group.media?.fit}
          background={group.media?.background}
          height={64}
          aspectRatio={1}
          radius={radii.md}
          style={styles.rowThumb}
          icon="calendar-outline"
        />
        <View style={styles.rowBody}>
          <Text style={styles.rowTitle} numberOfLines={1}>
            {group.title}
          </Text>
          <Meta numberOfLines={1}>{[group.dateLabel, group.place].filter(Boolean).join(" · ")}</Meta>
          <Meta numberOfLines={1} style={styles.rowMeta}>
            {[price, note?.label].filter(Boolean).join(" · ")}
          </Meta>
        </View>
      </Pressable>
      {onToggleSave ? <SaveButton saved={Boolean(saved)} title={group.title} onPress={onToggleSave} /> : null}
    </View>
  )
}

function SaveButton({
  saved,
  title,
  onPress,
  style,
}: {
  saved: boolean
  title: string
  onPress: () => void
  style?: object
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={saved ? `Remove ${title} from saved` : `Save ${title}`}
      accessibilityState={{ selected: saved }}
      hitSlop={6}
      style={({ pressed }) => [styles.save, style, pressed && styles.savePressed]}
    >
      <AppIcon name={saved ? "heart" : "heart-outline"} size={20} color={saved ? colors.red600 : colors.black} />
    </Pressable>
  )
}

type StatusNote = { label: string; tone: "default" | "urgent" | "muted" }

/** The single most useful status line for a card. */
function statusNote(group: EventGroup): StatusNote | null {
  if (group.applicationStatus) {
    const status = normalizeVendorRequestStatus(group.applicationStatus)
    return { label: `You applied · ${vendorRequestStatusLabel(status)}`, tone: "muted" }
  }
  const availability = groupAvailability(group)
  if (availability?.tone === "closed") return { label: availability.label, tone: "muted" }
  const deadline = deadlineLabel(group.deadlineIso)
  if (deadline?.urgent) return { label: deadline.label, tone: "urgent" }
  if (availability?.tone === "low") return { label: availability.label, tone: "urgent" }
  if (deadline) return { label: deadline.label, tone: "default" }
  return availability ? { label: availability.label, tone: "default" } : null
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    overflow: "hidden",
    ...shadow("sm"),
  },
  cardCompact: { width: 232 },
  cardFeatured: { ...shadow("md") },
  pressed: { opacity: 0.85 },
  body: { paddingHorizontal: spacing.md, paddingTop: spacing.md, paddingBottom: spacing.md, gap: 4 },
  bodyCompact: { paddingHorizontal: spacing.md, paddingVertical: spacing.sm, gap: 2 },
  title: { fontFamily: fonts.displaySemi, fontSize: typeScale.headline - 1, lineHeight: 22, color: colors.ink },
  titleCompact: { fontSize: typeScale.callout, lineHeight: 20 },
  meta: { color: colors.inkMuted },
  organizerRow: { flexDirection: "row", alignItems: "center", gap: 4 },
  organizer: { flexShrink: 1 },
  priceRow: { flexDirection: "row", alignItems: "baseline", gap: 4, marginTop: 2, flexWrap: "nowrap" },
  price: { fontFamily: fonts.bodyBold, fontSize: typeScale.callout, color: colors.ink, flexShrink: 0 },
  categories: { flexShrink: 1 },
  note: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.gray700, marginTop: 2 },
  noteUrgent: { color: colors.amber700 },
  noteMuted: { color: colors.gray500 },
  save: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    borderRadius: radii.pill,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  saveOverlay: { position: "absolute", top: spacing.sm, right: spacing.sm, ...shadow("sm") },
  savePressed: { backgroundColor: colors.gray100 },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    paddingLeft: spacing.sm,
    paddingRight: spacing.xs,
  },
  rowPress: { flex: 1, flexDirection: "row", alignItems: "center", gap: spacing.md, paddingVertical: spacing.sm },
  rowThumb: { width: 64 },
  rowBody: { flex: 1, gap: 2 },
  rowTitle: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  rowMeta: { color: colors.gray700 },
})
