import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, useLocalSearchParams, type Href, useFocusEffect } from "expo-router"
import * as WebBrowser from "expo-web-browser"

import { PayoutRow } from "@/components/organizer/payout-row"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { Skeleton, SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Eyebrow, Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  CONNECT_APP_DEEP_LINK,
  activityStatusLabel,
  cashOutReasonCopy,
  connectStateCopy,
  fetchConnectLoginLink,
  fetchConnectOnboardingLink,
  fetchOrganizerMoney,
  fetchOrganizerPayouts,
  formatMoney,
  formatMoneyOrZero,
  refreshConnectStatus,
  type MoneyCategory,
  type OrganizerFinancialActivityItem,
  type OrganizerFinancialSnapshot,
  type OrganizerPayout,
} from "@/lib/api/organizer-financial"
import { fetchOrganizerEvents, type OrgEventCard } from "@/lib/api/organizer-mobile"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

WebBrowser.maybeCompleteAuthSession()

type Segment = "overview" | "transactions" | "payouts" | "invoices"
const SEGMENTS: readonly Segment[] = ["overview", "transactions", "payouts", "invoices"]
const UNAVAILABLE_LABEL = "Unavailable"

function parseSegment(value: string | string[] | undefined): Segment {
  const raw = Array.isArray(value) ? value[0] : value
  return SEGMENTS.find((s) => s === raw) ?? "overview"
}

