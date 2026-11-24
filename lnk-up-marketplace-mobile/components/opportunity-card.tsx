import { Pressable, StyleSheet, View } from "react-native"

import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { formatMoney } from "@/lib/api/format-money"
import { opportunityApplyCta } from "@/lib/vendor/opportunity-cta"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

export { opportunityApplyCta }

function availability(item: MarketplaceOpportunity): { label: string; tone: "ok" | "low" | "none" } | null {
  if (!item.spotsTotal) return null
  if (item.spotsRemaining <= 0) return { label: "Full", tone: "none" }
  if (item.spotsRemaining <= Math.max(2, Math.ceil(item.spotsTotal * 0.2))) {
    return { label: `${item.spotsRemaining} left`, tone: "low" }
  }
  return { label: `${item.spotsRemaining} of ${item.spotsTotal} open`, tone: "ok" }
}

/** Marketplace opportunity card: image, key facts, availability, save, and an honest Apply state. */
export function OpportunityCard({
  item,
  onApply,
  onPress,
  saved,
  onToggleSave,
}: {
  item: MarketplaceOpportunity
  onApply?: () => void
  onPress?: () => void
  saved?: boolean
  onToggleSave?: () => void
}) {
  const location = [item.city, item.state].filter(Boolean).join(", ")
  const fee =
    item.vendorFee == null
      ? "Fee unavailable"
      : item.vendorFee > 0
        ? `From ${formatMoney(item.vendorFee)}`
        : "Free"
  const open = onPress ?? onApply
  const cta = opportunityApplyCta(item)
  const spots = availability(item)
  const category = item.categories[0]
  const image = item.gallery?.find((media) => media.type === "image")

  return (
    <Surface style={styles.card} elevated>
      <View>
        <Pressable
          onPress={open}
          disabled={!open}
          accessibilityRole="button"
          accessibilityLabel={[`View details for ${item.title}`, item.eventDate, location, fee].filter(Boolean).join(", ")}
        >
          <EventImage
            uri={item.media?.url || item.imageUrl || image?.thumb || image?.url}
            fit={item.media?.fit}
            background={item.media?.background}
            label={category ?? "Event"}
            height={168}
            radius={0}
          />
        </Pressable>
        <View style={styles.overlayRow} pointerEvents="box-none">
          {category ? (
            <View style={styles.overlayChip}>
              <Text style={styles.overlayChipText} numberOfLines={1}>
                {category}
              </Text>
            </View>
          ) : (
            <View />
          )}
          {onToggleSave && item.opportunityId ? (
            <Pressable
              onPress={onToggleSave}
              accessibilityRole="button"
              accessibilityLabel={saved ? `Unsave ${item.title}` : `Save ${item.title}`}
              accessibilityState={{ selected: Boolean(saved) }}
              hitSlop={6}
              style={({ pressed }) => [styles.saveHit, pressed && styles.saveHitPressed]}
            >
              <AppIcon name={saved ? "heart" : "heart-outline"} size={22} color={saved ? colors.red600 : colors.black} />
            </Pressable>
          ) : null}
        </View>
      </View>

      <Pressable
        onPress={open}
        disabled={!open}
        style={({ pressed }) => [styles.body, pressed && styles.detailsPressed]}
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
      >
        <View style={styles.titleRow}>
          <SectionTitle style={styles.title} numberOfLines={2}>
            {item.title}
          </SectionTitle>
          {item.trending ? (
            <View style={styles.badge}>
              <Meta style={styles.badgeText}>Trending</Meta>
            </View>
          ) : null}
        </View>
        {item.eventDate || location ? (
          <View style={styles.metaRow}>
            <AppIcon name="calendar-outline" size={14} color={colors.gray500} />
            <Meta numberOfLines={1} style={styles.flexShrink}>
              {[item.eventDate, location].filter(Boolean).join(" · ")}
            </Meta>
          </View>
        ) : null}
        {item.organizerName ? (
          <View style={styles.metaRow}>
            <AppIcon name={item.organizerVerified ? "shield-checkmark-outline" : "person-outline"} size={14} color={colors.gray500} />
            <Meta numberOfLines={1} style={styles.flexShrink}>
              {item.organizerName}
              {item.organizerVerified ? " · Verified organizer" : ""}
            </Meta>
          </View>
        ) : null}
        <View style={styles.chips}>
          <View style={styles.feePill}>
            <Text style={styles.fee}>{fee}</Text>
          </View>
          {spots ? (
            <View style={[styles.spotPill, spots.tone === "low" && styles.spotLow, spots.tone === "none" && styles.spotNone]}>
              <Text style={[styles.spotText, spots.tone !== "ok" && styles.spotTextStrong]}>{spots.label}</Text>
            </View>
          ) : null}
          {item.applicationDeadline && cta.canApply ? (
            <View style={styles.deadlineChip}>
              <Text style={styles.deadline}>Apply by {item.applicationDeadline}</Text>
            </View>
          ) : null}
        </View>
      </Pressable>

      {onApply && item.opportunityId ? (
        <View style={styles.actions}>
          <Button
            label="Details"
            variant="secondary"
            style={styles.flex}
            onPress={open}
            accessibilityLabel={`Details for ${item.title}`}
          />
          <Button
            label={cta.label}
            variant={cta.canApply ? "primary" : "secondary"}
            disabled={!cta.canApply && cta.reason !== "applied"}
            icon={cta.reason === "applied" ? "checkmark-circle-outline" : undefined}
            style={styles.flex}
            onPress={cta.canApply ? onApply : open}
            accessibilityLabel={cta.canApply ? `Apply to ${item.title}` : `${cta.label}: ${item.title}`}
          />
        </View>
      ) : null}
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: { padding: 0, overflow: "hidden", gap: 0 },
  overlayRow: {
    position: "absolute",
    top: spacing.sm,
    left: spacing.sm,
    right: spacing.sm,
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
  },
  overlayChip: {
    backgroundColor: colors.white,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: 4,
    maxWidth: "70%",
  },
  overlayChipText: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.black },
  saveHit: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    alignItems: "center",
    justifyContent: "center",
    borderRadius: radii.pill,
    backgroundColor: colors.white,
  },
  saveHitPressed: { backgroundColor: colors.gray100 },
  body: { paddingHorizontal: spacing.lg, paddingTop: spacing.md, gap: 6 },
  detailsPressed: { opacity: 0.7 },
  titleRow: { flexDirection: "row", alignItems: "flex-start", gap: spacing.sm },
  title: { flex: 1, fontSize: typeScale.headline, lineHeight: 24 },
  flexShrink: { flexShrink: 1 },
  badge: {
    backgroundColor: colors.lime,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radii.sm,
    overflow: "hidden",
  },
  badgeText: { color: colors.black, fontFamily: fonts.bodySemi, fontSize: typeScale.micro },
  metaRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs, marginTop: spacing.xs },
  feePill: {
    backgroundColor: colors.limeMuted,
    borderRadius: radii.sm,
    borderWidth: 1,
    borderColor: colors.lime,
    paddingHorizontal: spacing.sm,
    paddingVertical: 3,
  },
  fee: { fontFamily: fonts.bodyBold, fontSize: typeScale.footnote, color: colors.black },
  spotPill: {
    backgroundColor: colors.gray50,
    borderRadius: radii.sm,
    borderWidth: 1,
    borderColor: colors.gray200,
    paddingHorizontal: spacing.sm,
    paddingVertical: 3,
  },
  spotLow: { backgroundColor: colors.amber50, borderColor: colors.amber200 },
  spotNone: { backgroundColor: colors.red50, borderColor: colors.red200 },
  spotText: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.gray700 },
  spotTextStrong: { color: colors.black },
  deadlineChip: {
    backgroundColor: colors.amber50,
    borderRadius: radii.sm,
    borderWidth: 1,
    borderColor: colors.amber200,
    paddingHorizontal: spacing.sm,
    paddingVertical: 3,
  },
  deadline: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.amber700 },
  actions: { flexDirection: "row", gap: spacing.sm, padding: spacing.lg, paddingTop: spacing.md },
  flex: { flex: 1 },
})
