import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { NetworkBanner } from "@/components/organizer/network-banner"
import { SearchField } from "@/components/organizer/search-field"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChipRow } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  assignBooth,
  checkInApplication,
  checkInByCode,
  eventDayEntries,
  fetchEventBooths,
  overrideCheckIn,
  type CheckInOutcome,
  type EventBooth,
} from "@/lib/api/organizer-checkin"
import {
  fetchCheckinSummary,
  invalidateOrganizerMobile,
  type CheckinPopulations,
  type CheckinRosterRow,
} from "@/lib/api/organizer-mobile"
import { fetchEventRoster } from "@/lib/api/organizer-roster"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText } from "@/lib/feedback/feedback"
import { formatShortDate, formatTime } from "@/lib/format/date"
import { useKeyedSingleFlight, useSingleFlight } from "@/lib/hooks/use-single-flight"
import { useNetworkStatus } from "@/lib/network/use-network-status"
import {
  CHECKIN_FILTERS,
  canCheckInRow,
  canOverrideRow,
  isBlocked,
  matchesCheckinFilter,
  matchesCheckinSearch,
  populationLine,
  populationsFromRows,
  reasonCopy,
  rowFromRoster,
  type CheckinFilter,
} from "@/lib/organizer/checkin-model"
import { colors, fonts, layout, radii, spacing, typeScale, typography } from "@/lib/theme"

const OVERRIDE_MIN_REASON = 5

type Result = { outcome: CheckInOutcome; name: string; row?: CheckinRosterRow }

