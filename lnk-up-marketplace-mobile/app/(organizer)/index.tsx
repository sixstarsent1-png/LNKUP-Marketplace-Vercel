import { useCallback, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href, useFocusEffect } from "expo-router"

import { NotificationBell } from "@/components/notification-bell"
import { EventRow } from "@/components/organizer/event-row"
import { KpiGrid, type KpiItem } from "@/components/organizer/kpi-grid"
import { OrgHeader } from "@/components/organizer/org-header"
import { QuickActionGrid, type QuickAction } from "@/components/organizer/quick-action-grid"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { PushPermissionPrompt } from "@/components/push-permission-prompt"
import { GuidedTutorialChecklist } from "@/components/guided-tutorial-checklist"
import { Screen } from "@/components/screen"
import { TutorialPopupModal } from "@/components/tutorial-popup-modal"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { Skeleton, SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { ProgressBar } from "@/components/organizer/progress-bar"
import { Surface } from "@/components/ui/surface"
import { fetchInboxCount } from "@/lib/api/comms"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { formatMoney, formatMoneyOrZero } from "@/lib/api/format-money"
import { fetchOrganizerHome } from "@/lib/api/organizer-home"
import { fetchHomeSummary, type OrgHomeSummary, type OrgQueueGroup } from "@/lib/api/organizer-mobile"
import { fetchOrganizerProfileEdit } from "@/lib/api/organizer-profile"
import { fetchOrganizerRequests, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { useAuth } from "@/lib/auth/auth-context"
import {
  checkinEvent,
  legacyHomeToOrgHome,
  queueGroupHref,
  queueRowHref,
  setupStageHref,
  tutorialStats,
} from "@/lib/organizer/home-model"
import { loadLastEvent, type LastEvent } from "@/lib/organizer/last-event"
import { syncAppBadge } from "@/lib/push/notifications"
import { buildOrganizerTutorial } from "@/lib/tutorials/build-organizer-tutorial"
import { useRoleTutorial } from "@/lib/tutorials/use-role-tutorial"
import { colors, fonts, radii, spacing, typeScale, typography } from "@/lib/theme"

type Identity = { name: string; logoUrl: string }

async function loadHome(auth: Parameters<typeof fetchHomeSummary>[0], force: boolean): Promise<OrgHomeSummary> {
  const summary = await fetchHomeSummary(auth, force)
  if (summary) return summary
  return legacyHomeToOrgHome(await fetchOrganizerHome(auth, { force }))
}

export default function OrganizerHomeScreen() {
  const { session, auth } = useAuth()
  const [home, setHome] = useState<OrgHomeSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [unreadCount, setUnreadCount] = useState(0)
  const [identity, setIdentity] = useState<Identity | null>(null)
  const [requests, setRequests] = useState<OrganizerVendorRequest[]>([])
  const [lastEvent, setLastEvent] = useState<LastEvent | null>(null)
  const didFocusLoad = useRef(false)
  const homeRef = useRef<OrgHomeSummary | null>(null)
  homeRef.current = home

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh") => {
      if (!auth) return
      if (mode === "refresh") setRefreshing(true)
      else if (mode === "initial") setLoading(true)
      setError(null)
      setRefreshError(null)
      const force = mode === "refresh"
      try {
        const [summary, inbox, profile, requestList, last] = await Promise.all([
          loadHome(auth, force),
          fetchInboxCount("organizer", auth).catch(() => null),
          fetchOrganizerProfileEdit(auth).catch(() => null),
          fetchOrganizerRequests(auth, { force }).catch(() => null),
          loadLastEvent(auth.username),
        ])
        setHome(summary)
        setLastEvent(last)
        if (inbox) {
          setUnreadCount(inbox.unread)
          void syncAppBadge(inbox.unread)
        }
        if (profile) setIdentity({ name: profile.publicName.trim(), logoUrl: profile.logoUrl })
        if (requestList) setRequests(requestList.items)
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && homeRef.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
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

  const tutorial = useMemo(
    () => buildOrganizerTutorial({ summary: home ? { stats: tutorialStats(home) } : null, requests }),
    [home, requests],
  )
  const { visible: showTutorial, showPopup, dismiss, markPopupSeen, onStepClick, onPushClick } = useRoleTutorial(
    "organizer",
    tutorial,
    session?.user.id ?? session?.username,
    auth,
  )

  const organizationName = identity?.name || session?.user.name || "Your organization"

  return (
    <Screen
      title="Home"
      hideTitle
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <View style={styles.header}>
        <OrgHeader
          organizationName={organizationName}
          logoUrl={identity?.logoUrl}
          subtitle={home ? headerSubtitle(home) : undefined}
          right={<NotificationBell unreadCount={unreadCount} href="/(organizer)/notifications" />}
        />
      </View>

      {loading && !home ? (
        <View style={styles.stack} accessibilityLabel="Loading organizer home">
          <SkeletonStats count={4} />
          <Skeleton height={88} radius={radii.lg} />
          <SkeletonList count={3} />
        </View>
      ) : null}

      {!loading && error && !home ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {home ? (
        <View style={styles.stack} testID="organizer-home">
          <TutorialPopupModal
            visible={showPopup}
            tutorial={tutorial}
            onCta={onStepClick}
            onFinish={markPopupSeen}
            onSkipAll={dismiss}
            testID="organizer-tutorial-popup"
          />

          {refreshError ? (
            <InlineBanner tone="error" message={`Couldn’t refresh: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          {home.lifecycle === "new" ? <NewOrganizerSetup home={home} /> : null}

          {home.lifecycle !== "new" ? <KpiGrid items={kpiItems(home)} /> : null}

          <QuickActionGrid actions={quickActions(home)} />

          {home.lifecycle !== "new" && !home.setup.isActivated ? <SetupProgress home={home} /> : null}

          <PushPermissionPrompt role="organizer" />

          {lastEvent && lastEvent.id !== home.events.next?.id && !home.events.today.some((e) => e.id === lastEvent.id) ? (
            <ListGroup>
              <ListRow
                icon="return-up-back-outline"
                title={lastEvent.title || "Your last event"}
                meta="Pick up where you left off"
                onPress={() => router.push(`/(organizer)/events/${lastEvent.id}` as Href)}
              />
            </ListGroup>
          ) : null}

          {home.lifecycle !== "new" ? <ActionCenter queue={home.queue} /> : null}

          {home.events.today.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader title="Today" icon="flash-outline" />
              {home.events.today.map((event) => (
                <EventRow
                  key={event.id}
                  event={event}
                  variant="card"
                  testID={`home-today-${event.id}`}
                  onPress={() => router.push(`/(organizer)/events/operations?eventId=${event.id}` as Href)}
                />
              ))}
            </View>
          ) : null}

          {home.events.next && home.events.today.length === 0 ? (
            <View style={styles.section}>
              <SectionHeader title="Next event" icon="calendar-outline" />
              <EventRow
                event={home.events.next}
                variant="card"
                testID="home-next-event"
                onPress={() => router.push(`/(organizer)/events/${home.events.next?.id}` as Href)}
              />
            </View>
          ) : null}

          {home.events.upcoming.length > 1 ? (
            <View style={styles.section}>
              <SectionHeader title="Upcoming events" icon="calendar-outline" actionLabel="See all" onAction={() => router.push("/(organizer)/events")} />
              <View style={styles.list}>
                {home.events.upcoming.slice(1, 4).map((event) => (
                  <EventRow key={event.id} event={event} onPress={() => router.push(`/(organizer)/events/${event.id}` as Href)} />
                ))}
              </View>
            </View>
          ) : null}

          {home.recruitment.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader title="Open spaces to fill" icon="storefront-outline" />
              <ListGroup>
                {home.recruitment.map((gap) => (
                  <ListRow
                    key={`${gap.eventId}-${gap.opportunityId}`}
                    icon="people-outline"
                    title={`${gap.category || gap.boothType} · ${gap.open} open`}
                    meta={[gap.eventTitle, gap.dateLabel].filter(Boolean).join(" · ")}
                    onPress={() =>
                      router.push(
                        `/(organizer)/vendors/find?eventId=${gap.eventId}${gap.category ? `&category=${encodeURIComponent(gap.category)}` : ""}` as Href,
                      )
                    }
                  />
                ))}
              </ListGroup>
            </View>
          ) : null}

          {home.lifecycle !== "new" ? <MoneySnapshot home={home} /> : null}

          {showTutorial ? (
            <GuidedTutorialChecklist
              tutorial={tutorial}
              onStepClick={onStepClick}
              onDismiss={dismiss}
              onPushClick={onPushClick}
              testID="organizer-home-tutorial"
            />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

function headerSubtitle(home: OrgHomeSummary): string {
  if (home.events.today.length > 0) return home.events.today.length === 1 ? "Event day today" : `${home.events.today.length} events today`
  const upcoming = home.events.counts.upcoming
  if (upcoming > 0) return `${upcoming} upcoming event${upcoming === 1 ? "" : "s"}`
  return home.events.counts.total > 0 ? "No upcoming events" : "Welcome to LNKup"
}

function kpiItems(home: OrgHomeSummary): KpiItem[] {
  const { kpis } = home
  return [
    {
      key: "review",
      label: "To review",
      value: kpis.awaitingReview.value,
      scope: "applications",
      icon: "hourglass-outline",
      tone: kpis.awaitingReview.value > 0 ? "accent" : "default",
      onPress: () => router.push("/(organizer)/vendors?tab=requests&filter=review" as Href),
    },
    {
      key: "unpaid",
      label: "Approved, unpaid",
      value: kpis.approvedUnpaid.value,
      scope: "awaiting payment",
      icon: "card-outline",
      tone: kpis.approvedUnpaid.value > 0 ? "urgent" : "default",
      onPress: () => router.push("/(organizer)/vendors?tab=requests&filter=awaiting_payment" as Href),
    },
    {
      key: "confirmed",
      label: "Confirmed vendors",
      value: kpis.confirmed.value,
      scope: "upcoming events",
      icon: "checkmark-circle-outline",
      onPress: () => router.push("/(organizer)/events"),
    },
    {
      key: "collected",
      label: "Collected",
      value: formatMoneyOrZero(kpis.collected.value),
      scope: "after LNKup fees · all time",
      icon: "cash-outline",
      onPress: () => router.push("/(organizer)/money"),
    },
  ]
}

function quickActions(home: OrgHomeSummary): QuickAction[] {
  const actions: QuickAction[] = []
  const today = checkinEvent(home)
  if (today) {
    actions.push({
      key: "checkin",
      label: "Check in",
      icon: "checkmark-done-outline",
      onPress: () => router.push(`/(organizer)/events/checkin?eventId=${today.id}` as Href),
    })
  }
  if (home.kpis.awaitingReview.value > 0) {
    actions.push({
      key: "review",
      label: "Review",
      icon: "document-text-outline",
      badge: home.kpis.awaitingReview.value,
      onPress: () => router.push("/(organizer)/vendors?tab=requests&filter=review" as Href),
    })
  }
  actions.push({ key: "create", label: "Create event", icon: "add-circle-outline", onPress: () => router.push("/(organizer)/launch") })
  if (home.events.counts.live > 0) {
    actions.push({
      key: "find",
      label: "Find vendors",
      icon: "search-outline",
      onPress: () => router.push("/(organizer)/vendors?segment=discover" as Href),
    })
  }
  return actions
}

function NewOrganizerSetup({ home }: { home: OrgHomeSummary }) {
  return (
    <Surface elevated style={styles.card} testID="organizer-launch-progress">
      <SectionHeader title="Launch progress" subtitle={`${home.setup.completed} of ${home.setup.total} steps · ${home.setup.percent}%`} icon="rocket-outline" />
      <ProgressBar percent={home.setup.percent} label={`Launch progress ${home.setup.percent}%`} />
      <ListGroup>
        {home.setup.stages.map((stage) => (
          <ListRow
            key={stage.key}
            icon={stage.status === "complete" ? "checkmark-circle" : "ellipse-outline"}
            iconTone={stage.status === "complete" ? "accent" : "default"}
            title={stage.title}
            meta={stage.status === "complete" ? "Done" : stage.missing.length ? `Still needed: ${stage.missing.join(", ")}` : undefined}
            onPress={stage.status === "complete" ? undefined : () => router.push(setupStageHref(stage.key, home))}
          />
        ))}
      </ListGroup>
    </Surface>
  )
}

function SetupProgress({ home }: { home: OrgHomeSummary }) {
  const next = home.setup.next
  if (!next) return null
  return (
    <Pressable
      onPress={() => router.push(setupStageHref(next.stage, home))}
      accessibilityRole="button"
      accessibilityLabel={`Launch progress ${home.setup.percent}%. Next: ${next.title}`}
      testID="organizer-launch-progress"
    >
      <Surface style={styles.card}>
        <View style={styles.setupRow}>
          <View style={styles.setupText}>
            <Text style={styles.eyebrow}>Launch progress · {home.setup.percent}%</Text>
            <Text style={styles.cardTitle}>{next.title}</Text>
            {next.missing.length ? <Text style={styles.meta}>Still needed: {next.missing.join(", ")}</Text> : null}
          </View>
          <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
        </View>
        <ProgressBar percent={home.setup.percent} label={`Launch progress ${home.setup.percent}%`} />
      </Surface>
    </Pressable>
  )
}

function ActionCenter({ queue }: { queue: OrgQueueGroup[] }) {
  const groups = queue.filter((group) => group.count > 0)
  return (
    <View style={styles.section} testID="organizer-action-center">
      <SectionHeader title="Action center" icon="alert-circle-outline" />
      {groups.length === 0 ? (
        <EmptyPanel icon="checkmark-done-outline" title="All caught up" message="Nothing needs you right now." />
      ) : (
        groups.map((group) => (
          <ListGroup key={group.key} title={`${group.label} · ${group.count}`}>
            {[
              ...group.rows.map((row) => (
                <ListRow
                  key={`${group.key}-${row.appId ?? row.eventId}`}
                  title={row.title}
                  meta={row.meta}
                  onPress={() => router.push(queueRowHref(group.key, row))}
                />
              )),
              ...(group.count > group.rows.length
                ? [
                    <ListRow
                      key={`${group.key}-all`}
                      title={`See all ${group.count}`}
                      icon="list-outline"
                      onPress={() => router.push(queueGroupHref(group.key))}
                    />,
                  ]
                : []),
            ]}
          </ListGroup>
        ))
      )}
    </View>
  )
}

function MoneySnapshot({ home }: { home: OrgHomeSummary }) {
  const { money } = home
  return (
    <Pressable
      onPress={() => router.push("/(organizer)/money")}
      accessibilityRole="button"
      accessibilityLabel="Money snapshot. Open Money"
      testID="organizer-home-money"
    >
      <Surface style={styles.card}>
        <SectionHeader title="Money" icon="cash-outline" />
        <View style={styles.moneyRow}>
          <View style={styles.moneyCell}>
            <Text style={styles.moneyValue}>{money.available == null ? "—" : formatMoney(money.available)}</Text>
            <Text style={styles.meta}>Available now</Text>
          </View>
          <View style={styles.moneyCell}>
            <Text style={styles.moneyValue}>{formatMoneyOrZero(money.collected)}</Text>
            <Text style={styles.meta}>{money.label}</Text>
          </View>
        </View>
        {money.payoutsReady === false ? <Text style={styles.warn}>Finish payout setup so booth fees reach you.</Text> : null}
      </Surface>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  header: {
    marginBottom: spacing.lg,
  },
  stack: {
    gap: spacing.lg,
  },
  section: {
    gap: spacing.md,
  },
  list: {
    gap: spacing.sm,
  },
  card: {
    gap: spacing.md,
  },
  setupRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  setupText: {
    flex: 1,
    gap: 2,
  },
  eyebrow: {
    ...typography.eyebrow,
  },
  cardTitle: {
    fontFamily: fonts.displaySemi,
    fontSize: typeScale.bodyLarge,
    color: colors.black,
  },
  meta: {
    ...typography.meta,
  },
  moneyRow: {
    flexDirection: "row",
    gap: spacing.md,
  },
  moneyCell: {
    flex: 1,
    gap: 2,
  },
  moneyValue: {
    fontFamily: fonts.display,
    fontSize: typeScale.title,
    color: colors.black,
  },
  warn: {
    ...typography.meta,
    color: colors.amber700,
  },
})
