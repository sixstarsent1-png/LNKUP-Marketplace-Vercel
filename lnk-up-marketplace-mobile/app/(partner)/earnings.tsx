import { useCallback } from "react"
import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { formatMoneyOrZero } from "@/lib/api/format-money"
import {
  fetchPartnerEarnings,
  ledgerStatusLabel,
  pendingTotal,
  termsSummary,
  type PartnerLedgerRow,
} from "@/lib/api/partner"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { useFocusResource } from "@/lib/hooks/use-focus-resource"
import { colors, radii, spacing, type StatusTone, typeScale } from "@/lib/theme"

const STATUS_TONES: Record<string, StatusTone> = {
  pending_hold: "review",
  awaiting_approval: "review",
  on_hold: "waitlist",
  approved: "approved",
  paid: "paid",
  reversed: "rejected",
  expired: "neutral",
}

const EVENT_LABELS: Record<string, string> = {
  connector_booking_fee: "Booth booking",
  connector_vendor_signup: "Vendor signup credit",
}

function eventLabel(eventType: string): string {
  if (eventType.startsWith("connector_rank_bonus")) return "Rank bonus"
  return EVENT_LABELS[eventType] ?? "Commission"
}

function LedgerRow({ row }: { row: PartnerLedgerRow }) {
  const when =
    row.status === "paid" && row.paidAt
      ? `Paid ${formatShortDate(row.paidAt)}`
      : row.status === "pending_hold" && row.availableAt
        ? `Hold ends ${formatShortDate(row.availableAt)}`
        : formatShortDate(row.createdAt)
  const meta = [eventLabel(row.eventType), row.relatedName, when].filter(Boolean).join(" · ")
  return (
    <View style={styles.row} testID={`partner-ledger-${row.id}`}>
      <View style={styles.rowTop}>
        <Text style={[styles.amount, row.status === "reversed" && styles.amountReversed]}>
          {formatMoneyOrZero(row.netAmount)}
        </Text>
        <StatusBadge label={ledgerStatusLabel(row.status)} tone={STATUS_TONES[row.status] ?? "neutral"} />
      </View>
      <Meta numberOfLines={2}>{meta}</Meta>
    </View>
  )
}

export default function PartnerEarningsScreen() {
  const { auth } = useAuth()
  const fetcher = useCallback(() => fetchPartnerEarnings(auth), [auth])
  const { data, loading, refreshing, error, reload } = useFocusResource(fetcher)

  return (
    <Screen
      title="Earnings"
      subtitle="Commissions recorded from your referrals"
      refreshing={refreshing}
      onRefresh={() => void reload("refresh")}
    >
      {loading && !data ? <LoadingPanel label="Loading earnings…" variant="skeleton" count={3} /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void reload("initial")} />
      ) : null}

      {data ? (
        <View style={styles.stack} testID="partner-earnings">
          {error ? (
            <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void reload("refresh")} />
          ) : null}

          <StatGrid>
            <StatTile label="Approved" value={formatMoneyOrZero(data.balances.approved)} tone="accent" hint="Payable" />
            <StatTile label="Pending" value={formatMoneyOrZero(pendingTotal(data.balances))} hint="Hold + review" />
            <StatTile label="Paid" value={formatMoneyOrZero(data.balances.paid)} />
          </StatGrid>
          {data.balances.reversed > 0 ? (
            <Meta>{formatMoneyOrZero(data.balances.reversed)} was reversed after refunds or chargebacks.</Meta>
          ) : null}

          <View style={styles.terms}>
            <Text style={styles.termsTitle}>Your terms</Text>
            <Meta>{termsSummary(data.terms)}</Meta>
            {data.nextPayout && !data.terms.termsPending ? <Meta>Next payable date: {data.nextPayout}</Meta> : null}
            {data.terms.minimumPayout > 0 ? (
              <Meta>LNKup pays approved commissions once they reach {formatMoneyOrZero(data.terms.minimumPayout)}.</Meta>
            ) : null}
          </View>

          <SectionTitle>Commission history</SectionTitle>
          {data.ledger.length === 0 ? (
            <EmptyPanel
              title="No commissions yet"
              message="Commissions are recorded when someone you referred completes a qualifying paid booking."
              icon="wallet-outline"
            />
          ) : (
            data.ledger.map((row) => <LedgerRow key={row.id} row={row} />)
          )}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  terms: {
    backgroundColor: colors.gray50,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
    gap: spacing.xs,
  },
  termsTitle: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  row: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  rowTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.sm },
  amount: { fontSize: typeScale.bodyLarge, fontWeight: "800", color: colors.black },
  amountReversed: { color: colors.gray500, textDecorationLine: "line-through" },
})
