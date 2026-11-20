import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, type Href, useFocusEffect } from "expo-router"

import { GuidedTutorialChecklist } from "@/components/guided-tutorial-checklist"
import { NotificationBell } from "@/components/notification-bell"
import { OpportunityCard } from "@/components/opportunity-card"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { InlineBanner } from "@/components/ui/banner"
import { PressableScale } from "@/components/ui/pressable-scale"
import { SectionHeader } from "@/components/ui/section-header"
import { Skeleton, SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { EventDayCard } from "@/components/vendor/event-day-card"
import { NextStepCard } from "@/components/vendor/next-step-card"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchClosingSoonOpportunities,
  fetchRecommendedOpportunities,
  type MarketplaceOpportunity,
} from "@/lib/api/marketplace"
import { fetchNotifications } from "@/lib/api/notifications"
import { fetchVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { fetchVendorReadiness, type VendorReadiness } from "@/lib/api/vendor-readiness"
import { fetchVendorFavorites } from "@/lib/api/vendor-favorites"
import { useVendorFavorites } from "@/lib/hooks/use-vendor-favorites"
import { useAuth } from "@/lib/auth/auth-context"
import { getUnreadCount } from "@/lib/notifications/read-state"
import { syncAppBadge } from "@/lib/push/notifications"
import { buildVendorTutorial } from "@/lib/tutorials/build-vendor-tutorial"
import { useRoleTutorial } from "@/lib/tutorials/use-role-tutorial"
import { eventDayBookings, vendorLifecycleCounts, vendorNextStep } from "@/lib/vendor/vendor-stage"
import { colors, fonts, radii, spacing } from "@/lib/theme"

function openDetail(item: MarketplaceOpportunity) {
  if (!item.opportunityId) return
  router.push(`/opportunity/${item.opportunityId}` as Href)
}

function openApply(item: MarketplaceOpportunity) {
  if (!item.opportunityId) return
  router.push(`/apply/${item.opportunityId}` as Href)
}

/**
 * Vendor Home: leads with today's event (if any) and the single next step for the vendor's
 * stage, then compact counts and discovery. Everything shown comes from live records.
 */
export default function VendorHomeScreen() {
  const { session, auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [rail, setRail] = useState<MarketplaceOpportunity[]>([])
  const [closingSoon, setClosingSoon] = useState<MarketplaceOpportunity[]>([])
  const [readiness, setReadiness] = useState<VendorReadiness | null>(null)
  const [requests, setRequests] = useState<VendorRequest[]>([])
  const favorites = useVendorFavorites(auth, { autoLoad: false })
  const savedIds = favorites.savedIds
  const setSavedIds = favorites.setSavedIds
  const [savedPreview, setSavedPreview] = useState<MarketplaceOpportunity[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [railFailed, setRailFailed] = useState(false)
  const [railsLoading, setRailsLoading] = useState(true)
  const didFocusLoad = useRef(false)

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "initial") setLoading(true)
      else if (mode === "refresh") setRefreshing(true)
      setError(null)
      try {
        // Writes and payment returns invalidate this cache, so only pull-to-refresh forces; first load
        // shares the tab badge's in-flight request.
        const requestsResult = await fetchVendorRequests(auth, { force: mode === "refresh" })
        setRequests(requestsResult.items)
        // The next step only needs bookings; rails, readiness and favorites fill in as they arrive.
        setLoading(false)
        if (mode === "initial") setRailsLoading(true)

        const [recommended, closing, notifications, readinessResult, favoritesResult] = await Promise.allSettled([
          fetchRecommendedOpportunities(auth, { limit: 3 }),
          fetchClosingSoonOpportunities(auth, { limit: 3 }),
          fetchNotifications("vendor", { auth, userId: session?.user.id }),
          fetchVendorReadiness(auth),
          fetchVendorFavorites(auth),
        ])
        setRailFailed(recommended.status === "rejected")
        if (recommended.status === "fulfilled") setRail(recommended.value)
        if (closing.status === "fulfilled") setClosingSoon(closing.value)
        if (notifications.status === "fulfilled") {
          setUnreadCount(getUnreadCount(notifications.value))
          void syncAppBadge(getUnreadCount(notifications.value))
        }
        if (readinessResult.status === "fulfilled") setReadiness(readinessResult.value)
        if (favoritesResult.status === "fulfilled") {
          setSavedIds(new Set(favoritesResult.value.opportunityIds.filter(Boolean)))
          setSavedPreview(favoritesResult.value.items.slice(0, 2))
        }
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRailsLoading(false)
        setRefreshing(false)
      }
    },
    [auth, session?.user.id, setSavedIds],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  const counts = useMemo(() => vendorLifecycleCounts(requests), [requests])
  const eventDay = useMemo(() => eventDayBookings(requests)[0] ?? null, [requests])
  const next = useMemo(
    () => vendorNextStep(requests, readiness ? readiness.profileCompletion : null),
    [requests, readiness],
  )
  const showStrength = readiness != null && readiness.profileCompletion < 95 && next.step.kind !== "profile"

  async function handleToggleSave(item: MarketplaceOpportunity) {
    if (!item.opportunityId) return
    const id = item.opportunityId
    const nowSaved = await favorites.toggle(id)
    if (nowSaved === true) {
      setSavedPreview((prev) => (prev.some((p) => p.opportunityId === id) ? prev : [item, ...prev].slice(0, 2)))
    } else if (nowSaved === false) {
      setSavedPreview((prev) => prev.filter((p) => p.opportunityId !== id))
    }
  }

  const firstName = session?.user.name?.split(/\s+/)[0] ?? "there"

  const vendorTutorial = useMemo(
    () => buildVendorTutorial({ requests, favoritesCount: savedIds.size, readiness }),
    [requests, savedIds.size, readiness],
  )
  const { visible: showVendorTutorial, dismiss, onStepClick, onPushClick } = useRoleTutorial(
    "vendor",
    vendorTutorial,
    session?.user.id ?? session?.username,
    auth,
  )
  const showTutorial = showVendorTutorial && (next.stage === "new" || next.stage === "exploring")

  function renderOpportunity(item: MarketplaceOpportunity) {
    const id = item.opportunityId
    return (
      <OpportunityCard
        key={`${item.id}-${id ?? ""}`}
        item={item}
        saved={id ? savedIds.has(id) : false}
        onToggleSave={id ? () => void handleToggleSave(item) : undefined}
        onPress={id ? () => openDetail(item) : undefined}
        onApply={id ? () => openApply(item) : undefined}
      />
    )
  }

  return (
    <Screen
      title={`Hi, ${firstName}`}
      subtitle="Apply for free. Pay only when accepted."
      rightSlot={<NotificationBell unreadCount={unreadCount} href="/(vendor)/notifications" />}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      {loading ? <HomeSkeleton /> : null}

      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("initial")} />
      ) : null}

      {!loading && !error ? (
        <>
          {eventDay ? <EventDayCard booking={eventDay} /> : null}

          <NextStepCard step={next.step} />

          <StatGrid>
            <StatTile
              icon="alert-circle-outline"
              label="Needs action"
              value={counts.action}
              tone={counts.action > 0 ? "urgent" : "default"}
              onPress={() => router.push("/(vendor)/requests?filter=action")}
            />
            <StatTile
              icon="hourglass-outline"
              label="In review"
              value={counts.review}
              onPress={() => router.push("/(vendor)/requests?filter=review")}
            />
            <StatTile
              icon="ticket-outline"
              label="Confirmed"
              value={counts.confirmed}
              onPress={() => router.push("/(vendor)/requests?filter=confirmed")}
            />
          </StatGrid>

          {showTutorial ? (
            <GuidedTutorialChecklist
              tutorial={vendorTutorial}
              onStepClick={onStepClick}
              onDismiss={dismiss}
              onPushClick={onPushClick}
              testID="vendor-home-tutorial"
            />
          ) : null}

          {showStrength && readiness ? (
            <PressableScale
              onPress={() => router.push("/(vendor)/profile")}
              accessibilityRole="button"
              accessibilityLabel={`Profile strength ${Math.round(readiness.profileCompletion)} percent. Improve profile`}
            >
              <Surface style={styles.readinessCard}>
                <View style={styles.readinessRing}>
                  <Meta style={styles.readinessPct}>{Math.round(readiness.profileCompletion)}%</Meta>
                </View>
                <View style={styles.readinessCopy}>
                  <SectionTitle style={styles.readinessTitle}>Profile strength</SectionTitle>
                  <Meta numberOfLines={2}>
                    {readiness.nextAction?.label ||
                      readiness.headline ||
                      "A complete profile gets reviewed faster."}
                  </Meta>
                </View>
                <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
              </Surface>
            </PressableScale>
          ) : null}

          <View style={styles.section}>
            <SectionHeader
              title="Recommended for you"
              icon="sparkles-outline"
              actionLabel="See all"
              onAction={() => router.push("/(vendor)/find")}
            />
            {railsLoading && rail.length === 0 ? (
              <SkeletonList count={2} />
            ) : railFailed && rail.length === 0 ? (
              <InlineBanner
                tone="error"
                message="Couldn't load recommendations."
                actionLabel="Retry"
                onAction={() => void load("refresh")}
              />
            ) : rail.length === 0 ? (
              <EmptyPanel
                icon="storefront-outline"
                title="No recommendations yet"
                message="As organizers open applications in your area, matches show up here."
                actionLabel="Explore events"
                onAction={() => router.push("/(vendor)/find")}
              />
            ) : (
              <View style={styles.list}>{rail.map(renderOpportunity)}</View>
            )}
          </View>

          {closingSoon.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader
                title="Closing soon"
                icon="alarm-outline"
                actionLabel="See all"
                onAction={() => router.push({ pathname: "/(vendor)/find", params: { sort: "closing_soon" } })}
              />
              <View style={styles.list}>{closingSoon.map(renderOpportunity)}</View>
            </View>
          ) : null}

          {savedPreview.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader
                title="Saved"
                icon="heart-outline"
                actionLabel={`See all${savedIds.size ? ` (${savedIds.size})` : ""}`}
                onAction={() => router.push("/(vendor)/favorites")}
              />
              <View style={styles.list}>{savedPreview.map(renderOpportunity)}</View>
            </View>
          ) : null}

          <PressableScale
            onPress={() => router.push("/(vendor)/referrals" as Href)}
            accessibilityRole="button"
            accessibilityLabel="Referral invites. Earn LNKup credit toward booth fees"
            testID="vendor-home-referral-cta"
          >
            <Surface style={styles.recruit}>
              <View style={styles.recruitIcon}>
                <AppIcon name="gift-outline" size={20} color={colors.black} />
              </View>
              <View style={styles.readinessCopy}>
                <SectionTitle style={styles.readinessTitle}>Invite and earn booth credit</SectionTitle>
                <Meta numberOfLines={2}>Credits apply to booth fees at checkout — not a cash withdrawal.</Meta>
              </View>
              <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
            </Surface>
          </PressableScale>
        </>
      ) : null}
    </Screen>
  )
}

/** First-paint placeholder that mirrors the Home layout (next step, stats, cards). */
function HomeSkeleton() {
  return (
    <View style={styles.skeleton} accessibilityLabel="Loading your marketplace">
      <Skeleton height={168} radius={radii.lg} />
      <SkeletonStats count={3} />
      <Skeleton width="45%" height={18} />
      <SkeletonList count={2} withMedia />
    </View>
  )
}

const styles = StyleSheet.create({
  skeleton: { gap: spacing.lg },
  readinessCard: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  readinessRing: {
    width: 56,
    height: 56,
    borderRadius: 28,
    borderWidth: 4,
    borderColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  readinessPct: { fontFamily: fonts.bodyBold, color: colors.black },
  readinessCopy: { flex: 1, gap: 2 },
  readinessTitle: { fontSize: 16 },
  recruit: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  recruitIcon: {
    width: 40,
    height: 40,
    borderRadius: radii.md,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
  },
  section: { gap: spacing.md },
  list: { gap: spacing.md },
})
