import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon } from "@/components/ui/app-icon"
import {
  formatMoney,
  type VendorFinancialActivityItem,
} from "@/lib/api/vendor-wallet"
import { colors, fonts, layout, radii, spacing, typography } from "@/lib/theme"

export function ActivityRow({
  item,
  onPress,
}: {
  item: VendorFinancialActivityItem
  onPress?: () => void
}) {
  const amount =
    item.amount != null ? formatMoney(item.amount, item.currency ?? "USD") : undefined
  const meta = [item.status, item.createdAt].filter(Boolean).join(" · ")
  const isPaid = item.status === "paid"

  const body = (
    <>
      <View style={[styles.icon, isPaid && styles.iconPaid]}>
        <AppIcon
          name={isPaid ? "checkmark" : "time-outline"}
          size={16}
          color={isPaid ? colors.green700 : colors.gray700}
        />
      </View>
      <View style={styles.text}>
        <Text style={styles.label} numberOfLines={2}>
          {item.label}
        </Text>
        {meta ? <Text style={styles.meta}>{meta}</Text> : null}
      </View>
      {amount ? <Text style={styles.amount}>{amount}</Text> : null}
      {onPress ? <AppIcon name="chevron-forward" size={16} color={colors.gray500} /> : null}
    </>
  )

  if (onPress) {
    return (
      <Pressable
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={[item.label, amount, meta].filter(Boolean).join(", ")}
      >
        {body}
      </Pressable>
    )
  }

  return <View style={styles.row}>{body}</View>
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 8,
    backgroundColor: colors.white,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  icon: {
    width: 32,
    height: 32,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  iconPaid: {
    backgroundColor: colors.green50,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  label: {
    ...typography.label,
  },
  meta: {
    ...typography.meta,
    fontSize: 12,
    textTransform: "capitalize",
  },
  amount: {
    fontSize: 14,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
})
