import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { eventMetaLine } from "@/components/organizer/event-row"
import { KpiGrid } from "@/components/organizer/kpi-grid"
import { ProgressBar } from "@/components/organizer/progress-bar"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOperationsSummary, type OperationsSummary, type OpsMode, type OpsTask } from "@/lib/api/organizer-mobile"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, fonts, spacing, typeScale, type StatusTone } from "@/lib/theme"

const MODE_COPY: Record<OpsMode, { title: string; body: string }> = {
  pre_event: { title: "Getting ready", body: "Finish what vendors need before event day." },
  event_day: { title: "Event day", body: "Check vendors in and keep the day running." },
  post_event: { title: "After the event", body: "Wrap up attendance and follow up with vendors." },
}

const TIER_TONE: Record<string, StatusTone> = { ready: "paid", on_track: "approved", at_risk: "due" }

function taskHref(task: OpsTask, eventId: string): Href | null {
  switch (task.key) {
    case "booths":
      return `/(organizer)/events/booths?eventId=${eventId}` as Href
    case "packet":
      return `/(organizer)/packet?eventId=${eventId}` as Href
    case "loadin":
    case "parking":
      return `/(organizer)/events/booths?eventId=${eventId}&tab=logistics` as Href
    case "payments":
      return `/(organizer)/money/event?eventId=${eventId}` as Href
    case "documents":
      return `/(organizer)/vendors?tab=requests&event=${eventId}&filter=awaiting_payment` as Href
    default:
      return null
  }
}

function pairText(pair: [number, number]): string {
  return `${pair[0]} of ${pair[1]}`
}

