import { useCallback, useMemo, useRef, useState } from "react"
import { Linking, StyleSheet, View } from "react-native"
import { router, type Href, useFocusEffect } from "expo-router"

import { ContinueSetupCard } from "@/components/continue-setup-card"
import { FillSlotsCard } from "@/components/fill-slots-card"
import { NextStepsCard } from "@/components/next-steps-card"
import { NotificationBell } from "@/components/notification-bell"
import { PushPermissionPrompt } from "@/components/push-permission-prompt"
import { GuidedTutorialChecklist } from "@/components/guided-tutorial-checklist"
import { TutorialPopupModal } from "@/components/tutorial-popup-modal"
import { OrganizerIntelligenceCard } from "@/components/organizer-intelligence-card"
import { OrganizerEventCard } from "@/components/organizer-event-card"
import { OrganizerHomeHero, type OrganizerHeroAction } from "@/components/organizer-home-hero"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { PressableScale } from "@/components/ui/pressable-scale"
import { SectionHeader } from "@/components/ui/section-header"
import { Skeleton, SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Body, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchInboxCount } from "@/lib/api/comms"
import { fetchNotifications } from "@/lib/api/notifications"
import type { OrganizerRecommendation } from "@/lib/api/organizer-intelligence"
import { formatMoneyOrZero } from "@/lib/api/organizer-financial"
import {
  applyLiveRequestCounts,
  fetchOrganizerHome,
  type OrganizerChecklistItem,
  type OrganizerHomeSummary,
} from "@/lib/api/organizer-home"
import { fetchOrganizerProfileEdit } from "@/lib/api/organizer-profile"
import { fetchOrganizerEvents, type OrganizerEventLite } from "@/lib/api/organizer-events"
import {
  fetchOrganizerRequests,
  organizerRequestStatusLabel,
  organizerRequestStatusTone,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import { useAuth } from "@/lib/auth/auth-context"
import { getUnreadCount } from "@/lib/notifications/read-state"
import { syncAppBadge } from "@/lib/push/notifications"
import { buildOrganizerTutorial } from "@/lib/tutorials/build-organizer-tutorial"
import { useRoleTutorial } from "@/lib/tutorials/use-role-tutorial"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type HeroAction = OrganizerHeroAction

async function openExternalUrl(url: string) {
  try {
    const can = await Linking.canOpenURL(url)
    if (!can) return
    await Linking.openURL(url)
  } catch {
    // ignore — avoid crashing on bad handoff URLs
  }
}

function checklistKey(item: OrganizerChecklistItem): string {
  return (item.key || item.id || "").toLowerCase()
}

function handleChecklistPress(item: OrganizerChecklistItem) {
  const key = checklistKey(item)

  if (key === "create_event" || key === "event") {
    router.push("/(organizer)/launch")
    return
  }
  if (key === "payout_account" || key === "payouts" || key === "collect_payment") {
    router.push("/(organizer)/money")
    return
  }
  if (key === "review_applications" || key === "approve_vendor") {
    router.push("/(organizer)/vendors?tab=requests" as Href)
    return
  }
  if (key === "invite_vendors") {
    router.push("/(organizer)/vendors?tab=discover" as Href)
    return
  }
  if (key === "invite_organizers" || key === "referrals") {
    router.push("/(organizer)/referrals" as Href)
    return
  }
  if (key === "vendor_categories" || key === "booth_fee") {
    if (item.eventId) {
      router.push(`/(organizer)/events/${item.eventId}` as Href)
    } else {
      router.push("/(organizer)/launch")
    }
    return
  }
  if (key === "public_profile") {
    router.push("/(organizer)/profile" as Href)
    return
  }
  if (key === "vendor_packet") {
    if (item.eventId) {
      router.push(`/(organizer)/packet?eventId=${encodeURIComponent(item.eventId)}` as Href)
    } else {
      router.push("/(organizer)/launch")
    }
    return
  }
  if (item.ctaUrl) {
    void openExternalUrl(item.ctaUrl)
  }
}

function resolveHero(summary: OrganizerHomeSummary): HeroAction {
  const pending = summary.attentionItems.find((item) => item.id === "pending-requests")
  if (pending) {
    return {
      eyebrow: "Needs review",
      title: pending.label,
      body: pending.description || "Keep your vendor pipeline moving.",
      cta: "Review requests",
      href: "/(organizer)/vendors?tab=requests" as Href,
      tone: "urgent",
    }
  }

  const payoutIncomplete = summary.launchChecklist.find(
    (item) => !item.complete && (checklistKey(item) === "payout_account" || checklistKey(item) === "payouts"),
  )
  if (payoutIncomplete) {
    return {
      eyebrow: "Payouts",
      title: payoutIncomplete.label || "Finish payout setup",
      body: payoutIncomplete.description || "Set up payouts so booth fees can settle to your account.",
      cta: payoutIncomplete.ctaLabel || "Open Money",
      href: "/(organizer)/money",
      tone: "default",
    }
  }

  if (summary.stats.paymentRequired > 0) {
    return {
      eyebrow: "Payments",
      title: `${summary.stats.paymentRequired} vendor${summary.stats.paymentRequired === 1 ? "" : "s"} still need to pay`,
      body: "Track outstanding booth fees in Money.",
      cta: "Open Money",
      href: "/(organizer)/money",
      tone: "default",
    }
  }

  const createIncomplete = summary.launchChecklist.find(
    (item) => !item.complete && (checklistKey(item) === "create_event" || checklistKey(item) === "event"),
  )
  if (createIncomplete || summary.stats.events === 0) {
    return {
      eyebrow: "Get started",
      title: "Create your first event",
      body: "Add the date and place, set booth prices, and publish so vendors can apply.",
      cta: "Create event",
      href: "/(organizer)/launch",
      tone: "default",
    }
  }

  return {
    eyebrow: "All caught up",
    title: "Plan your next event",
    body: "Nothing needs you right now. Create another event when you're ready.",
    cta: "Create event",
    href: "/(organizer)/launch",
    tone: "default",
  }
}

function attentionHref(id: string): Href {
  if (id === "public-profile") return "/(organizer)/profile" as Href
  if (id === "payment-required") return "/(organizer)/money"
  return "/(organizer)/vendors?tab=requests" as Href
}

function requestVendorsHref(request: OrganizerVendorRequest): Href {
  if (request.eventId) {
    return `/(organizer)/vendors?tab=requests&event=${encodeURIComponent(request.eventId)}` as Href
  }
  return "/(organizer)/vendors?tab=requests" as Href
}

function recommendationAction(recommendation: OrganizerRecommendation): {
  label: string
  href?: Href
  externalUrl?: string
} {
  const stage = recommendation.funnelStage.toLowerCase()
  if (stage.includes("application")) {
    return { label: "Review requests", href: "/(organizer)/vendors?tab=requests" as Href }
  }
  if (stage.includes("payment")) {
    return { label: "Open Money", href: "/(organizer)/money" }
  }
  if (stage.includes("apply")) {
    return { label: "Open events", href: "/(organizer)/events" }
  }
  if (recommendation.href) {
    return { label: "Open LNKup web", externalUrl: recommendation.href }
  }
  return { label: "Open More", href: "/(organizer)/more" }
}

export default function OrganizerHomeScreen() {
  const { session, auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [summary, setSummary] = useState<OrganizerHomeSummary | null>(null)
  const [unreadCount, setUnreadCount] = useState(0)
  const [upcoming, setUpcoming] = useState<OrganizerEventLite[]>([])
  const [recentRequests, setRecentRequests] = useState<OrganizerVendorRequest[]>([])
  const [allRequests, setAllRequests] = useState<OrganizerVendorRequest[]>([])
  const [secondaryNote, setSecondaryNote] = useState<string | null>(null)
  const [statsExpanded, setStatsExpanded] = useState(false)
  const [needsPublicProfile, setNeedsPublicProfile] = useState(false)
  const didFocusLoad = useRef(false)
  const summaryRef = useRef<OrganizerHomeSummary | null>(null)
  summaryRef.current = summary

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else if (mode === "initial") setLoading(true)
      setError(null)
      setRefreshError(null)
      setSecondaryNote(null)
      try {
        const [data, notifications, serverCount] = await Promise.all([
          fetchOrganizerHome(auth, { force: mode === "refresh" }),
          fetchNotifications("organizer", { auth, userId: session?.user.id }).catch(() => []),
          fetchInboxCount("organizer", auth),
        ])
        setSummary(data)
        try {
          const profile = await fetchOrganizerProfileEdit(auth)
          setNeedsPublicProfile(!profile.publicName.trim())
        } catch {
          // Unknown is not "missing": don't tell the organizer to create a profile they may have.
          setNeedsPublicProfile(false)
        }
        const activeExperiment = data.intelligence.experiments[0]
        const topRecommendation = data.intelligence.recommendations[0]
        if (activeExperiment) {
          void trackMobileEvent({
            event: "organizer_experiment_viewed",
            source: "mobile_organizer_home",
            auth,
            metadata: {
              experimentId: activeExperiment.experimentId,
              variant: activeExperiment.variant,
              segment: `${activeExperiment.segment.type}:${activeExperiment.segment.value}`,
            },
          })
        }
        if (topRecommendation) {
          void trackMobileEvent({
            event: "organizer_experiment_recommendation_opened",
            source: "mobile_organizer_home",
            auth,
            metadata: {
              recommendationId: topRecommendation.id,
              funnelStage: topRecommendation.funnelStage,
              segment: `${topRecommendation.segment.type}:${topRecommendation.segment.value}`,
            },
          })
        }
        const unread = serverCount ? serverCount.unread : getUnreadCount(notifications)
        setUnreadCount(unread)
        void syncAppBadge(unread)

        const [eventsSettled, requestsSettled] = await Promise.allSettled([
          fetchOrganizerEvents(auth, { force: mode === "refresh" }),
          fetchOrganizerRequests(auth, { force: mode === "refresh" }),
        ])

        if (eventsSettled.status === "fulfilled") {
          setUpcoming(
            eventsSettled.value.items
              .filter((event) => event.status === "upcoming" || event.status === "unknown")
              .slice(0, 3),
          )
        } else {
          setUpcoming([])
          setSecondaryNote("Some home sections couldn’t load. Pull to refresh.")
        }

        if (requestsSettled.status === "fulfilled") {
          setSummary(applyLiveRequestCounts(data, requestsSettled.value.items))
          setAllRequests(requestsSettled.value.items)
          setRecentRequests(
            requestsSettled.value.items
              .filter((item) => item.status === "submitted" || item.status === "under_review")
              .slice(0, 3),
          )
        } else {
          setAllRequests([])
          setRecentRequests([])
          setSecondaryNote("Some home sections couldn’t load. Pull to refresh.")
        }
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && summaryRef.current) {
          setRefreshError(shaped.message)
        } else {
          setError(shaped)
        }
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, session?.user.id],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  const hero = useMemo(() => (summary ? resolveHero(summary) : null), [summary])
  const isZeroState = Boolean(summary && summary.stats.events === 0)
  const checklistVisible = useMemo(() => {
    if (!summary) return []
    const incomplete = summary.launchChecklist.filter((item) => !item.complete)
    const sliced = incomplete.slice(0, 3)
    if (!needsPublicProfile || sliced.some((item) => checklistKey(item) === "public_profile")) return sliced
    const profileStep: OrganizerChecklistItem = {
      id: "public-profile",
      key: "public_profile",
      label: "Create your public profile",
      complete: false,
      ctaLabel: "Create profile",
    }
    return [profileStep, ...sliced].slice(0, 4)
  }, [needsPublicProfile, summary])

  const attentionItems = useMemo(() => {
    if (!summary) return []
    // The hero already leads with pending requests; listing them again repeats the same card.
    const rest = summary.attentionItems.filter((item) => item.id !== "pending-requests")
    if (!needsPublicProfile) return rest
    return [
      {
        id: "public-profile",
        label: "Create your public profile",
        description: "Add your organization name so vendors can find you.",
        severity: "info" as const,
      },
      ...rest,
    ]
  }, [needsPublicProfile, summary])

  const organizerTutorial = useMemo(
    () =>
      buildOrganizerTutorial({
        summary,
        requests: allRequests,
      }),
    [summary, allRequests],
  )
  const {
    visible: showOrganizerTutorial,
    showPopup: showOrganizerTutorialPopup,
    dismiss,
    markPopupSeen,
    onStepClick,
    onPushClick,
  } = useRoleTutorial("organizer", organizerTutorial, session?.user.id ?? session?.username, auth)

  return (
    <Screen
      title="Home"
      subtitle={`Signed in as ${session?.user.name ?? "organizer"}`}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      rightSlot={<NotificationBell unreadCount={unreadCount} href="/(organizer)/notifications" />}
    >
      {loading && !summary ? (
        <View style={styles.stack} accessibilityLabel="Loading organizer home">
          <Skeleton height={148} radius={radii.lg} />
          <SkeletonStats count={3} />
          <SkeletonList count={2} />
        </View>
      ) : null}

      {!loading && error && !summary ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {summary ? (
        <View style={styles.stack}>
          <TutorialPopupModal
            visible={showOrganizerTutorialPopup}
            tutorial={organizerTutorial}
            onCta={onStepClick}
            onFinish={markPopupSeen}
            onSkipAll={dismiss}
            testID="organizer-tutorial-popup"
          />

          {refreshError ? (
            <InlineBanner
              tone="error"
              message={`Couldn’t refresh: ${refreshError}`}
              actionLabel="Retry"
              onAction={() => void load("refresh")}
            />
          ) : null}

          {secondaryNote ? <InlineBanner tone="attention" message={secondaryNote} /> : null}

          {hero ? <OrganizerHomeHero hero={hero} /> : null}

          <PushPermissionPrompt role="organizer" />

          <ContinueSetupCard />

          <NextStepsCard role="organizer" />

          {upcoming.length > 0 ? <FillSlotsCard eventIds={upcoming.map((event) => event.id)} /> : null}

          <View style={styles.statsBlock}>
            <StatGrid>
              <StatTile
                icon="hourglass-outline"
                label="Pending"
                value={summary.stats.vendorRequests}
                tone={summary.stats.vendorRequests > 0 ? "accent" : "default"}
                onPress={() => router.push("/(organizer)/vendors?tab=requests" as Href)}
              />
              <StatTile
                icon="card-outline"
                label="Payment due"
                value={summary.stats.paymentRequired}
                tone={summary.stats.paymentRequired > 0 ? "urgent" : "default"}
                onPress={() => router.push("/(organizer)/money")}
              />
              <StatTile
                icon="calendar-outline"
                label="Events"
                value={summary.stats.events}
                onPress={() =>
                  router.push(
                    summary.stats.events === 0 ? "/(organizer)/launch" : ("/(organizer)/events" as Href),
                  )
                }
              />
              {statsExpanded ? (
                <>
                  <StatTile
                    icon="checkmark-circle-outline"
                    label="Approved"
                    value={summary.stats.approvedVendors}
                    onPress={() =>
                      router.push("/(organizer)/vendors?tab=requests&filter=awaiting_payment" as Href)
                    }
                  />
                  <StatTile
                    icon="cash-outline"
                    label="Paid"
                    value={summary.stats.paidVendors}
                    onPress={() => router.push("/(organizer)/money")}
                  />
                  <StatTile
                    icon="storefront-outline"
                    label="Open opps"
                    value={summary.stats.openOpportunities}
                    onPress={() =>
                      router.push(
                        summary.stats.events === 0 ? "/(organizer)/launch" : ("/(organizer)/events" as Href),
                      )
                    }
                  />
                </>
              ) : null}
            </StatGrid>
            <Button
              label={statsExpanded ? "Hide stats" : "More stats"}
              icon={statsExpanded ? "chevron-up" : "chevron-down"}
              variant="ghost"
              size="sm"
              style={styles.moreStats}
              accessibilityState={{ expanded: statsExpanded }}
              onPress={() => setStatsExpanded((v) => !v)}
            />
          </View>

          {showOrganizerTutorial ? (
            <GuidedTutorialChecklist
              tutorial={organizerTutorial}
              onStepClick={onStepClick}
              onDismiss={dismiss}
              onPushClick={onPushClick}
              testID="organizer-home-tutorial"
            />
          ) : null}

          {attentionItems.length === 0 && hero?.tone === "urgent" ? null : (
            <View style={styles.section}>
              <SectionHeader title="Needs attention" icon="alert-circle-outline" />
              {attentionItems.length === 0 ? (
                <EmptyPanel
                  icon="checkmark-done-outline"
                  title="All clear"
                  message="No urgent organizer actions right now."
                />
              ) : (
                <View style={styles.list}>
                  {attentionItems.map((item) => {
                    const cta =
                      item.id === "public-profile"
                        ? "Create profile"
                        : item.id === "payment-required"
                          ? "Open Money"
                          : "Review vendors"
                    return (
                      <PressableScale
                        key={item.id}
                        onPress={() => router.push(attentionHref(item.id))}
                        accessibilityRole="button"
                        accessibilityLabel={[item.label, item.description, cta].filter(Boolean).join(". ")}
                      >
                        <Surface
                          variant={item.severity === "warning" ? "urgent" : "default"}
                          style={styles.attentionCard}
                        >
                          <View style={styles.attentionText}>
                            <Meta style={styles.cardTitle}>{item.label}</Meta>
                            {item.description ? <Meta>{item.description}</Meta> : null}
                            <Meta style={styles.cardCta}>{cta} →</Meta>
                          </View>
                          <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
                        </Surface>
                      </PressableScale>
                    )
                  })}
                </View>
              )}
            </View>
          )}

          {upcoming.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader
                title="Upcoming events"
                icon="calendar-outline"
                actionLabel="See all"
                onAction={() => router.push("/(organizer)/events")}
              />
              <View style={styles.list}>
                {upcoming.map((event) => (
                  <OrganizerEventCard key={event.id} event={event} showStatus={false} />
                ))}
              </View>
            </View>
          ) : !isZeroState ? (
            <View style={styles.section}>
              <SectionHeader
                title="Upcoming events"
                icon="calendar-outline"
                actionLabel="See all"
                onAction={() => router.push("/(organizer)/events")}
              />
              <EmptyPanel
                icon="calendar-outline"
                title="No upcoming events"
                message="Past events stay in Events. Launch a new opportunity anytime."
                actionLabel="Launch event"
                onAction={() => router.push("/(organizer)/launch")}
              />
            </View>
          ) : null}

          {!isZeroState ? (
            <View style={styles.section}>
              <SectionHeader
                title="Recent requests"
                icon="people-outline"
                actionLabel={recentRequests.length > 0 ? "See all" : undefined}
                onAction={() => router.push("/(organizer)/vendors?tab=requests" as Href)}
              />
              {recentRequests.length === 0 ? (
                <EmptyPanel
                  icon="people-outline"
                  title="No requests yet"
                  message="Vendor applications show up here as soon as they apply."
                  actionLabel="Discover vendors"
                  onAction={() => router.push("/(organizer)/vendors?tab=discover" as Href)}
                />
              ) : (
                <ListGroup>
                  {recentRequests.map((request) => (
                    <ListRow
                      key={request.id}
                      icon="storefront-outline"
                      title={request.vendorBusinessName}
                      meta={[request.eventName, request.submittedAt].filter(Boolean).join(" · ")}
                      rightSlot={
                        <StatusBadge
                          label={organizerRequestStatusLabel(request.status)}
                          tone={organizerRequestStatusTone(request.status)}
                        />
                      }
                      onPress={() => router.push(requestVendorsHref(request))}
                    />
                  ))}
                </ListGroup>
              )}
            </View>
          ) : null}

          {summary.financial ? (
            <PressableScale
              onPress={() => router.push("/(organizer)/money")}
              accessibilityRole="button"
              accessibilityLabel="Money snapshot. Open Money"
            >
              <Surface elevated style={styles.card}>
                <SectionHeader title="Money snapshot" icon="cash-outline" />
                <Meta style={styles.money}>
                  {formatMoneyOrZero(summary.financial.collectedToDate ?? 0, summary.financial.currency)}{" "}
                  collected
                </Meta>
                <Meta>
                  Pending{" "}
                  {formatMoneyOrZero(summary.financial.pendingPayments ?? 0, summary.financial.currency)}
                </Meta>
                <Meta style={styles.cardCta}>Open Money →</Meta>
              </Surface>
            </PressableScale>
          ) : null}

          <OrganizerIntelligenceCard
            experiments={summary.intelligence.experiments}
            diagnostics={summary.intelligence.diagnostics}
            recommendations={summary.intelligence.recommendations}
            eventHighlights={summary.intelligence.eventHighlights}
            onEventPress={(eventId) => router.push(`/(organizer)/events/${eventId}` as Href)}
            onRecommendationPress={(recommendation) => {
              const action = recommendationAction(recommendation)
              return {
                label: action.label,
                onPress: () => {
                  void trackMobileEvent({
                    event: "organizer_experiment_recommendation_action_clicked",
                    source: "mobile_organizer_home",
                    auth,
                    metadata: {
                      recommendationId: recommendation.id,
                      experimentId: recommendation.experimentId ?? null,
                      funnelStage: recommendation.funnelStage,
                      decision: recommendation.decision.status,
                      confidence: recommendation.confidenceLabel,
                    },
                  })
                  void trackMobileEvent({
                    event: "organizer_experiment_decision_recorded",
                    source: "mobile_organizer_home",
                    auth,
                    metadata: {
                      experimentId: recommendation.experimentId ?? null,
                      decision: recommendation.decision.status,
                      confidence: recommendation.confidenceLabel,
                    },
                  })
                  if (action.href) {
                    router.push(action.href)
                    return
                  }
                  if (action.externalUrl) {
                    void openExternalUrl(action.externalUrl)
                  }
                },
              }
            }}
          />

          {checklistVisible.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader
                title={summary?.activation ? `Next steps · ${summary.activation.percent}% set up` : "Next steps"}
                icon="list-outline"
              />
              <ListGroup>
                {checklistVisible.map((item) => (
                  <ListRow
                    key={item.id}
                    icon="ellipse-outline"
                    title={item.label}
                    meta={item.ctaLabel ? `${item.ctaLabel} →` : undefined}
                    onPress={() => handleChecklistPress(item)}
                  />
                ))}
              </ListGroup>
            </View>
          ) : null}

          <PressableScale
            onPress={() => router.push("/(organizer)/referrals" as Href)}
            accessibilityRole="button"
            accessibilityLabel="Referral invites. Invite organizers or vendors and earn LNKup credit toward booth fees"
            testID="organizer-home-referral-cta"
          >
            <Surface accentBorderTop style={styles.recruitBanner}>
              <View style={styles.recruitRow}>
                <View style={styles.recruitIcon}>
                  <AppIcon name="gift-outline" size={20} color={colors.black} />
                </View>
                <View style={styles.recruitCopy}>
                  <Eyebrow style={styles.recruitEyebrow}>Referral Invites</Eyebrow>
                  <SectionTitle style={styles.recruitTitle}>
                    Invite organizers or vendors — earn LNKup credit toward booth fees
                  </SectionTitle>
                </View>
              </View>
              <Body style={styles.recruitBody}>
                Credits apply to booth fees at checkout — not a bank withdrawal.
              </Body>
              <Meta style={styles.recruitCta}>Get invite links →</Meta>
            </Surface>
          </PressableScale>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.lg,
  },
  recruitBanner: {
    backgroundColor: colors.black,
    borderTopColor: colors.lime,
    gap: spacing.sm,
  },
  recruitRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  recruitIcon: {
    width: 40,
    height: 40,
    borderRadius: radii.md,
    backgroundColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
  },
  recruitCopy: {
    flex: 1,
    gap: 2,
  },
  recruitEyebrow: {
    color: colors.lime,
  },
  recruitTitle: {
    color: colors.white,
    fontSize: typeScale.bodyLarge,
    lineHeight: 22,
  },
  recruitBody: {
    color: colors.gray200,
  },
  recruitCta: {
    color: colors.lime,
    fontFamily: fonts.bodySemi,
  },
  statsBlock: {
    gap: spacing.xs,
  },
  moreStats: {
    alignSelf: "flex-start",
  },
  section: {
    gap: spacing.md,
  },
  list: {
    gap: spacing.sm,
  },
  card: {
    gap: spacing.xs,
  },
  attentionCard: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  attentionText: {
    flex: 1,
    gap: spacing.xs,
  },
  cardTitle: {
    fontFamily: fonts.bodySemi,
    color: colors.black,
    fontSize: typeScale.callout,
  },
  cardCta: {
    marginTop: spacing.xs,
    fontFamily: fonts.bodySemi,
    color: colors.black,
  },
  money: {
    fontSize: typeScale.headline,
    lineHeight: 28,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
})
