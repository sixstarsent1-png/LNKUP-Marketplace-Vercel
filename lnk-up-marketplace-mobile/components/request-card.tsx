import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { StatusBadge } from "@/components/ui/status-badge"
import {
  vendorRequestStatusLabel,
  vendorRequestStatusTone,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import { formatMoney } from "@/lib/api/vendor-wallet"
import { vendorStatusExplainer } from "@/lib/status/application-status"
import { colors, fonts, radii, spacing, typography, typeScale } from "@/lib/theme"
import { isPastEventInvoice, isUpcomingPaymentDue } from "@/lib/vendor/booking-lifecycle"

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
  const pastInvoice = isPastEventInvoice(item)
  const isDue = isUpcomingPaymentDue(item)
  const tone = pastInvoice ? "neutral" : vendorRequestStatusTone(item.status)
  const statusLabel = item.primaryStatus ?? vendorRequestStatusLabel(item.status)
  const fee = item.amountDue != null && item.amountDue > 0 ? formatMoney(item.amountDue, item.currency ?? "USD") : undefined
  const whenWhere = [item.eventDate, item.city].filter(Boolean).join(" · ")
  const explainer =
    hint ??
    (item.eventResolution === "unrecoverable"
      ? "The event listing was removed. Your application, payments and receipts are kept."
      : pastInvoice
        ? "Outstanding invoice · past event. The event already happened; the balance is still open."
        : vendorStatusExplainer(item.status))
  const optionLabel = item.opportunityTitle && item.opportunityTitle !== item.eventName ? item.opportunityTitle : item.category

  const body = (
    <View style={styles.row}>
      <EventImage uri={item.imageUrl} height={72} radius={radii.md} style={styles.thumb} icon="calendar-outline" />
      <View style={styles.copy}>
        <View style={styles.titleRow}>
          <Text style={styles.title} numberOfLines={2}>
            {item.eventName}
          </Text>
        </View>
        <StatusBadge label={statusLabel} tone={tone} />
        {item.organizerName ? (
          <Text style={styles.meta} numberOfLines={1}>
            {item.organizerName}
          </Text>
        ) : null}
        {whenWhere ? (
          <View style={styles.metaRow}>
            <AppIcon name="calendar-outline" size={14} color={colors.gray500} />
            <Text style={styles.meta} numberOfLines={1}>
              {whenWhere}
            </Text>
          </View>
        ) : null}
        {optionLabel || fee ? (
          <View style={styles.metaRow}>
            <AppIcon name="pricetag-outline" size={14} color={colors.gray500} />
            <Text style={[styles.meta, isDue && styles.metaDue]} numberOfLines={1}>
              {[optionLabel, fee ? `${fee}${isDue || pastInvoice ? " due" : ""}` : null].filter(Boolean).join(" · ")}
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
      {explainer && (!primaryLabel || pastInvoice || item.eventResolution === "unrecoverable") ? <Text style={styles.hint}>{explainer}</Text> : null}
      {onPrimaryAction && primaryLabel ? (
        <Button
          label={primaryLabel}
          onPress={onPrimaryAction}
          variant={isDue || item.status === "info_requested" ? "primary" : "secondary"}
          icon={item.status === "info_requested" ? "chatbubble-ellipses-outline" : isDue || pastInvoice ? "card-outline" : "ticket-outline"}
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
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.line,
    padding: spacing.md,
  },
  cardDue: { borderColor: colors.amber200, backgroundColor: colors.amber50 },
  pressed: { opacity: 0.75 },
  row: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  thumb: { width: 72 },
  copy: { flex: 1, gap: 4, alignItems: "flex-start" },
  titleRow: { flexDirection: "row" },
  title: { flex: 1, fontFamily: fonts.displaySemi, fontSize: typeScale.bodyLarge, lineHeight: 21, color: colors.black },
  metaRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  meta: { ...typography.meta, flexShrink: 1 },
  metaDue: { color: colors.amber700, fontFamily: fonts.bodySemi },
  hint: { marginTop: spacing.sm, fontSize: typeScale.caption, fontFamily: fonts.bodySemi, color: colors.gray700 },
  primaryBtn: { marginTop: spacing.md },
})
