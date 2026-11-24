import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { ActivityIndicator, Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, useFocusEffect, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { FilterChipRow } from "@/components/ui/chip"
import { isLiveApiEnabled } from "@/lib/api/config"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchInboxCount,
  fetchInboxPage,
  isCommsUnavailable,
  markTabRead,
  trackNotificationOpened,
} from "@/lib/api/comms"
import {
  fetchNotifications,
  markInboxRead,
  markInboxReadAll,
  type NotificationRole,
} from "@/lib/api/notifications"
import { useAuth } from "@/lib/auth/auth-context"
import { BackLink } from "@/components/ui/back-link"
import { showToast } from "@/lib/feedback/feedback"
import { inAppHrefForNotification } from "@/lib/push/notification-routing"
import { getUnreadCount, markAllRead, markRead } from "@/lib/notifications/read-state"
import { clearAppBadge, syncAppBadge } from "@/lib/push/notifications"
import {
  inboxTabsFor,
  notificationTypeLabel,
  type InboxTab,
  type LnkupNotification,
} from "@/lib/notifications/types"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

type NotificationsScreenProps = {
  role: NotificationRole
  prefsHref: string
}

const HOME_FOR_ROLE: Record<NotificationRole, Href> = {
  vendor: "/(vendor)",
  organizer: "/(organizer)",
  partner: "/(partner)",
}

/** "server" = paginated lnkup-communications inbox; "legacy" = composed feed (plugin not installed). */
type Source = "server" | "legacy"

function severityColor(severity?: LnkupNotification["severity"]): string {
  if (severity === "urgent") return colors.red600
  if (severity === "warning") return colors.amber700
  if (severity === "success") return colors.green700
  return colors.gray500
}

function relativeTime(iso?: string): string {
  if (!iso) return ""
  const then = Date.parse(iso)
  if (Number.isNaN(then)) return ""
  const minutes = Math.round((Date.now() - then) / 60000)
  if (minutes < 1) return "Just now"
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.round(hours / 24)
  if (days < 7) return `${days}d ago`
  return new Date(then).toLocaleDateString(undefined, { month: "short", day: "numeric" })
}

