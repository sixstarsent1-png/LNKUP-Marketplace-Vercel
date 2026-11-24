import { StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { formatMoneyOrZero, type VendorWalletSummary } from "@/lib/api/vendor-wallet"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

/**
 * Amount due first, then lifetime booth spend and credits. Booth payments are spend, never
 * revenue; credits apply to booth fees and are not withdrawable.
 */
export function WalletSummary({ summary }: { summary: VendorWalletSummary }) {
  const currency = summary.currency ?? "USD"
  const due = summary.amountDue ?? 0
  const pastDue = summary.pastEventDue ?? 0
  return (
    <View style={styles.card} accessibilityRole="summary" testID="wallet-summary">
      <Text style={styles.label}>Amount due</Text>
      <Text style={styles.amount} adjustsFontSizeToFit numberOfLines={1} maxFontSizeMultiplier={1.3}>
        {formatMoneyOrZero(due, currency)}
      </Text>
      <Text style={styles.caption}>
        {due <= 0
          ? "You're all paid up."
          : pastDue > 0 && pastDue < due
            ? `Includes ${formatMoneyOrZero(pastDue, currency)} on invoices for past events`
            : pastDue > 0
              ? "Open invoices for past events"
              : `${summary.paymentRequiredCount ?? 0} booth fee${summary.paymentRequiredCount === 1 ? "" : "s"} to confirm your spots`}
      </Text>
      <View style={styles.divider} />
      <View style={styles.row}>
        <View style={styles.cell}>
          <Text style={styles.cellLabel}>Booth spend · all time</Text>
          <Text style={styles.cellValue}>{formatMoneyOrZero(summary.totalPaid, currency)}</Text>
        </View>
        <View style={styles.cell}>
          <Text style={styles.cellLabel}>Credits · booth fees only</Text>
          <Text style={styles.cellValue}>{formatMoneyOrZero(summary.availableCredits, currency)}</Text>
        </View>
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  card: { backgroundColor: colors.shell, borderRadius: radii.lg, padding: spacing.lg, gap: 2 },
  label: { fontFamily: fonts.bodySemi, fontSize: typeScale.footnote, color: colors.gray200 },
  amount: { fontFamily: fonts.display, fontSize: typeScale.large, color: colors.white },
  caption: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.lime },
  divider: { height: StyleSheet.hairlineWidth, backgroundColor: "rgba(255,255,255,0.2)", marginVertical: spacing.md },
  row: { flexDirection: "row", gap: spacing.lg },
  cell: { flex: 1, gap: 2 },
  cellLabel: { fontFamily: fonts.body, fontSize: typeScale.caption, color: colors.gray400 },
  cellValue: { fontFamily: fonts.bodyBold, fontSize: typeScale.bodyLarge, color: colors.white },
})