/** Event-day check-in: find a vendor first, pass code second. Online only — nothing is queued. */
export default function OrganizerCheckInScreen() {
  const { auth } = useAuth()
  const { eventId: eventParam } = useLocalSearchParams<{ eventId?: string }>()
  const eventId = String(eventParam ?? "")
  const network = useNetworkStatus()
  const online = network === "online"
  const [rows, setRows] = useState<CheckinRosterRow[] | null>(null)
  const [populations, setPopulations] = useState<CheckinPopulations | null>(null)
  const [meta, setMeta] = useState<{ title: string; date: string } | null>(null)
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [filter, setFilter] = useState<CheckinFilter>("expected")
  const [query, setQuery] = useState("")
  const [codeOpen, setCodeOpen] = useState(false)
  const [code, setCode] = useState("")
  const [selected, setSelected] = useState<CheckinRosterRow | null>(null)
  const [result, setResult] = useState<Result | null>(null)
  const [boothFor, setBoothFor] = useState<CheckinRosterRow | null>(null)
  const [booths, setBooths] = useState<EventBooth[] | null>(null)
  const [boothsError, setBoothsError] = useState<string | null>(null)
  const rowsRef = useRef<CheckinRosterRow[] | null>(null)
  rowsRef.current = rows

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!eventId || !auth) return
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      setRefreshError(null)
      try {
        const summary = await fetchCheckinSummary(eventId, auth, mode === "refresh")
        if (summary) {
          setRows(summary.rows)
          setPopulations(summary)
          setMeta({ title: summary.title, date: summary.date })
        } else {
          const roster = await fetchEventRoster(eventId, auth, { force: mode === "refresh" })
          const next = eventDayEntries(roster).map(rowFromRoster)
          setRows(next)
          setPopulations(populationsFromRows(next))
        }
        setUpdatedAt(new Date())
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (rowsRef.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, eventId],
  )

  useFocusEffect(
    useCallback(() => {
      void load(rowsRef.current ? "refresh" : "initial")
    }, [load]),
  )

  // Coming back online: refresh before anyone acts on a stale roster.
  useEffect(() => {
    if (network === "reconnecting") void load("refresh")
  }, [network, load])

  const finish = useCallback(
    (outcome: CheckInOutcome, name: string, row?: CheckinRosterRow) => {
      setSelected(null)
      setResult({ outcome, name, row })
      invalidateOrganizerMobile(eventId)
      if (outcome.kind !== "offline") void load("refresh")
    },
    [eventId, load],
  )

  const [checkIn, isCheckingIn] = useKeyedSingleFlight(async (applicationId: string) => {
    const row = rows?.find((item) => item.applicationId === applicationId)
    if (!row || !online) return
    finish(await checkInApplication(auth, eventId, applicationId), row.vendorName, row)
  })

  const [override, isOverriding] = useKeyedSingleFlight(async (applicationId: string) => {
    const row = rows?.find((item) => item.applicationId === applicationId)
    if (!row || !online) return
    const reason = await promptText({
      title: `Check in ${row.vendorName} anyway?`,
      message: `${reasonCopy(row.reason)} Your reason is saved to the event log.`,
      placeholder: "Reason (for example: paid cash at the gate)",
      confirmLabel: "Check in",
      minLength: OVERRIDE_MIN_REASON,
      multiline: true,
    })
    if (!reason) return
    finish(await overrideCheckIn(auth, eventId, applicationId, reason.trim()), row.vendorName, row)
  })

  const [submitCode, codeBusy] = useSingleFlight(async () => {
    const trimmed = code.trim()
    if (!trimmed || !online) return
    const outcome = await checkInByCode(auth, eventId, trimmed)
    if (outcome.kind === "checked_in" || outcome.kind === "already") setCode("")
    setCodeOpen(false)
    finish(outcome, outcome.businessName ?? "Vendor")
  })

  const [openBooths, boothsLoading] = useSingleFlight(async (row: CheckinRosterRow) => {
    setSelected(null)
    setBoothFor(row)
    setBoothsError(null)
    try {
      setBooths(await fetchEventBooths(auth, eventId))
    } catch (err) {
      setBoothsError(appErrorFromWp(err).message)
    }
  })

  const [chooseBooth, isAssigning] = useKeyedSingleFlight(async (boothId: string) => {
    const row = boothFor
    const booth = booths?.find((item) => item.id === boothId)
    if (!row || !booth || !online) return
    try {
      await assignBooth(auth, eventId, boothId, row.applicationId)
      setBoothFor(null)
      setResult({ outcome: { kind: "checked_in", message: `${row.vendorName} is now at booth ${booth.number}.`, override: false }, name: row.vendorName })
      void load("refresh")
    } catch (err) {
      setBoothsError(appErrorFromWp(err).message)
    }
  })

  const visible = useMemo(
    () => (rows ?? []).filter((row) => matchesCheckinFilter(row, query ? "all" : filter) && matchesCheckinSearch(row, query)),
    [rows, filter, query],
  )
  const filterOptions = useMemo(
    () => CHECKIN_FILTERS.map((item) => ({ value: item.key, label: item.label, count: (rows ?? []).filter((row) => matchesCheckinFilter(row, item.key)).length })),
    [rows],
  )

  const back = <BackLink label="Event" fallbackHref={(eventId ? `/(organizer)/events/${eventId}?tab=operations` : "/(organizer)/events") as Href} />

  if (!eventId) {
    return (
      <ScreenList title="Check-in" header={back} data={[]} keyExtractor={() => ""} renderItem={() => null} listHeader={<EmptyPanel icon="calendar-outline" title="Pick an event" message="Open check-in from an event's Operations tab." />} />
    )
  }

  return (
    <ScreenList
      title="Check-in"
      subtitle={meta ? [meta.title, formatShortDate(meta.date)].filter(Boolean).join(" · ") : undefined}
      header={back}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      data={rows ? visible : []}
      keyExtractor={(row) => row.applicationId}
      renderItem={(row) => (
        <CheckinRow
          row={row}
          disabled={!online}
          busy={isCheckingIn(row.applicationId) || isOverriding(row.applicationId)}
          onCheckIn={() => void checkIn(row.applicationId)}
          onOpen={() => setSelected(row)}
        />
      )}
      listHeader={
        <View style={styles.stack}>
          <NetworkBanner status={network} lastUpdated={updatedAt ? updatedAt.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" }) : undefined} />

          {loading && !rows ? (
            <View style={styles.stack} accessibilityLabel="Loading check-in">
              <SkeletonStats count={2} />
              <SkeletonList count={4} />
            </View>
          ) : null}

          {!loading && error && !rows ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} /> : null}

          {rows && populations ? (
            <>
              {refreshError ? <InlineBanner tone="error" message={`Couldn’t refresh the list: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} /> : null}

              <StatGrid>
                <StatTile icon="checkmark-circle-outline" label="Checked in" hint="of eligible vendors" value={`${populations.checkedIn} of ${populations.expected}`} tone={populations.checkedIn > 0 ? "accent" : "default"} />
                <StatTile
                  icon="alert-circle-outline"
                  label="Blocked"
                  hint="unpaid or documents"
                  value={String(populations.blocked.paymentPending + populations.blocked.documents)}
                  tone={populations.blocked.paymentPending + populations.blocked.documents > 0 ? "urgent" : "default"}
                  onPress={() => setFilter("attention")}
                />
              </StatGrid>
              <Meta accessibilityLiveRegion="polite" testID="checkin-populations">
                {populationLine(populations)}
              </Meta>

              <SearchField value={query} onChangeText={setQuery} placeholder="Find a vendor by name, category or booth" testID="checkin-search" />
              <Button
                label="Enter a pass code"
                icon="keypad-outline"
                variant="secondary"
                disabled={!online}
                onPress={() => setCodeOpen(true)}
                testID="checkin-code-open"
              />
              {!query ? <FilterChipRow options={filterOptions} value={filter} onChange={setFilter} /> : null}

              {rows.length === 0 ? (
                <EmptyPanel icon="people-outline" title="No booked vendors yet" message="Approved and paid vendors appear here on event day." />
              ) : visible.length === 0 ? (
                <EmptyPanel icon="search-outline" title="No vendors match" message={query ? "Check the spelling or try a booth number." : "Nobody in this view right now."} />
              ) : null}
            </>
          ) : null}

          <BottomSheet
            visible={codeOpen}
            onClose={() => setCodeOpen(false)}
            title="Pass code"
            footer={<Button label="Check in with code" loadingLabel="Checking…" fullWidth loading={codeBusy} disabled={!code.trim() || !online} onPress={() => void submitCode()} />}
          >
            <Meta>Type the code printed under the vendor’s pass.</Meta>
            <FormField
              label="Pass code"
              value={code}
              onChangeText={setCode}
              autoCapitalize="none"
              autoCorrect={false}
              autoFocus
              returnKeyType="go"
              onSubmitEditing={() => void submitCode()}
              testID="checkin-code-input"
            />
          </BottomSheet>

          <BottomSheet visible={selected != null} onClose={() => setSelected(null)} title={selected?.vendorName ?? "Vendor"}>
            {selected ? (
              <View style={styles.sheet} testID="checkin-vendor-sheet">
                <ListGroup>
                  <ListRow icon="grid-outline" title={selected.booth ? `Booth ${selected.booth}` : "No booth yet"} meta={selected.category || undefined} />
                  <ListRow
                    icon={selected.checkedIn ? "checkmark-circle" : isBlocked(selected) ? "alert-circle-outline" : "time-outline"}
                    iconTone={selected.checkedIn ? "accent" : isBlocked(selected) ? "danger" : "default"}
                    title={selected.checkedIn ? `Checked in${selected.checkedInAt ? ` at ${formatTime(selected.checkedInAt)}` : ""}` : reasonCopy(selected.reason)}
                  />
                </ListGroup>
                {canCheckInRow(selected) ? (
                  <Button
                    label="Check in"
                    loadingLabel="Saving…"
                    disabled={!online}
                    loading={isCheckingIn(selected.applicationId)}
                    onPress={() => void checkIn(selected.applicationId)}
                    accessibilityLabel={`Check in ${selected.vendorName}`}
                  />
                ) : null}
                {canOverrideRow(selected) ? (
                  <Button
                    label="Check in with a reason"
                    loadingLabel="Saving…"
                    variant="secondary"
                    disabled={!online}
                    loading={isOverriding(selected.applicationId)}
                    onPress={() => void override(selected.applicationId)}
                    accessibilityLabel={`Check in ${selected.vendorName} with an override`}
                  />
                ) : null}
                <Button
                  label={selected.booth ? "Move booth" : "Assign booth"}
                  variant="ghost"
                  disabled={!online}
                  onPress={() => void openBooths(selected)}
                  accessibilityLabel={`${selected.booth ? "Move" : "Assign"} booth for ${selected.vendorName}`}
                />
              </View>
            ) : null}
          </BottomSheet>

          <BottomSheet visible={result != null} onClose={() => setResult(null)} title={result ? resultTitle(result) : ""}>
            {result ? <ResultBody result={result} onOverride={result.row && canOverrideRow(result.row) ? () => { const id = result.row!.applicationId; setResult(null); void override(id) } : undefined} /> : null}
          </BottomSheet>

          <BottomSheet visible={boothFor != null} title={boothFor ? `Booth for ${boothFor.vendorName}` : "Booth"} onClose={() => setBoothFor(null)}>
            {boothsError ? <InlineBanner tone="error" message={boothsError} /> : null}
            {boothsLoading && !booths ? <SkeletonList count={3} /> : null}
            {booths && booths.length === 0 ? <EmptyPanel icon="grid-outline" title="No booths set up" message="Add booths from the event's Booths screen." /> : null}
            {booths && booths.length > 0 && boothFor ? (
              <ListGroup>
                {booths.map((booth) => {
                  const mine = booth.applicationId === boothFor.applicationId
                  const taken = Boolean(booth.applicationId) && !mine
                  return (
                    <ListRow
                      key={booth.id}
                      icon="grid-outline"
                      title={`Booth ${booth.number}`}
                      meta={taken ? `Taken by ${booth.vendorName || "another vendor"}` : booth.typeLabel || undefined}
                      rightSlot={<StatusBadge label={mine ? "Current" : taken ? "Taken" : isAssigning(booth.id) ? "Assigning…" : "Open"} tone={mine ? "approved" : taken ? "neutral" : "paid"} />}
                      onPress={taken || mine || isAssigning(booth.id) || !online ? undefined : () => void chooseBooth(booth.id)}
                    />
                  )
                })}
              </ListGroup>
            ) : null}
          </BottomSheet>
        </View>
      }
    />
  )
}

function resultTitle(result: Result): string {
  switch (result.outcome.kind) {
    case "checked_in":
      return "Done"
    case "already":
      return "Already checked in"
    case "blocked":
      return "Can't check in yet"
    case "offline":
      return "Not saved"
    case "unconfirmed":
      return "Checking…"
    case "denied":
      return "Not allowed"
    case "error":
      return "Not saved"
    default: {
      const exhaustive: never = result.outcome
      return exhaustive
    }
  }
}

function ResultBody({ result, onOverride }: { result: Result; onOverride?: () => void }) {
  const { outcome, name } = result
  switch (outcome.kind) {
    case "checked_in":
      return <InlineBanner tone="success" title={name} message={outcome.override ? `${outcome.message} The reason was saved to the event log.` : outcome.message} />
    case "already":
      return <InlineBanner tone="info" title={name} message={result.row?.checkedInAt ? `Checked in at ${formatTime(result.row.checkedInAt)}.` : outcome.message} />
    case "blocked":
      return (
        <View style={styles.sheet}>
          <InlineBanner tone="attention" title={name} message={outcome.message} />
          {onOverride && outcome.canOverride ? <Button label="Check in with a reason" variant="secondary" onPress={onOverride} accessibilityLabel={`Check in ${name} with an override`} /> : null}
        </View>
      )
    case "unconfirmed":
      return <InlineBanner tone="info" message={outcome.message} />
    case "offline":
    case "denied":
    case "error":
      return <InlineBanner tone="error" title={name} message={outcome.message} />
    default: {
      const exhaustive: never = outcome
      return exhaustive
    }
  }
}

function CheckinRow({
  row,
  disabled,
  busy,
  onCheckIn,
  onOpen,
}: {
  row: CheckinRosterRow
  disabled: boolean
  busy: boolean
  onCheckIn: () => void
  onOpen: () => void
}) {
  const blocked = isBlocked(row)
  const status = row.checkedIn ? "Checked in" : blocked ? (row.reason === "payment_pending" ? "Payment pending" : row.reason === "documents" ? "Documents" : "Not eligible") : "Expected"
  return (
    <View style={styles.row} testID={`checkin-row-${row.applicationId}`}>
      <Pressable
        onPress={onOpen}
        style={({ pressed }) => [styles.rowMain, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={`${row.vendorName}. ${row.booth ? `Booth ${row.booth}` : "No booth yet"}. ${status}`}
      >
        <View style={styles.rowText}>
          <Text style={styles.rowTitle} numberOfLines={1}>
            {row.vendorName}
          </Text>
          <Text style={styles.rowMeta} numberOfLines={1}>
            {[row.booth ? `Booth ${row.booth}` : "No booth yet", row.category].filter(Boolean).join(" · ")}
          </Text>
        </View>
        <StatusBadge label={status} tone={row.checkedIn ? "paid" : blocked ? "due" : "neutral"} />
      </Pressable>
      {canCheckInRow(row) ? (
        <Button label="Check in" size="sm" loadingLabel="Saving…" loading={busy} disabled={disabled} onPress={onCheckIn} accessibilityLabel={`Check in ${row.vendorName}`} />
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  sheet: { gap: spacing.md },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    paddingRight: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  rowMain: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin + 12,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  pressed: { backgroundColor: colors.gray50 },
  rowText: { flex: 1, gap: 2 },
  rowTitle: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.black },
  rowMeta: { ...typography.meta },
})
