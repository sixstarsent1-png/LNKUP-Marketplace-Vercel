import { useCallback, useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, useLocalSearchParams, type Href, useFocusEffect } from "expo-router"
import * as WebBrowser from "expo-web-browser"

import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { Skeleton, SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Eyebrow, Meta } from "@/components/ui/typography"
import { newClientToken } from "@/lib/api/client-token"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  CONNECT_APP_DEEP_LINK,
  activityStatusLabel,
  cancelOrganizerPayout,
  cashOutReasonCopy,
  connectStateCopy,
  fetchConnectLoginLink,
  fetchConnectOnboardingLink,
  fetchOrganizerMoney,
  fetchOrganizerMoneyEvents,
  fetchOrganizerPayouts,
  formatMoney,
  formatMoneyOrZero,
  payoutHistoryStatusLabel,
  refreshConnectStatus,
  requestOrganizerCashout,
  type MoneyCategory,
  type OrganizerEventMoneySummary,
  type OrganizerFinancialActivityItem,
  type OrganizerFinancialSnapshot,
  type OrganizerPayout,
} from "@/lib/api/organizer-financial"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import { useKeyedSingleFlight, useSingleFlight } from "@/lib/hooks/use-single-flight"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

WebBrowser.maybeCompleteAuthSession()

const HERO_KEY = "available"
const PAYOUT_HISTORY_LIMIT = 10
const UNAVAILABLE_LABEL = "Unavailable"

type PayoutType = "standard" | "instant"

const PAYOUT_TYPE_OPTIONS: ReadonlyArray<{ value: PayoutType; label: string }> = [
  { value: "standard", label: "Standard" },
  { value: "instant", label: "Instant" },
]

/**
 * Which payout type the form is on and its limit. Defaults to Instant only when Standard has
 * nothing to send but Instant does (card funds still settling for Standard).
 */
function resolveCashoutOptions(
  summary: OrganizerFinancialSnapshot | null,
  choice: PayoutType | null,
): { type: PayoutType; limit: number | undefined; showInstant: boolean; canInstant: boolean } {
  const canInstant = Boolean(summary?.canInstantCashout)
  const showInstant = summary?.canInstantCashout !== undefined
  const standardHasFunds = !summary?.balanceUnavailable && (summary?.availableBalance ?? 0) > 0
  const preferred: PayoutType = choice ?? (!standardHasFunds && canInstant ? "instant" : "standard")
  const type: PayoutType = preferred === "instant" && showInstant ? "instant" : "standard"
  return {
    type,
    limit: type === "instant" ? summary?.instantAvailable : summary?.availableBalance,
    showInstant,
    canInstant: showInstant && canInstant,
  }
}

