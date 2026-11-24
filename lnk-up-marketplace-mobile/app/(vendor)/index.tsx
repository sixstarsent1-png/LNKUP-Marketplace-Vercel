import { useCallback, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href, useFocusEffect } from "expo-router"

import { GuidedTutorialChecklist } from "@/components/guided-tutorial-checklist"
import { EventRail } from "@/components/marketplace/event-rail"
import { NotificationBell } from "@/components/notification-bell"
import { PushPermissionPrompt } from "@/components/push-permission-prompt"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { PressableScale } from "@/components/ui/pressable-scale"
import { Skeleton, SkeletonList } from "@/components/ui/skeleton"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { ActionCenter, buildActionItems } from "@/components/vendor/action-center"
import { EventDayCard } from "@/components/vendor/event-day-card"
import { NextStepCard } from "@/components/vendor/next-step-card"
import { UpcomingSpotlight } from "@/components/vendor/upcoming-spotlight"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { groupOpportunitiesLocally, toggleGroupSaved, type EventGroup } from "@/lib/api/event-groups"
import { fetchSectionGroups } from "@/lib/api/marketplace"
import { fetchInboxCount } from "@/lib/api/comms"
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
import { nextUpcomingBooking } from "@/lib/vendor/booking-lifecycle"
import { eventDayBookings, vendorLifecycleCounts, vendorNextStep } from "@/lib/vendor/vendor-stage"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

const RAIL_LIMIT = 6

/**
 * Vendor Home, ordered by what the vendor needs now: event day, actions with their events, the
 * next confirmed event, then grouped discovery. New vendors see profile setup before discovery.
 */