export function NotificationsScreen({ role, prefsHref }: NotificationsScreenProps) {
  const { auth, session } = useAuth()
  const userId = session?.user.id
  const tabs = useMemo(() => inboxTabsFor(role), [role])
  const [source, setSource] = useState<Source>(isLiveApiEnabled() ? "server" : "legacy")
  const [tab, setTab] = useState<InboxTab>("all")
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<LnkupNotification[]>([])
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [tabCounts, setTabCounts] = useState<Partial<Record<InboxTab, number>>>({})
  const didFocusLoad = useRef(false)
  const markingAll = useRef(false)
  const requestId = useRef(0)

  const refreshCounts = useCallback(async () => {
    const counts = await fetchInboxCount(role, auth)
    if (!counts) return
    setTabCounts(counts.byTab)
    void syncAppBadge(counts.unread)
  }, [auth, role])

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial", nextTab: InboxTab = tab) => {
      const current = ++requestId.current
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        if (source === "server") {
          try {
            const result = await fetchInboxPage(role, { tab: nextTab, page: 1, auth })
            if (current !== requestId.current) return
            setItems(result.items)
            setPage(1)
            setHasMore(result.hasMore)
            void refreshCounts()
            return
          } catch (err) {
            if (!isCommsUnavailable(err)) throw err
            setSource("legacy")
          }
        }
        const next = await fetchNotifications(role, { auth, userId })
        if (current !== requestId.current) return
        setItems(next)
        setHasMore(false)
        void syncAppBadge(getUnreadCount(next))
      } catch (err) {
        if (current === requestId.current) setError(appErrorFromWp(err))
      } finally {
        if (current === requestId.current) {
          setLoading(false)
          setRefreshing(false)
        }
      }
    },
    [auth, refreshCounts, role, source, tab, userId],
  )

  useEffect(() => {
    void load("initial")
    // Initial load only; tab changes call load directly.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source])

  useFocusEffect(
    useCallback(() => {
      if (!didFocusLoad.current) {
        didFocusLoad.current = true
        return
      }
      void load("refresh")
    }, [load]),
  )

  const handleTabChange = useCallback(
    (next: InboxTab) => {
      setTab(next)
      setItems([])
      void load("initial", next)
    },
    [load],
  )

  const handleLoadMore = useCallback(async () => {
    if (source !== "server" || !hasMore || loadingMore || loading) return
    setLoadingMore(true)
    try {
      const result = await fetchInboxPage(role, { tab, page: page + 1, auth })
      setItems((prev) => {
        const seen = new Set(prev.map((n) => n.id))
        return [...prev, ...result.items.filter((n) => !seen.has(n.id))]
      })
      setPage(result.page)
      setHasMore(result.hasMore)
    } catch {
      showToast("Couldn't load more notifications.", "error")
    } finally {
      setLoadingMore(false)
    }
  }, [auth, hasMore, loading, loadingMore, page, role, source, tab])

  const unreadCount = useMemo(() => {
    if (source === "server" && tabCounts[tab] != null) return tabCounts[tab] ?? 0
    return getUnreadCount(items)
  }, [items, source, tab, tabCounts])

  async function handleOpen(item: LnkupNotification) {
    if (!item.readAt) {
      setItems((prev) => prev.map((n) => (n.id === item.id ? { ...n, readAt: new Date().toISOString() } : n)))
      if (userId != null) void markRead(userId, item.id).catch(() => undefined)
      void markInboxRead(item.id, auth)
        .then(() => refreshCounts())
        .catch(() => undefined)
    }
    if (item.notificationId) void trackNotificationOpened(item.notificationId, auth)
    const href = inAppHrefForNotification(item, session?.user.roles)
    if (href) {
      router.push(href as never)
      return
    }
    showToast("This item is no longer available in the app.", "info")
  }

  async function handleMarkAll() {
    if (markingAll.current) return
    const ids = items.filter((n) => !n.readAt).map((n) => n.id)
    if (ids.length === 0 && unreadCount === 0) return
    markingAll.current = true
    const previous = items
    const now = new Date().toISOString()
    setItems((prev) => prev.map((n) => (n.readAt ? n : { ...n, readAt: now })))
    try {
      if (source === "server") {
        await markTabRead(role, tab, auth)
        await refreshCounts()
      } else {
        if (userId != null) await markAllRead(userId, ids)
        await markInboxReadAll(ids, auth)
        void clearAppBadge()
      }
    } catch {
      setItems(previous)
      showToast("Couldn't mark notifications as read. Try again.", "error")
    } finally {
      markingAll.current = false
    }
  }

  const rows = !loading && !error ? items : EMPTY_NOTIFICATIONS
  const emptyCopy = EMPTY_COPY[tab]

  return (
    <ScreenList
      title="Notifications"
      subtitle={unreadCount > 0 ? `${unreadCount} unread` : "You're caught up"}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      header={<BackLink label="Home" fallbackHref={HOME_FOR_ROLE[role]} />}
      rightSlot={
        unreadCount > 0 ? (
          <Pressable
            onPress={() => void handleMarkAll()}
            style={styles.markAll}
            accessibilityRole="button"
            accessibilityLabel={tab === "all" ? "Mark all notifications as read" : "Mark this category as read"}
          >
            <Text style={styles.markAllText}>Mark all</Text>
          </Pressable>
        ) : undefined
      }
      data={rows}
      keyExtractor={(item) => item.id}
      onEndReached={() => void handleLoadMore()}
      renderItem={(item) => {
        const unread = !item.readAt
        const when = relativeTime(item.createdAt)
        return (
          <Pressable
            style={[styles.card, unread && styles.cardUnread]}
            onPress={() => void handleOpen(item)}
            accessibilityRole="button"
            accessibilityLabel={`${unread ? "Unread. " : ""}${item.title}${when ? `, ${when}` : ""}`}
          >
            <View style={styles.cardTop}>
              <Text style={[styles.type, { color: severityColor(item.severity) }]}>
                {notificationTypeLabel(item.type)}
              </Text>
              <View style={styles.cardMeta}>
                {when ? <Text style={styles.time}>{when}</Text> : null}
                {unread ? <View style={styles.dot} /> : null}
              </View>
            </View>
            <Text style={styles.cardTitle}>{item.title}</Text>
            {item.message ? <Text style={styles.cardBody}>{item.message}</Text> : null}
            {item.actionLabel ? <Text style={styles.action}>{item.actionLabel} →</Text> : null}
          </Pressable>
        )
      }}
      listFooter={
        <>
          {loadingMore ? <ActivityIndicator color={colors.black} style={styles.more} /> : null}
          <Pressable
            style={styles.prefs}
            onPress={() => router.push(prefsHref as never)}
            accessibilityRole="button"
          >
            <Text style={styles.prefsText}>Notification settings</Text>
            <Text style={styles.prefsHint}>Choose email, push, digests and quiet hours</Text>
          </Pressable>
        </>
      }
      listHeader={
        <>
          {source === "server" ? (
            <FilterChipRow
              options={tabs.map((t) => ({ value: t.value, label: t.label, count: tabCounts[t.value] }))}
              value={tab}
              onChange={handleTabChange}
            />
          ) : null}

          {tab === "invitations" && role === "vendor" ? (
            <Pressable
              style={styles.prefs}
              onPress={() => router.push("/(vendor)/invitations" as Href)}
              accessibilityRole="button"
              accessibilityLabel="Open invitations to accept or decline"
            >
              <Text style={styles.prefsText}>Open invitations →</Text>
              <Text style={styles.prefsHint}>Accept or decline invitations from organizers</Text>
            </Pressable>
          ) : null}

          {loading ? <LoadingPanel label="Loading notifications…" variant="skeleton" count={4} /> : null}

          {!loading && error ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
          ) : null}

          {!loading && !error && items.length === 0 ? (
            <EmptyPanel title={tab === "all" ? "No notifications" : "Nothing here yet"} message={emptyCopy} />
          ) : null}
        </>
      }
    />
  )
}