/** One event's operations: lifecycle mode, readiness, tasks with owners, logistics and team. */
export default function OrganizerOperationsScreen() {
  const { eventId } = useLocalSearchParams<{ eventId: string }>()
  const id = String(eventId ?? "")
  const { auth } = useAuth()
  const [ops, setOps] = useState<OperationsSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(
    async (force = false) => {
      if (!auth || !id) return
      if (force) setRefreshing(true)
      setError(null)
      try {
        setOps(await fetchOperationsSummary(id, auth, force))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, id],
  )

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  const mode = ops ? MODE_COPY[ops.mode] : null
  const openTasks = ops?.tasks.filter((t) => !t.done) ?? []
  const doneTasks = ops?.tasks.filter((t) => t.done) ?? []

  return (
    <Screen
      title="Operations"
      hideTitle
      refreshing={refreshing}
      onRefresh={() => void load(true)}
      header={<BackLink label="Event" fallbackHref={`/(organizer)/events/${id}` as Href} />}
    >
      {loading && !ops ? <LoadingPanel label="Loading operations…" variant="skeleton" count={3} /> : null}
      {!loading && error && !ops ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load(true)} /> : null}
      {!loading && !error && !ops ? (
        <EmptyPanel icon="construct-outline" title="Operations aren't available" message="You may not have access to this event." actionLabel="Back to Events" onAction={() => router.replace("/(organizer)/events")} />
      ) : null}

      {ops && mode ? (
        <View style={styles.page} testID="operations-workspace">
          <View style={styles.header}>
            <Text style={styles.title} accessibilityRole="header" numberOfLines={2}>
              {ops.event.title}
            </Text>
            <Meta>{eventMetaLine(ops.event)}</Meta>
            <View style={styles.chips}>
              <StatusBadge label={mode.title} tone={ops.mode === "event_day" ? "due" : "neutral"} />
              {ops.myRole && ops.myRole !== "owner" ? <StatusBadge label={`You: ${ops.staff.find((s) => s.role === ops.myRole)?.roleLabel ?? ops.myRole}`} tone="approved" /> : null}
            </View>
          </View>

          {ops.mode === "event_day" && ops.can.check_in ? (
            <InlineBanner
              tone="attention"
              title="Check-in is open"
              message={`${ops.checkin.checkedIn} of ${ops.checkin.expected} eligible vendors checked in.`}
              actionLabel="Open check-in"
              onAction={() => router.push(`/(organizer)/events/checkin?eventId=${id}` as Href)}
            />
          ) : null}

          <KpiGrid
            items={[
              {
                key: "arrivals",
                label: ops.mode === "pre_event" ? "Expected" : "Checked in",
                value: ops.mode === "pre_event" ? ops.checkin.expected : `${ops.checkin.checkedIn}/${ops.checkin.expected}`,
                scope: "eligible vendors",
                icon: "people-outline",
                onPress: ops.can.check_in ? () => router.push(`/(organizer)/events/checkin?eventId=${id}` as Href) : undefined,
              },
              {
                key: "blocked",
                label: "Blocked",
                value: ops.checkin.blocked.paymentPending + ops.checkin.blocked.documents,
                scope: "unpaid or documents",
                icon: "alert-circle-outline",
                tone: ops.checkin.blocked.paymentPending + ops.checkin.blocked.documents > 0 ? "urgent" : "default",
              },
              {
                key: "booths",
                label: "Booths placed",
                value: `${ops.booths.assigned}/${ops.booths.confirmed}`,
                scope: "confirmed vendors",
                icon: "grid-outline",
                onPress: () => router.push(`/(organizer)/events/booths?eventId=${id}` as Href),
              },
              {
                key: "ready",
                label: "Vendor readiness",
                value: `${ops.readiness.percent}%`,
                scope: `${ops.readiness.readyCount} of ${ops.readiness.vendorCount} ready`,
                icon: "checkmark-done-outline",
              },
            ]}
          />

          <Surface style={styles.card}>
            <SectionHeader title={mode.title} subtitle={mode.body} icon="flag-outline" />
            {ops.lifecycle.stageLabel ? <Meta>Stage: {ops.lifecycle.stageLabel}</Meta> : null}
            <ProgressBar percent={ops.lifecycle.progress} label={`Event lifecycle ${ops.lifecycle.progress}%`} />
          </Surface>

          {ops.tasks.length > 0 ? (
            <ListGroup title={openTasks.length ? `To do · ${openTasks.length}` : "All set"}>
              {[...openTasks, ...doneTasks].map((task) => {
                const href = task.canAct ? taskHref(task, id) : null
                return (
                  <ListRow
                    key={task.key}
                    icon={task.done ? "checkmark-circle" : "ellipse-outline"}
                    iconTone={task.done ? "accent" : "default"}
                    title={task.label}
                    meta={`${task.detail} · ${task.owner === "vendor" ? "Vendor's step" : "Your step"}`}
                    onPress={href ? () => router.push(href) : undefined}
                  />
                )
              })}
            </ListGroup>
          ) : null}

          <ListGroup title="Vendor packet">
            <ListRow icon="document-text-outline" title="Prepared" meta={`${pairText(ops.packet.prepared)} sections filled in`} />
            <ListRow icon="eye-outline" title="Status" meta={ops.packet.published || (ops.packet.stage === "draft" ? "Draft" : "Ready")} />
            <ListRow icon="lock-open-outline" title="Can open it" meta={`${pairText(ops.packet.accessible)} paid vendors`} />
            <ListRow icon="checkmark-done-outline" title="Viewed · accepted rules" meta={`${pairText(ops.packet.viewed)} · ${pairText(ops.packet.acknowledged)} of vendors with access`} />
            {ops.can.edit_packets ? <ListRow icon="create-outline" title="Edit packet" onPress={() => router.push(`/(organizer)/packet?eventId=${id}` as Href)} /> : null}
          </ListGroup>

          {ops.readiness.vendors.length > 0 ? (
            <ListGroup title="Vendor readiness">
              {ops.readiness.vendors.slice(0, 12).map((vendor) => (
                <ListRow
                  key={vendor.applicationId}
                  icon="storefront-outline"
                  title={vendor.businessName}
                  meta={[`${vendor.percent}% ready`, vendor.blocker || vendor.statusLabel].filter(Boolean).join(" · ")}
                  rightSlot={<StatusBadge label={vendor.tierLabel} tone={TIER_TONE[vendor.tier] ?? "neutral"} />}
                  onPress={ops.can.owner ? () => router.push(`/(organizer)/vendors/review/${vendor.applicationId}?filter=all` as Href) : undefined}
                />
              ))}
            </ListGroup>
          ) : null}

          <ListGroup title="Logistics">
            <ListRow icon="time-outline" title="Load-in windows" meta={ops.logistics.loadinWindows ? `${ops.logistics.loadinWindows} scheduled` : "None yet"} onPress={() => router.push(`/(organizer)/events/booths?eventId=${id}&tab=logistics` as Href)} />
            <ListRow icon="car-outline" title="Parking" meta={ops.logistics.parkingAreas ? `${ops.logistics.parkingAreas} area${ops.logistics.parkingAreas === 1 ? "" : "s"}` : "None yet"} onPress={() => router.push(`/(organizer)/events/booths?eventId=${id}&tab=logistics` as Href)} />
            <ListRow icon="warning-outline" title="Incidents" meta={ops.incidents.open ? `${ops.incidents.open} open` : "None open"} />
          </ListGroup>

          {ops.staff.length > 0 ? (
            <ListGroup title="Event team">
              {ops.staff.map((member) => (
                <ListRow key={member.userId} icon="person-outline" title={member.name} meta={member.roleLabel} />
              ))}
            </ListGroup>
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  page: { gap: spacing.lg },
  header: { gap: spacing.xs },
  title: { fontFamily: fonts.display, fontSize: typeScale.title, color: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs },
  card: { gap: spacing.sm },
})