export default function OrganizerMoneyScreen() {
  const { auth } = useAuth()
  const { connect: connectParam, segment: segmentParam } = useLocalSearchParams<{ connect?: string; segment?: string }>()
  const [segment, setSegment] = useState<Segment>(() => parseSegment(segmentParam))
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [summary, setSummary] = useState<OrganizerFinancialSnapshot | null>(null)
  const [activity, setActivity] = useState<OrganizerFinancialActivityItem[]>([])
  const [events, setEvents] = useState<OrgEventCard[] | null>(null)
  const [payouts, setPayouts] = useState<OrganizerPayout[] | null>(null)
  const [payoutsError, setPayoutsError] = useState<string | null>(null)
  const [connectMessage, setConnectMessage] = useState<string | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const didFocusLoad = useRef(false)
  const handledConnectParam = useRef<string | null>(null)
  const summaryRef = useRef<OrganizerFinancialSnapshot | null>(null)
  summaryRef.current = summary

  useEffect(() => {
    if (segmentParam) setSegment(parseSegment(segmentParam))
  }, [segmentParam])

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
      const eventsTask = auth
        ? fetchOrganizerEvents(auth, {}, force).then(
            (result) => setEvents(result?.events ?? []),
            () => setEvents([]),
          )
        : Promise.resolve()
      try {
        const data = await fetchOrganizerMoney(auth, { force })
        setSummary(data.summary)
        setActivity(data.activity)
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && summaryRef.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        await Promise.all([payoutsTask, eventsTask])
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

  const currency = summary?.currency ?? "USD"
  const connect = summary?.connect
  const connectCopy = connect ? connectStateCopy(connect) : null
  const availableCategory = summary?.categories.find((item) => item.key === "available")
  const availableUnavailable = Boolean(availableCategory?.unavailable || summary?.balanceUnavailable)
  const available = availableCategory?.amount ?? summary?.availableBalance ?? 0
  const instantAvailable = summary?.canInstantCashout ? summary.instantAvailable : undefined
  const canCashOut = Boolean(connect?.canCashout) && !availableUnavailable && (available > 0 || (instantAvailable ?? 0) > 0)
  const cashOutReason = cashOutReasonCopy(summary?.cashOutReason)
  const netCategory = summary?.categories.find((c) => c.key === "net")
  const breakdown = (summary?.categories ?? []).filter((c) => c.key !== "available" && c.key !== "net")
  const eventsWithMoney = useMemo(
    () => (events ?? []).filter((e) => e.money && (e.money.collectedGross > 0 || e.money.outstanding > 0 || e.money.paidBookings > 0)),
    [events],
  )
  const receivables = eventsWithMoney.reduce((sum, e) => sum + (e.money?.outstanding ?? 0), 0)
  const paymentDue = summary?.paymentRequiredCount ?? 0

  return (
    <Screen title="Money" refreshing={refreshing} onRefresh={() => void load("refresh")}>
      {loading && !summary ? (
        <View style={styles.stack} accessibilityLabel="Loading money">
          <Skeleton height={140} radius={radii.lg} />
          <SkeletonStats count={2} />
          <SkeletonList count={2} />
        </View>
      ) : null}

      {!loading && error && !summary ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} /> : null}

      {summary ? (
        <View style={styles.stack}>
          {refreshError ? (
            <InlineBanner tone="error" message={`Couldn’t refresh: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          <SegmentedControl
            options={[
              { value: "overview", label: "Overview" },
              { value: "transactions", label: "Activity" },
              { value: "payouts", label: "Payouts" },
              { value: "invoices", label: "Invoices", count: paymentDue || undefined },
            ]}
            value={segment}
            onChange={setSegment}
          />

          {segment === "overview" ? (
            <>
              <View
                style={styles.hero}
                accessible
                accessibilityLabel={`Available to cash out now: ${availableUnavailable ? UNAVAILABLE_LABEL : formatMoneyOrZero(available, currency)}${
                  instantAvailable != null ? `. Available for instant cash out: ${formatMoneyOrZero(instantAvailable, currency)}` : ""
                }`}
                testID="money-available"
              >
                <Eyebrow style={styles.heroEyebrow}>{availableCategory?.label ?? "Available to cash out"}</Eyebrow>
                <Text style={styles.heroValue} adjustsFontSizeToFit numberOfLines={1}>
                  {availableUnavailable ? UNAVAILABLE_LABEL : formatMoneyOrZero(available, currency)}
                </Text>
                <Text style={styles.heroCaption}>Current balance</Text>
                {netCategory ? (
                  <Text style={styles.heroInstant}>
                    {netCategory.label}: {formatMoneyOrZero(netCategory.amount, currency)}
                  </Text>
                ) : null}
                {instantAvailable != null ? (
                  <Text style={styles.heroInstant}>Instant cash out available: {formatMoneyOrZero(instantAvailable, currency)}</Text>
                ) : null}
                {connect?.canCashout ? (
                  <Button
                    label="Cash out"
                    icon="arrow-down-circle-outline"
                    variant="primary"
                    disabled={!canCashOut}
                    onPress={() => router.push("/(organizer)/money/cashout" as Href)}
                    testID="money-cashout"
                  />
                ) : null}
                {connect?.canCashout && !canCashOut ? (
                  <Meta style={styles.heroNote}>{cashOutReason ?? "Nothing to cash out yet. Paid booth fees show here once they settle."}</Meta>
                ) : null}
              </View>

              {breakdown.length > 0 ? <MoneyBreakdown categories={breakdown} currency={currency} /> : null}

              <ListGroup title="Booth fees not yet paid">
                <ListRow
                  icon="document-text-outline"
                  title={formatMoneyOrZero(receivables, currency)}
                  meta={
                    paymentDue > 0
                      ? `${paymentDue} approved vendor${paymentDue === 1 ? " hasn't" : "s haven't"} paid yet. This isn't in your balance until they do.`
                      : "Every approved vendor has paid."
                  }
                  onPress={() => setSegment("invoices")}
                />
              </ListGroup>

              {connect && connectCopy ? (
                <Surface style={styles.card}>
                  <View style={styles.payoutHeader}>
                    <SectionHeader title="Payout account" icon="card-outline" />
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
                  <View style={styles.row}>
                    {connect.canOnboard ? (
                      <Button
                        label={connect.kind === "not_started" ? "Set up payouts" : "Finish payout setup"}
                        loadingLabel="Opening…"
                        icon="shield-checkmark-outline"
                        variant={connect.canCashout ? "secondary" : "primary"}
                        size={connect.canCashout ? "sm" : "md"}
                        style={styles.flex}
                        loading={onboardBusy}
                        disabled={manageBusy}
                        onPress={() => void setupPayouts()}
                      />
                    ) : null}
                    {connect.kind === "active" ? (
                      <Button
                        label="Manage payout account"
                        loadingLabel="Opening…"
                        variant="secondary"
                        size="sm"
                        style={styles.flex}
                        loading={manageBusy}
                        disabled={onboardBusy}
                        onPress={() => void managePayouts()}
                      />
                    ) : null}
                  </View>
                </Surface>
              ) : null}

              {summary.wordpressFinancialUrl ? (
                <ListGroup>
                  <ListRow
                    icon="bar-chart-outline"
                    title="Reports and exports"
                    meta="Tax documents and detailed reports open on LNKup web"
                    onPress={() => void openExternalUrl(summary.wordpressFinancialUrl as string)}
                  />
                </ListGroup>
              ) : null}
            </>
          ) : null}

          {segment === "transactions" ? (
            activity.length === 0 ? (
              <EmptyPanel icon="receipt-outline" title="No activity yet" message="Collected booth payments, refunds and payouts appear here." />
            ) : (
              <ListGroup>
                {activity.map((item) => (
                  <ListRow
                    key={item.id}
                    title={item.label}
                    meta={[item.eventName, activityStatusLabel(item.status), formatShortDate(item.createdAt)].filter(Boolean).join(" · ")}
                    rightSlot={<Text style={styles.amount}>{formatMoney(item.amount, item.currency ?? currency)}</Text>}
                    onPress={item.eventId ? () => router.push(`/(organizer)/money/event?eventId=${item.eventId}` as Href) : undefined}
                  />
                ))}
              </ListGroup>
            )
          ) : null}

          {segment === "payouts" ? (
            payoutsError && payouts == null ? (
              <InlineBanner tone="error" message={`Couldn’t load cash outs: ${payoutsError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
            ) : (payouts ?? []).length === 0 ? (
              <EmptyPanel icon="time-outline" title="No cash outs yet" message="Cash outs you request show here with their status and reference." />
            ) : (
              <View style={styles.list}>
                {(payouts ?? []).map((payout) => (
                  <PayoutRow key={payout.id} payout={payout} onPress={() => router.push(`/(organizer)/money/payout?id=${payout.id}` as Href)} />
                ))}
              </View>
            )
          ) : null}

          {segment === "invoices" ? (
            events === null ? (
              <SkeletonList count={3} />
            ) : eventsWithMoney.length === 0 ? (
              <EmptyPanel icon="document-text-outline" title="No booth fees yet" message="Invoices appear when you approve vendors for a paid booth." />
            ) : (
              <ListGroup title="By event · before LNKup fees">
                {eventsWithMoney.map((event) => (
                  <ListRow
                    key={event.id}
                    title={event.title}
                    meta={[
                      formatShortDate(event.date),
                      `${event.money?.paidBookings ?? 0} paid`,
                      (event.money?.outstanding ?? 0) > 0 ? `${formatMoney(event.money?.outstanding, currency)} unpaid` : null,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                    rightSlot={<Text style={styles.amount}>{formatMoneyOrZero(event.money?.collectedGross, currency)}</Text>}
                    onPress={() => router.push(`/(organizer)/money/event?eventId=${event.id}` as Href)}
                  />
                ))}
              </ListGroup>
            )
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

function MoneyBreakdown({ categories, currency }: { categories: MoneyCategory[]; currency: string }) {
  return (
    <ListGroup title="Balances · current">
      {categories.map((category) => (
        <ListRow
          key={category.key}
          title={category.label}
          meta={category.hint}
          rightSlot={<Text style={styles.amount}>{category.unavailable ? UNAVAILABLE_LABEL : formatMoneyOrZero(category.amount, currency)}</Text>}
        />
      ))}
    </ListGroup>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
  list: { gap: spacing.sm },
  flex: { flex: 1 },
  row: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  hero: {
    backgroundColor: colors.lime,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.sm,
  },
  heroEyebrow: { color: colors.gray900 },
  heroValue: { fontFamily: fonts.display, fontSize: typeScale.hero, lineHeight: 44, color: colors.black },
  heroInstant: { fontFamily: fonts.bodySemi, fontSize: typeScale.footnote, color: colors.gray900 },
  heroNote: { color: colors.gray900 },
  heroCaption: { fontFamily: fonts.body, fontSize: typeScale.caption, color: colors.gray900 },
  card: { gap: spacing.sm },
  payoutHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.sm },
  requirements: { gap: spacing.xs },
  amount: { fontSize: typeScale.body, fontFamily: fonts.bodyBold, color: colors.black },
})