const EMPTY_NOTIFICATIONS: LnkupNotification[] = []

const EMPTY_COPY: Record<InboxTab, string> = {
  all: "Updates from applications, payments, and events will show here.",
  applications: "Updates from applications, payments, and events will show here.",
  payments: "Updates from applications, payments, and events will show here.",
  events: "Updates from applications, payments, and events will show here.",
  opportunities: "New events that match your business will show here.",
  operations: "Event operations and recruiting updates will show here.",
  invitations: "Invitations you send or receive, and replies to them, will show here.",
  account: "Setup tips and account notices will show here.",
}

const styles = StyleSheet.create({
  markAll: {
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.sm,
  },
  markAllText: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  cardUnread: {
    borderColor: colors.black,
    backgroundColor: colors.gray50,
  },
  cardTop: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  cardMeta: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
  },
  type: {
    fontSize: typeScale.micro,
    fontWeight: "700",
    textTransform: "uppercase",
    letterSpacing: 0.6,
  },
  time: {
    fontSize: typeScale.micro,
    color: colors.gray500,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.limeDark,
  },
  cardTitle: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "700",
    color: colors.black,
  },
  cardBody: {
    fontSize: typeScale.body,
    color: colors.gray500,
    lineHeight: 20,
  },
  action: {
    marginTop: spacing.xs,
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
  more: {
    marginVertical: spacing.md,
  },
  prefs: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: 2,
  },
  prefsText: {
    fontSize: typeScale.callout,
    fontWeight: "700",
    color: colors.black,
  },
  prefsHint: {
    fontSize: typeScale.footnote,
    color: colors.gray500,
  },
})
