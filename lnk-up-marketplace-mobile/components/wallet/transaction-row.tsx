import { memo } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { Meta } from "@/components/ui/typography"
import { formatMoney } from "@/lib/api/format-money"
import { formatLedgerDate, type VendorTransaction } from "@/lib/api/vendor-transactions"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

const STATUS_COPY: Record<VendorTransaction["status"], { label: string; color: string }> = {
  paid: { label: "Paid", color: colors.green700 },
  refunded: { label: "Refunded", color: colors.gray700 },
  failed: { label: "Failed", color: colors.red600 },
  pending: { label: "Pending", color: colors.amber700 },
  removed: { label: "No longer due", color: colors.gray500 },
  other: { label: "Recorded", color: colors.gray500 },
}

function iconFor(t: VendorTransaction): AppIconName {
  if (t.status === "refunded" || t.type.includes("refund")) return "return-down-back-outline"
  if (t.type.includes("credit")) return "gift-outline"
  if (t.status === "failed") return "alert-circle-outline"
  return "card-outline"
}

/** Ledger row: event, type, date, amount and status in words (never color alone). */
export const TransactionRow = memo(function TransactionRow({
  item,
  onPress,
}: {
  item: VendorTransaction
  onPress?: () => void
}) {
  const status = STATUS_COPY[item.status]
  const date = formatLedgerDate(item.occurredAt)
  const title = item.eventTitle || item.typeLabel
  const amount = item.amount > 0 ? formatMoney(item.amount, item.currency) : "No charge"
  const signed = item.status === "refunded" && item.amount > 0 ? `+${amount}` : amount
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      accessibilityRole={onPress ? "button" : undefined}
      accessibilityLabel={[title, item.typeLabel, date, signed, status.label].filter(Boolean).join(", ")}
    >
      <View style={styles.icon}>
        <AppIcon name={iconFor(item)} size={18} color={colors.ink} />
      </View>
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={1}>
          {title}
        </Text>
        <Meta numberOfLines={1}>{[item.eventTitle ? item.typeLabel : null, date].filter(Boolean).join(" · ")}</Meta>
      </View>
      <View style={styles.trailing}>
        <Text style={styles.amount}>{signed}</Text>
        <Text style={[styles.status, { color: status.color }]}>{status.label}</Text>
      </View>
      {onPress ? <AppIcon name="chevron-forward" size={16} color={colors.gray400} /> : null}
    </Pressable>
  )
})

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 12,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
  },
  pressed: { backgroundColor: colors.gray50 },
  icon: {
    width: 36,
    height: 36,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  body: { flex: 1, gap: 2 },
  title: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  trailing: { alignItems: "flex-end", gap: 2 },
  amount: { fontFamily: fonts.bodyBold, fontSize: typeScale.callout, color: colors.ink },
  status: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption },
})
