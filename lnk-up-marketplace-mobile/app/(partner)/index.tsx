import { useCallback } from "react"
import { StyleSheet, View } from "react-native"
import { router } from "expo-router"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ShareLinkCard } from "@/components/partner/share-link-card"
import { PushPermissionPrompt } from "@/components/push-permission-prompt"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import { formatMoneyOrZero } from "@/lib/api/format-money"
import { fetchPartnerDashboard, pendingTotal, termsSummary, type PartnerDashboard } from "@/lib/api/partner"
import { useAuth } from "@/lib/auth/auth-context"
import { useFocusResource } from "@/lib/hooks/use-focus-resource"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

function shareLinks(data: PartnerDashboard) {
  const city = data.links.city ? ` in ${data.links.city}` : ""
  return [
    {
      key: "organizer",
      label: "Invite an organizer",
      hint: "For people who run markets, festivals and pop-ups.",
      url: data.links.organizer,
      message: `Run vendor applications for your events on LNKup${city}.`,
    },
    {
      key: "vendor",
      label: "Invite a vendor",
      hint: data.terms.vendorReferralsRewarded
        ? "Vendor referrals can earn under your terms."
        : "Vendor sign-ups are tracked; commissions come from organizer bookings.",
      url: data.links.vendor,
      message: `Find booth opportunities${city} on LNKup.`,
    },
  ]
}

export default function PartnerHomeScreen() {
  const { auth, session } = useAuth()
  const fetcher = useCallback(() => fetchPartnerDashboard(auth), [auth])
  const { data, loading, refreshing, error, reload } = useFocusResource(fetcher)

  return (
    <Screen
      title="Home"
      subtitle={data ? data.programLabel : "Partner"}
      refreshing={refreshing}
      onRefresh={() => void reload("refresh")}
      rightSlot={
        <Button
          label="Inbox"
          size="sm"
          variant="ghost"
          icon="notifications-outline"
          onPress={() => router.push("/(partner)/notifications")}
          accessibilityLabel="Open notifications"
        />
      }
    >
      {loading && !data ? <LoadingPanel label="Loading your partner dashboard…" variant="skeleton" count={2} /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void reload("initial")} />
      ) : null}

      {data ? (
        <View style={styles.stack} testID="partner-home">
          {error ? (
            <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void reload("refresh")} />
          ) : null}

          <View style={styles.hero}>
            <Eyebrow style={styles.heroEyebrow}>{data.programLabel}</Eyebrow>
            <Text style={styles.heroTitle}>
              {data.organization || session?.user.name || "Welcome back"}
            </Text>
            <Text style={styles.heroBody}>{termsSummary(data.terms)}</Text>
          </View>

          {data.terms.termsPending ? (
            <InlineBanner
              tone="info"
              message="LNKup is finalizing your commission terms. Share your links now; referrals are tracked from the first click."
            />
          ) : null}

          <PushPermissionPrompt role="partner" />

          <ShareLinkCard code={data.code} links={shareLinks(data)} />

          <SectionTitle>Your referrals</SectionTitle>
          <StatGrid>
            <StatTile
              label="Organizers joined"
              value={data.stats.organizerSignups}
              icon="business-outline"
              onPress={() => router.push("/(partner)/referrals")}
            />
            <StatTile
              label="Vendors joined"
              value={data.stats.vendorSignups}
              icon="storefront-outline"
              onPress={() => router.push("/(partner)/referrals")}
            />
            <StatTile label="Paid bookings" value={data.stats.paidBookings} icon="ticket-outline" />
          </StatGrid>

          <SectionTitle>Earnings</SectionTitle>
          <StatGrid>
            <StatTile
              label="Approved"
              value={formatMoneyOrZero(data.balances.approved)}
              tone="accent"
              onPress={() => router.push("/(partner)/earnings")}
            />
            <StatTile
              label="Pending"
              value={formatMoneyOrZero(pendingTotal(data.balances))}
              hint="Hold + review"
              onPress={() => router.push("/(partner)/earnings")}
            />
            <StatTile label="Paid" value={formatMoneyOrZero(data.balances.paid)} />
          </StatGrid>
          {data.balances.estimated > 0 ? (
            <Meta>
              Estimated from referred bookings: {formatMoneyOrZero(data.balances.estimated)}. Estimates are not
              payable until bookings complete and LNKup approves them.
            </Meta>
          ) : null}

          <Button
            label="Submit an organizer lead"
            variant="secondary"
            icon="flag-outline"
            onPress={() => router.push("/(partner)/leads")}
          />
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  hero: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    borderTopWidth: 3,
    borderTopColor: colors.lime,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  heroEyebrow: { color: colors.lime },
  heroTitle: {
    fontFamily: fonts.display,
    fontSize: typeScale.headline,
    lineHeight: 26,
    color: colors.white,
  },
  heroBody: {
    fontFamily: fonts.body,
    fontSize: typeScale.body,
    lineHeight: 20,
    color: colors.gray200,
  },
})
