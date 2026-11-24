import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { payoutStatusLabel } from "@/components/organizer/payout-row"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { cancelOrganizerPayout, fetchOrganizerPayouts, formatMoney, type OrganizerPayout } from "@/lib/api/organizer-financial"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { formatDateTime } from "@/lib/format/date"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"
import { payoutTimeline } from "@/lib/organizer/payout-timeline"
import { colors, fonts, spacing, typeScale, typography } from "@/lib/theme"

export default function OrganizerPayoutDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { auth } = useAuth()
  const [payout, setPayout] = useState<OrganizerPayout | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(
    async (force = false) => {
      setError(null)
      try {
        const list = await fetchOrganizerPayouts(auth, { force })
        setPayout(list.find((row) => row.id === String(id)) ?? null)
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
      }
    },
    [auth, id],
  )

  useFocusEffect(
    useCallback(() => {
      void load(true)
    }, [load]),
  )

  const [cancel, cancelling] = useSingleFlight(async () => {
    if (!payout) return
    const ok = await confirmAction({
      title: "Cancel this cash out?",
      message: "The amount goes back to your available balance.",
      confirmLabel: "Cancel cash out",
      cancelLabel: "Keep it",
      destructive: true,
    })
    if (!ok) return
    try {
      await cancelOrganizerPayout(auth, payout.id)
      showToast("Cash out cancelled. Your balance was restored.", "success")
      await load(true)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
  })

  const status = payout ? payoutStatusLabel(payout.status) : null

  return (
    <Screen title="Cash out" refreshing={false} onRefresh={() => void load(true)} header={<BackLink label="Payouts" fallbackHref={"/(organizer)/money?segment=payouts" as Href} />}>
      {loading && !payout ? <LoadingPanel label="Loading cash out…" variant="skeleton" count={2} /> : null}
      {!loading && error && !payout ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load(true)} /> : null}
      {!loading && !error && !payout ? (
        <EmptyPanel icon="time-outline" title="Cash out not found" message="It may be older than your recent history." actionLabel="Back to Money" onAction={() => router.replace("/(organizer)/money?segment=payouts" as Href)} />
      ) : null}

      {payout && status ? (
        <View style={styles.stack} testID="payout-detail">
          <View style={styles.hero}>
            <Text style={styles.amount}>{formatMoney(payout.amount)}</Text>
            <StatusBadge label={status.label} tone={status.tone} />
          </View>

          {payout.status === "failed" && payout.failureReason ? <InlineBanner tone="error" title="This cash out failed" message={payout.failureReason} /> : null}

          <ListGroup title="Status">
            {payoutTimeline(payout).map((step) => (
              <ListRow
                key={step.key}
                icon={step.done ? (step.tone === "error" ? "close-circle" : "checkmark-circle") : "ellipse-outline"}
                iconTone={step.tone === "error" ? "danger" : step.done ? "accent" : "default"}
                title={step.label}
                meta={step.at ? formatDateTime(step.at) : undefined}
              />
            ))}
          </ListGroup>

          <ListGroup title="Details">
            <ListRow title="Method" meta={payout.type === "instant" ? "Instant" : "Standard"} />
            <ListRow title="LNKup fee" rightSlot={<Text style={styles.value}>{formatMoney(payout.feeAmount)}</Text>} />
            <ListRow title="Sent to your bank" rightSlot={<Text style={styles.value}>{formatMoney(payout.netAmount)}</Text>} />
            <ListRow title="LNKup reference" meta={payout.reference} />
            {payout.providerRef ? <ListRow title="Bank transfer reference" meta={payout.providerRef} /> : null}
          </ListGroup>

          {payout.provider === "stripe_connect_payout" && payout.type === "instant" ? (
            <Meta>Your payout provider deducts its own instant fee from this transfer; LNKup takes no fee on it.</Meta>
          ) : null}

          {payout.canCancel ? (
            <Button label="Cancel cash out" loadingLabel="Cancelling…" variant="danger" loading={cancelling} onPress={() => void cancel()} accessibilityLabel={`Cancel cash out of ${formatMoney(payout.amount)}`} />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
  hero: { gap: spacing.sm, alignItems: "flex-start" },
  amount: { fontFamily: fonts.display, fontSize: typeScale.hero, color: colors.black },
  value: { ...typography.label },
})
