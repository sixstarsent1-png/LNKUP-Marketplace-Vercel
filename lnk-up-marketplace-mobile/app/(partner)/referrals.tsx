import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { SegmentedControl } from "@/components/ui/chip"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { formatMoneyOrZero } from "@/lib/api/format-money"
import { fetchPartnerReferrals, milestoneLabel, type PartnerReferral } from "@/lib/api/partner"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { useFocusResource } from "@/lib/hooks/use-focus-resource"
import { colors, radii, spacing, type StatusTone, typeScale } from "@/lib/theme"

type Kind = "organizers" | "vendors"

function milestoneTone(milestone: string): StatusTone {
  if (milestone === "reward_paid") return "paid"
  if (milestone === "activated" || milestone === "reward_pending") return "approved"
  if (milestone === "first_payment" || milestone === "opportunity_published") return "review"
  return "neutral"
}

function ReferralRow({ referral }: { referral: PartnerReferral }) {
  const joined = formatShortDate(referral.referralDate)
  const meta = [referral.city, joined ? `Joined ${joined}` : "", referral.remainingTerm].filter(Boolean).join(" · ")
  return (
    <View style={styles.row} testID={`partner-referral-${referral.id}`}>
      <View style={styles.rowTop}>
        <Text style={styles.name} numberOfLines={1}>
          {referral.displayName}
        </Text>
        <StatusBadge label={milestoneLabel(referral.milestone)} tone={milestoneTone(referral.milestone)} />
      </View>
      {meta ? <Meta>{meta}</Meta> : null}
      {referral.approvedEarnings > 0 ? (
        <Text style={styles.earned}>{formatMoneyOrZero(referral.approvedEarnings)} approved</Text>
      ) : null}
    </View>
  )
}

export default function PartnerReferralsScreen() {
  const { auth } = useAuth()
  const fetcher = useCallback(() => fetchPartnerReferrals(auth), [auth])
  const { data, loading, refreshing, error, reload } = useFocusResource(fetcher)
  const [kind, setKind] = useState<Kind>("organizers")

  const rows = data ? data[kind] : []

  return (
    <Screen
      title="Referrals"
      subtitle="People who joined LNKup through your links"
      refreshing={refreshing}
      onRefresh={() => void reload("refresh")}
    >
      {loading && !data ? <LoadingPanel label="Loading referrals…" variant="skeleton" count={3} /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void reload("initial")} />
      ) : null}

      {data ? (
        <View style={styles.stack} testID="partner-referrals">
          {error ? (
            <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void reload("refresh")} />
          ) : null}
          <StatGrid>
            <StatTile label="Link clicks" value={data.counts.clicks} />
            <StatTile label="Organizers joined" value={data.counts.registrations} />
            <StatTile label="Activated" value={data.counts.activated} />
          </StatGrid>

          <SegmentedControl
            options={[
              { value: "organizers", label: "Organizers", count: data.organizers.length },
              { value: "vendors", label: "Vendors", count: data.vendors.length },
            ]}
            value={kind}
            onChange={setKind}
          />

          {kind === "vendors" && !data.vendorReferralsRewarded && data.vendors.length > 0 ? (
            <Meta>Vendor sign-ups are tracked for your growth stats. Commissions come from organizer bookings.</Meta>
          ) : null}

          {rows.length === 0 ? (
            <EmptyPanel
              title={kind === "organizers" ? "No organizer referrals yet" : "No vendor referrals yet"}
              message="Share your link from Home. Referrals appear here as soon as someone signs up with it."
              icon="people-outline"
            />
          ) : (
            rows.map((referral) => <ReferralRow key={referral.id} referral={referral} />)
          )}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  row: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  rowTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.sm },
  name: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black, flex: 1 },
  earned: { fontSize: typeScale.footnote, fontWeight: "600", color: colors.gray700 },
})
