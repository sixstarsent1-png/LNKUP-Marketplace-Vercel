import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { StatusPill } from "@/components/ui/status-pill"
import {
  vendorRequestStatusLabel,
  vendorRequestStatusTone,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import { formatMoney } from "@/lib/api/vendor-wallet"
import { colors, fonts, radii, spacing, typography } from "@/lib/theme"

/** Vendor booking card: event image, status, when/where, fee, and the next action. */
export function RequestCard({
  item,
  onPress,
  onPrimaryAction,
  primaryLabel,
  hint,
}: {
  item: VendorRequest
  onPress?: () => void
  onPrimaryAction?: () => void
  primaryLabel?: string
  hint?: string
}) {
  const tone = vendorRequestStatusTone(item.status)
  const statusLabel = vendorRequestStatusLabel(item.status)
  const isDue = tone === "due"
  const fee = item.amountDue != null && item.amountDue > 0 ? formatMoney(item.amountDue, item.currency ?? "USD") : undefined
  const whenWhere = [item.eventDate, item.city].filter(Boolean).join(" · ")

  const body = (
    <View style={styles.row}>
      <EventImage uri={item.imageUrl} height={72} radius={radii.md} style={styles.thumb} icon="calendar-outline" />
      <View style={styles.copy}>
        <View style={styles.titleRow}>
          <Text style={styles.title} numberOfLines={2}>
            {item.eventName}
          </Text>
        </View>
        <StatusPill status={item.status} audience="vendor" />
        {whenWhere ? (
          <View style={styles.metaRow}>
            <AppIcon name="calendar-outline" size={14} color={colors.gray500} />
            <Text style={styles.meta} numberOfLines={1}>
              {whenWhere}
            </Text>
          </View>
        ) : null}
        {item.category || fee ? (
          <View style={styles.metaRow}>
            <AppIcon name="pricetag-outline" size={14} color={colors.gray500} />
            <Text style={[styles.meta, isDue && styles.metaDue]} numberOfLines={1}>
              {[item.category, fee ? `${fee}${isDue ? " due" : ""}` : null].filter(Boolean).join(" · ")}
            </Text>
          </View>
        ) : null}
      </View>
      {onPress ? <AppIcon name="chevron-forward" size={18} color={colors.gray400} /> : null}
    </View>
  )

  return (
    <View style={[styles.card, isDue && styles.cardDue]}>
      {onPress ? (
        <Pressable
          onPress={onPress}
          accessibilityRole="button"
          accessibilityLabel={[item.eventName, statusLabel, whenWhere, fee ? `${fee}${isDue ? " due" : ""}` : ""]
            .filter(Boolean)
            .join(", ")}
          accessibilityHint="Opens booking details"
          style={({ pressed }) => pressed && styles.pressed}
        >
          {body}
        </Pressable>
      ) : (
        body
      )}
      {hint && !primaryLabel ? <Text style={styles.hint}>{hint}</Text> : null}
      {onPrimaryAction && primaryLabel ? (
        <Button
          label={primaryLabel}
          onPress={onPrimaryAction}
          variant={isDue ? "primary" : "secondary"}
          icon={isDue ? (item.status === "info_requested" ? "chatbubble-ellipses-outline" : "card-outline") : "ticket-outline"}
          accessibilityLabel={`${primaryLabel}: ${item.eventName}`}
          style={styles.primaryBtn}
        />
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
  },
  cardDue: { borderColor: colors.amber200, backgroundColor: colors.amber50 },
  pressed: { opacity: 0.75 },
  row: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  thumb: { width: 72 },
  copy: { flex: 1, gap: 4, alignItems: "flex-start" },
  titleRow: { flexDirection: "row" },
  title: { flex: 1, fontFamily: fonts.displaySemi, fontSize: 16, lineHeight: 21, color: colors.black },
  metaRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  meta: { ...typography.meta, flexShrink: 1 },
  metaDue: { color: colors.amber700, fontFamily: fonts.bodySemi },
  hint: { marginTop: spacing.sm, fontSize: 12, fontFamily: fonts.bodySemi, color: colors.gray700 },
  primaryBtn: { marginTop: spacing.md },
})
