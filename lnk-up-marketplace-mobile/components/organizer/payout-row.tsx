import { Pressable, StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { StatusBadge } from "@/components/ui/status-badge"
import { formatMoney } from "@/lib/api/format-money"
import type { OrganizerPayout, OrganizerPayoutStatus } from "@/lib/api/organizer-financial"
import { formatShortDate } from "@/lib/format/date"
import { colors, fonts, layout, radii, spacing, typeScale, typography, type StatusTone } from "@/lib/theme"

export function payoutStatusLabel(status: OrganizerPayoutStatus): { label: string; tone: StatusTone } {
  switch (status) {
    case "requested":
      return { label: "Requested", tone: "review" }
    case "approved":
      return { label: "Approved", tone: "approved" }
    case "processing":
      return { label: "On the way", tone: "due" }
    case "paid":
      return { label: "Paid", tone: "paid" }
    case "failed":
      return { label: "Failed", tone: "rejected" }
    case "cancelled":
      return { label: "Cancelled", tone: "neutral" }
    case "unknown":
      return { label: "Status unavailable", tone: "neutral" }
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

/** One cash-out: amount, method, date, status and the LNKup reference. */
export function PayoutRow({ payout, onPress }: { payout: OrganizerPayout; onPress?: () => void }) {
  const status = payoutStatusLabel(payout.status)
  const method = payout.type === "instant" ? "Instant" : "Standard"
  const date = formatShortDate(payout.paidAt ?? payout.createdAt) ?? ""
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      accessibilityRole={onPress ? "button" : undefined}
      accessibilityLabel={`${method} cash out of ${formatMoney(payout.amount)}, ${status.label}, ${date}, reference ${payout.reference}`}
      testID={`payout-row-${payout.id}`}
      style={({ pressed }) => [styles.row, pressed && onPress && styles.pressed]}
    >
      <View style={styles.text}>
        <Text style={styles.title} numberOfLines={1}>
          {method} cash out
        </Text>
        <Text style={styles.meta} numberOfLines={1}>
          {[date, payout.reference].filter(Boolean).join(" · ")}
        </Text>
      </View>
      <View style={styles.right}>
        <Text style={styles.amount}>{formatMoney(payout.amount)}</Text>
        <StatusBadge label={status.label} tone={status.tone} />
      </View>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 12,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
    borderRadius: radii.md,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    ...typography.label,
  },
  meta: {
    ...typography.meta,
  },
  right: {
    alignItems: "flex-end",
    gap: spacing.xs,
  },
  amount: {
    fontFamily: fonts.bodyBold,
    fontSize: typeScale.callout,
    color: colors.black,
  },
})