export default function VendorHomeScreen() {
  const { session, auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [recommended, setRecommended] = useState<EventGroup[]>([])
  const [closingSoon, setClosingSoon] = useState<EventGroup[]>([])
  const [savedGroups, setSavedGroups] = useState<EventGroup[]>([])
  const [readiness, setReadiness] = useState<VendorReadiness | null>(null)
  const [requests, setRequests] = useState<VendorRequest[]>([])
  const favorites = useVendorFavorites(auth, { autoLoad: false })
  const { savedIds, setSavedIds, setSaved } = favorites
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
        setLoading(false)
        if (mode === "initial") setRailsLoading(true)

        const [rec, closing, notifications, readinessResult, favoritesResult, serverCount] = await Promise.allSettled([
          fetchSectionGroups("recommended", auth, { limit: RAIL_LIMIT }),
          fetchSectionGroups("closing_soon", auth, { limit: RAIL_LIMIT }),
          fetchNotifications("vendor", { auth, userId: session?.user.id }),
          fetchVendorReadiness(auth),
          fetchVendorFavorites(auth),
          fetchInboxCount("vendor", auth),
        ])
        setRailFailed(rec.status === "rejected")
        if (rec.status === "fulfilled") setRecommended(rec.value)
        if (closing.status === "fulfilled") setClosingSoon(closing.value)
        if (serverCount.status === "fulfilled" && serverCount.value) {
          setUnreadCount(serverCount.value.unread)
          void syncAppBadge(serverCount.value.unread)
        } else if (notifications.status === "fulfilled") {
          setUnreadCount(getUnreadCount(notifications.value))
          void syncAppBadge(getUnreadCount(notifications.value))
        }
        if (readinessResult.status === "fulfilled") setReadiness(readinessResult.value)
        if (favoritesResult.status === "fulfilled") {
          setSavedIds(new Set(favoritesResult.value.opportunityIds.filter(Boolean)))
          setSavedGroups(groupOpportunitiesLocally(favoritesResult.value.items).slice(0, RAIL_LIMIT))
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
  const spotlight = useMemo(() => {
    const next = nextUpcomingBooking(requests)
    return next && next.id !== eventDay?.id ? next : null
  }, [requests, eventDay])
  const actions = useMemo(() => buildActionItems(requests, readiness), [requests, readiness])
  const next = useMemo(() => vendorNextStep(requests, readiness ? readiness.profileCompletion : null), [requests, readiness])
  const closingRail = useMemo(() => {
    const shown = new Set(recommended.map((g) => g.key))
    return closingSoon.filter((g) => !shown.has(g.key))
  }, [recommended, closingSoon])
  const isNew = next.stage === "new"
  const showStrength = readiness != null && readiness.profileCompletion < 95

  const handleToggleSave = useCallback(
    (group: EventGroup) => {
      void toggleGroupSaved(group, savedIds, setSaved)
    },
    [savedIds, setSaved],
  )

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

  const strength =
    showStrength && readiness ? (
      <PressableScale
        onPress={() => router.push("/(vendor)/profile")}
        accessibilityRole="button"
        accessibilityLabel={`Profile ${Math.round(readiness.profileCompletion)} percent complete. ${readiness.nextAction?.label ?? "Improve profile"}`}
      >
        <Surface style={styles.strength}>
          <View style={styles.strengthRing}>
            <Meta style={styles.strengthPct}>{Math.round(readiness.profileCompletion)}%</Meta>
          </View>
          <View style={styles.flex}>
            <Text style={styles.strengthTitle}>{readiness.nextAction?.label || "Finish your business profile"}</Text>
            <Meta numberOfLines={2}>Organizers see your profile when you apply. Completion is not verification.</Meta>
          </View>
          <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
        </Surface>
      </PressableScale>
    ) : null

  return (
    <Screen
      title={`Hi, ${firstName}`}
      rightSlot={<NotificationBell unreadCount={unreadCount} href="/(vendor)/notifications" />}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <Pressable
        onPress={() => router.push({ pathname: "/(vendor)/find", params: { focus: "1" } } as Href)}
        accessibilityRole="search"
        accessibilityLabel="Search events, cities or categories"
        style={({ pressed }) => [styles.search, pressed && styles.pressed]}
        testID="vendor-home-search"
      >
        <AppIcon name="search-outline" size={18} color={colors.gray500} />
        <Text style={styles.searchText}>Find events, cities or categories</Text>
      </Pressable>

      {loading ? <HomeSkeleton /> : null}

      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("initial")} /> : null}

      {!loading && !error ? (
        <>
          {eventDay ? <EventDayCard booking={eventDay} /> : null}

          {isNew ? <NextStepCard step={next.step} /> : null}
          {isNew ? strength : null}

          <ActionCenter items={actions} />

          {spotlight ? <UpcomingSpotlight booking={spotlight} /> : null}

          {!isNew && actions.length === 0 && !spotlight && counts.review > 0 ? (
            <Pressable
              onPress={() => router.push("/(vendor)/requests?filter=applications" as Href)}
              accessibilityRole="button"
              style={({ pressed }) => [styles.strip, pressed && styles.pressed]}
              testID="vendor-next-step"
            >
              <AppIcon name="hourglass-outline" size={18} color={colors.ink} />
              <Text style={styles.stripText}>
                {counts.review} application{counts.review === 1 ? "" : "s"} with organizers
              </Text>
              <AppIcon name="chevron-forward" size={16} color={colors.gray400} />
            </Pressable>
          ) : null}

          <PushPermissionPrompt role="vendor" />

          {railsLoading && recommended.length === 0 ? (
            <SkeletonList count={1} withMedia />
          ) : railFailed && recommended.length === 0 ? (
            <InlineBanner tone="error" message="Couldn't load recommendations." actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : recommended.length === 0 ? (
            <EmptyPanel
              icon="storefront-outline"
              title="No recommendations yet"
              message="As organizers open applications in your markets, matches show up here."
              actionLabel="Explore events"
              onAction={() => router.push("/(vendor)/find")}
            />
          ) : (
            <EventRail
              title="Recommended for you"
              icon="sparkles-outline"
              groups={recommended}
              savedIds={savedIds}
              onToggleSave={handleToggleSave}
              onSeeAll={() => router.push("/(vendor)/find")}
              testID="vendor-home-recommended"
            />
          )}

          <EventRail
            title="Applications closing soon"
            icon="alarm-outline"
            groups={closingRail}
            savedIds={savedIds}
            onToggleSave={handleToggleSave}
            onSeeAll={() => router.push({ pathname: "/(vendor)/find", params: { sort: "closing_soon" } })}
          />

          <EventRail
            title="Saved"
            icon="heart-outline"
            groups={savedGroups}
            savedIds={savedIds}
            onToggleSave={handleToggleSave}
            onSeeAll={() => router.push("/(vendor)/favorites")}
          />

          {!isNew ? strength : null}

          {showTutorial ? (
            <GuidedTutorialChecklist
              tutorial={vendorTutorial}
              onStepClick={onStepClick}
              onDismiss={dismiss}
              onPushClick={onPushClick}
              testID="vendor-home-tutorial"
            />
          ) : null}

          <PressableScale
            onPress={() => router.push("/(vendor)/referrals" as Href)}
            accessibilityRole="button"
            accessibilityLabel="Referral invites. Earn LNKup credit toward booth fees"
            testID="vendor-home-referral-cta"
          >
            <Surface style={styles.strength}>
              <View style={styles.recruitIcon}>
                <AppIcon name="gift-outline" size={20} color={colors.black} />
              </View>
              <View style={styles.flex}>
                <SectionTitle style={styles.strengthTitle}>Invite and earn booth credit</SectionTitle>
                <Meta numberOfLines={2}>Credits apply to booth fees at checkout. They can't be withdrawn as cash.</Meta>
              </View>
              <AppIcon name="chevron-forward" size={18} color={colors.gray500} />
            </Surface>
          </PressableScale>
        </>
      ) : null}
    </Screen>
  )
}

/** First-paint placeholder that mirrors the Home layout (actions, spotlight, rail). */
function HomeSkeleton() {
  return (
    <View style={styles.skeleton} accessibilityLabel="Loading your marketplace">
      <Skeleton height={140} radius={radii.lg} />
      <Skeleton height={96} radius={radii.lg} />
      <Skeleton width="45%" height={18} />
      <SkeletonList count={1} withMedia />
    </View>
  )
}

const styles = StyleSheet.create({
  skeleton: { gap: spacing.lg },
  pressed: { opacity: 0.75 },
  flex: { flex: 1, gap: 2 },
  search: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin + 4,
    paddingHorizontal: spacing.md,
    borderRadius: radii.pill,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    marginTop: -spacing.xs,
  },
  searchText: { fontFamily: fonts.body, fontSize: typeScale.callout, color: colors.gray500 },
  strip: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 8,
    paddingHorizontal: spacing.md,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  stripText: { flex: 1, fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  strength: { flexDirection: "row", alignItems: "center", gap: spacing.md, padding: spacing.md },
  strengthRing: {
    width: 48,
    height: 48,
    borderRadius: 24,
    borderWidth: 4,
    borderColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  strengthPct: { fontFamily: fonts.bodyBold, color: colors.black, fontSize: typeScale.caption },
  strengthTitle: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  recruitIcon: {
    width: 40,
    height: 40,
    borderRadius: radii.md,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
  },
})