export default function OrganizerMoneyScreen() {
  const { auth } = useAuth()
  const { connect: connectParam } = useLocalSearchParams<{ connect?: string }>()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [summary, setSummary] = useState<OrganizerFinancialSnapshot | null>(null)
  const [activity, setActivity] = useState<OrganizerFinancialActivityItem[]>([])
  const [events, setEvents] = useState<OrganizerEventMoneySummary[]>([])
  const [payouts, setPayouts] = useState<OrganizerPayout[] | null>(null)
  const [payoutsError, setPayoutsError] = useState<string | null>(null)
  const [connectMessage, setConnectMessage] = useState<string | null>(null)
  const [cashoutAmount, setCashoutAmount] = useState("")
  const [payoutChoice, setPayoutChoice] = useState<PayoutType | null>(null)
  const [cashoutError, setCashoutError] = useState<string | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const didFocusLoad = useRef(false)
  const handledConnectParam = useRef<string | null>(null)
  const summaryRef = useRef<OrganizerFinancialSnapshot | null>(null)
  summaryRef.current = summary

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else if (mode === "initial") setLoading(true)
      setError(null)
      setRefreshError(null)
      const force = mode === "refresh"
      const payoutsTask = fetchOrganizerPayouts(auth, { force }).then(
        (list) => {
          setPayouts(list)
          setPayoutsError(null)
        },
        (err: unknown) => setPayoutsError(appErrorFromWp(err).message),
      )
      try {
        const data = await fetchOrganizerMoney(auth, { force })
        setSummary(data.summary)
        setActivity(data.activity)
        const eventMoney = await fetchOrganizerMoneyEvents(auth).catch(() => [])
        setEvents(eventMoney)
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && summaryRef.current) {
          setRefreshError(shaped.message)
        } else {
          setError(shaped)
        }
      } finally {
        await payoutsTask
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  useEffect(() => {
    if (connectParam !== "return" && connectParam !== "refresh") return
    if (handledConnectParam.current === connectParam) return
    handledConnectParam.current = connectParam
    void (async () => {
      setConnectMessage("Updating payout status…")
      await refreshConnectStatus(auth).catch(() => null)
      await load("refresh")
      setConnectMessage(null)
      router.setParams({ connect: undefined })
    })()
  }, [auth, connectParam, load])

  const [setupPayouts, onboardBusy] = useSingleFlight(async () => {
    setConnectMessage(null)
    try {
      const { url } = await fetchConnectOnboardingLink(auth)
      await WebBrowser.openAuthSessionAsync(url, CONNECT_APP_DEEP_LINK)
      setConnectMessage("Updating payout status…")
      await refreshConnectStatus(auth).catch(() => null)
      await load("refresh")
      setConnectMessage(null)
    } catch (err) {
      setConnectMessage(appErrorFromWp(err).message)
    }
  })

  const [managePayouts, manageBusy] = useSingleFlight(async () => {
    setConnectMessage(null)
    try {
      const { url } = await fetchConnectLoginLink(auth)
      await WebBrowser.openBrowserAsync(url)
    } catch (err) {
      setConnectMessage(appErrorFromWp(err).message)
    }
  })

  const [cashout, cashoutBusy] = useSingleFlight(async () => {
    const amount = Number(cashoutAmount)
    const currencyCode = summary?.currency ?? "USD"
    const options = resolveCashoutOptions(summary, payoutChoice)
    const isInstant = options.type === "instant"
    setCashoutError(null)
    if (!Number.isFinite(amount) || amount <= 0) {
      setCashoutError("Enter an amount greater than zero.")
      return
    }
    if (options.limit != null && amount > options.limit) {
      setCashoutError(
        `${isInstant ? "Instant available" : "Available"} is ${formatMoneyOrZero(options.limit, currencyCode)}.`,
      )
      return
    }
    // One token per confirmed intent: a retry after a dropped response returns the same payout.
    const clientToken = newClientToken()
    const confirmed = await confirmAction({
      title: isInstant ? "Confirm instant cash out" : "Confirm cash out",
      message: isInstant
        ? `Instant cash out ${formatMoney(amount, currencyCode)} to your debit card or bank? Stripe charges an instant payout fee.`
        : `Cash out ${formatMoney(amount, currencyCode)} to your payout account?`,
      confirmLabel: isInstant ? "Cash out instantly" : "Cash out",
    })
    if (!confirmed) return
    try {
      const result = await requestOrganizerCashout(auth, amount, options.type, clientToken)
      showToast(result.message ?? "Cash out requested.", "success")
      setCashoutAmount("")
      await load("refresh")
    } catch (err) {
      setCashoutError(appErrorFromWp(err).message)
    }
  })

  const [cancelPayout, isCancelling] = useKeyedSingleFlight(async (payoutId: string) => {
    const confirmed = await confirmAction({
      title: "Cancel this cash out?",
      message: "The amount goes back to your available balance.",
      confirmLabel: "Cancel cash out",
      cancelLabel: "Keep it",
      destructive: true,
    })
    if (!confirmed) return
    try {
      await cancelOrganizerPayout(auth, payoutId)
      showToast("Cash out cancelled. Your balance was restored.", "success")
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
    await load("refresh")
  })

  const balancesUnknown =
    summary != null &&
    summary.categories.length === 0 &&
    summary.collectedToDate == null &&
    summary.pendingPayments == null &&
    summary.availableBalance == null

  const currency = summary?.currency ?? "USD"
  const paymentDue = summary?.paymentRequiredCount ?? 0
  const connect = summary?.connect
  const connectCopy = connect ? connectStateCopy(connect) : null
  const availableAmt = summary?.availableBalance ?? 0
  const heroCategory = summary?.categories.find((item) => item.key === HERO_KEY)
  const netCategory = summary?.categories.find((item) => item.key === "net")
  const listCategories =
    summary?.categories.filter((item) => item.key !== HERO_KEY && item !== netCategory) ?? []
  const heroAmount = heroCategory?.amount ?? availableAmt
  const heroUnavailable = Boolean(heroCategory?.unavailable || summary?.balanceUnavailable)
  const heroText = heroUnavailable ? UNAVAILABLE_LABEL : formatMoneyOrZero(heroAmount, currency)
  const standardEnabled = Boolean(connect?.canCashout) && !heroUnavailable && heroAmount > 0
  const cashoutOptions = resolveCashoutOptions(summary, payoutChoice)
  const isInstant = cashoutOptions.type === "instant"
  const cashoutEnabled = isInstant ? cashoutOptions.canInstant : standardEnabled
  const instantText = formatMoneyOrZero(summary?.instantAvailable, currency)
  const cashOutReason = cashOutReasonCopy(summary?.cashOutReason)
  const anyConnectBusy = onboardBusy || manageBusy
  const visiblePayouts = (payouts ?? []).slice(0, PAYOUT_HISTORY_LIMIT)

  return (
    <Screen
      title="Money"
      subtitle="Vendor booth fees and cash outs"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      {loading && !summary ? (
        <View style={styles.stack} accessibilityLabel="Loading money">
          <Skeleton height={140} radius={radii.lg} />
          <SkeletonStats count={2} />
          <SkeletonList count={2} />
        </View>
      ) : null}

      {!loading && error && !summary ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {summary ? (
        <View style={styles.stack}>
          {refreshError ? (
            <InlineBanner
              tone="error"
              message={`Couldn’t refresh: ${refreshError}`}
              actionLabel="Retry"
              onAction={() => void load("refresh")}
            />
          ) : null}

          {balancesUnknown ? (
            <EmptyPanel
              icon="cash-outline"
              title="Balances unavailable"
              message="We couldn’t load your balances right now."
              actionLabel="Try again"
              onAction={() => void load("refresh")}
            />
          ) : (
            <>
              <View
                style={styles.hero}
                accessible
                accessibilityLabel={`${heroCategory?.label ?? "Available to cash out"} ${heroText}${
                  cashoutOptions.canInstant ? `. Instant payout available now: ${instantText}` : ""
                }`}
              >
                <Eyebrow style={styles.heroEyebrow}>{heroCategory?.label ?? "Available to cash out"}</Eyebrow>
                <Text style={styles.heroValue} adjustsFontSizeToFit numberOfLines={1}>
                  {heroText}
                </Text>
                {cashoutOptions.canInstant ? (
                  <View style={styles.heroInstant}>
                    <Text style={styles.heroInstantText} numberOfLines={1}>
                      Instant payout available now: {instantText}
                    </Text>
                  </View>
                ) : null}
                <Meta style={styles.heroBody}>
                  {netCategory
                    ? `${netCategory.label}: ${formatMoneyOrZero(netCategory.amount, currency)}`
                    : (heroCategory?.hint ?? "Ready to send to your bank.")}
                </Meta>
              </View>

              {listCategories.length > 0 ? (
                <MoneyBreakdown categories={listCategories} currency={currency} />
              ) : (
                <StatGrid>
                  <StatTile
                    icon="cash-outline"
                    label="Collected"
                    value={formatMoneyOrZero(summary.collectedToDate, currency)}
                  />
                  <StatTile
                    icon="hourglass-outline"
                    label="Pending"
                    value={formatMoneyOrZero(summary.pendingPayments, currency)}
                  />
                </StatGrid>
              )}
            </>
          )}

          {paymentDue > 0 ? (
            <InlineBanner
              tone="attention"
              title={`${paymentDue} vendor${paymentDue === 1 ? "" : "s"} still need to pay`}
              message="See who hasn’t paid yet in Vendors."
              actionLabel="Review"
              onAction={() => router.push("/(organizer)/vendors?tab=requests&filter=awaiting_payment" as Href)}
            />
          ) : null}

          {connect && connectCopy ? (
            <Surface style={styles.card}>
              <View style={styles.payoutHeader}>
                <SectionHeader title="Payouts" icon="card-outline" />
                <StatusBadge label={connectCopy.badge} tone={connectCopy.tone} />
              </View>
              <Meta>{connectCopy.message}</Meta>
              {connect.requirements.length > 0 ? (
                <View style={styles.requirements} accessibilityLabel="Details still needed">
                  {connect.requirements.map((item) => (
                    <Meta key={item}>• {item}</Meta>
                  ))}
                </View>
              ) : null}
              {connectMessage ? <InlineBanner tone="info" message={connectMessage} /> : null}

              {connect.canCashout ? (
                <View style={styles.cashoutBlock}>
                  {cashoutOptions.showInstant ? (
                    <SegmentedControl
                      options={PAYOUT_TYPE_OPTIONS}
                      value={cashoutOptions.type}
                      onChange={(next) => {
                        setCashoutError(null)
                        setPayoutChoice(next)
                      }}
                    />
                  ) : null}
                  <FormField
                    label="Cash out amount"
                    hint={isInstant ? `Instant available: ${instantText}` : `Available to cash out: ${heroText}`}
                    value={cashoutAmount}
                    onChangeText={(raw) => {
                      setCashoutError(null)
                      setCashoutAmount(raw.replace(/[^0-9.]/g, ""))
                    }}
                    placeholder="0.00"
                    keyboardType="decimal-pad"
                    tone="muted"
                    error={cashoutError ?? undefined}
                    editable={cashoutEnabled}
                  />
                  <Button
                    label={isInstant ? "Cash out instantly" : "Cash out"}
                    loadingLabel="Requesting…"
                    icon={isInstant ? "flash-outline" : "arrow-down-circle-outline"}
                    loading={cashoutBusy}
                    disabled={!cashoutEnabled || anyConnectBusy}
                    onPress={() => void cashout()}
                  />
                  {isInstant && cashoutEnabled ? (
                    <Meta>Arrives in minutes. Stripe charges an instant payout fee (about 1.5%).</Meta>
                  ) : null}
                  {!isInstant && !standardEnabled && cashoutOptions.canInstant ? (
                    <Meta>Standard payouts open once card payments finish processing. Switch to Instant to cash out now.</Meta>
                  ) : null}
                  {!cashoutEnabled && !(!isInstant && cashoutOptions.canInstant) ? (
                    <Meta>{cashOutReason ?? "Nothing to cash out yet. Paid booth fees show here once they settle."}</Meta>
                  ) : null}
                </View>
              ) : null}

              <View style={styles.secondaryRow}>
                {connect.canOnboard ? (
                  <Button
                    label={connect.kind === "not_started" ? "Set up payouts" : "Finish payout setup"}
                    loadingLabel="Opening…"
                    icon="shield-checkmark-outline"
                    variant={connect.canCashout ? "secondary" : "primary"}
                    size={connect.canCashout ? "sm" : "md"}
                    style={styles.flex}
                    loading={onboardBusy}
                    disabled={manageBusy || cashoutBusy}
                    onPress={() => void setupPayouts()}
                  />
                ) : null}
                {connect.kind === "active" ? (
                  <Button
                    label="Manage payouts"
                    loadingLabel="Opening…"
                    variant="secondary"
                    size="sm"
                    style={styles.flex}
                    loading={manageBusy}
                    disabled={onboardBusy || cashoutBusy}
                    onPress={() => void managePayouts()}
                  />
                ) : null}
                {summary.wordpressFinancialUrl ? (
                  <Button
                    label="More on web"
                    icon="open-outline"
                    variant="secondary"
                    size="sm"
                    style={styles.flex}
                    onPress={() => void openExternalUrl(summary.wordpressFinancialUrl as string)}
                  />
                ) : null}
              </View>
            </Surface>
          ) : null}

          <View style={styles.section}>
            <SectionHeader title="Cash out history" icon="time-outline" />
            {payoutsError && payouts == null ? (
              <InlineBanner
                tone="error"
                message={`Couldn’t load cash outs: ${payoutsError}`}
                actionLabel="Retry"
                onAction={() => void load("refresh")}
              />
            ) : visiblePayouts.length === 0 ? (
              <EmptyPanel
                icon="time-outline"
                title="No cash outs yet"
                message="Cash outs you request will show here with their status."
              />
            ) : (
              <ListGroup>
                {visiblePayouts.map((payout) => (
                  <PayoutRow
                    key={payout.id}
                    payout={payout}
                    currency={currency}
                    cancelling={isCancelling(payout.id)}
                    onCancel={() => void cancelPayout(payout.id)}
                  />
                ))}
              </ListGroup>
            )}
          </View>

          {events.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader title="By event" icon="calendar-outline" />
              <ListGroup>
                {events.map((event) => (
                  <ListRow
                    key={event.eventId}
                    title={event.eventName ?? "Event"}
                    meta={`${event.paidVendorCount ?? 0} paid · ${event.paymentRequiredCount ?? 0} due · ${formatMoneyOrZero(event.pending, event.currency ?? currency)} pending`}
                    rightSlot={
                      <Text style={styles.amount}>
                        {formatMoneyOrZero(event.collected, event.currency ?? currency)}
                      </Text>
                    }
                    onPress={() => router.push(`/(organizer)/events/${event.eventId}?tab=payments` as Href)}
                  />
                ))}
              </ListGroup>
            </View>
          ) : null}

          <View style={styles.section}>
            <SectionHeader title="Recent activity" icon="receipt-outline" />
            {activity.length === 0 ? (
              <EmptyPanel
                icon="receipt-outline"
                title="No fee activity yet"
                message="Collected booth payments and payouts will appear here."
              />
            ) : (
              <ListGroup>
                {activity.map((item) => (
                  <ListRow
                    key={item.id}
                    title={item.label}
                    meta={[item.eventName, activityStatusLabel(item.status), formatShortDate(item.createdAt)]
                      .filter(Boolean)
                      .join(" · ")}
                    rightSlot={
                      <Text style={styles.amount}>
                        {formatMoney(item.amount, item.currency ?? currency)}
                      </Text>
                    }
                    onPress={
                      item.eventId
                        ? () => router.push(`/(organizer)/events/${item.eventId}` as Href)
                        : undefined
                    }
                  />
                ))}
              </ListGroup>
            )}
          </View>
        </View>
      ) : null}
    </Screen>
  )
}

function MoneyBreakdown({ categories, currency }: { categories: MoneyCategory[]; currency: string }) {
  return (
    <ListGroup>
      {categories.map((category) => (
        <ListRow
          key={category.key}
          title={category.label}
          meta={category.hint}
          rightSlot={
            <Text style={styles.amount}>
              {category.unavailable ? UNAVAILABLE_LABEL : formatMoneyOrZero(category.amount, currency)}
            </Text>
          }
        />
      ))}
    </ListGroup>
  )
}

function PayoutRow({
  payout,
  currency,
  cancelling,
  onCancel,
}: {
  payout: OrganizerPayout
  currency: string
  cancelling: boolean
  onCancel: () => void
}) {
  const date = formatShortDate(payout.paidAt ?? payout.createdAt)
  const parts = [
    payoutHistoryStatusLabel(payout.status),
    payout.type === "instant" ? "Instant" : null,
    payout.feeAmount > 0 ? `Fee ${formatMoney(payout.feeAmount, currency)}` : null,
    date,
  ].filter(Boolean)
  return (
    <View>
      <ListRow
        title={formatMoney(payout.amount, currency) ?? "Cash out"}
        meta={parts.join(" · ")}
        rightSlot={
          payout.canCancel ? (
            <Button
              label="Cancel"
              loadingLabel="Cancelling…"
              variant="secondary"
              size="sm"
              loading={cancelling}
              onPress={onCancel}
              accessibilityLabel={`Cancel cash out of ${formatMoney(payout.amount, currency)}`}
            />
          ) : undefined
        }
      />
      {payout.status === "failed" && payout.failureReason ? (
        <Meta style={styles.failure}>{payout.failureReason}</Meta>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.lg,
  },
  flex: {
    flex: 1,
  },
  hero: {
    backgroundColor: colors.lime,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.xs,
  },
  heroEyebrow: {
    color: colors.gray900,
  },
  heroValue: {
    fontFamily: fonts.display,
    fontSize: typeScale.hero,
    lineHeight: 44,
    color: colors.black,
  },
  heroBody: {
    fontSize: typeScale.body,
    color: colors.gray900,
  },
  heroInstant: {
    alignSelf: "flex-start",
    backgroundColor: colors.black,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
  },
  heroInstantText: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.lime,
  },
  card: {
    gap: spacing.sm,
  },
  payoutHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.sm,
  },
  requirements: {
    gap: spacing.xs,
  },
  cashoutBlock: {
    marginTop: spacing.xs,
    gap: spacing.sm,
  },
  secondaryRow: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  section: {
    gap: spacing.md,
  },
  amount: {
    fontSize: typeScale.body,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
  failure: {
    color: colors.red600,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.sm,
  },
})
