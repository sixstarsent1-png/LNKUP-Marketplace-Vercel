import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, useFocusEffect } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { FilterChipRow } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
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
  isActionRequiredNotification,
  notificationTypeLabel,
  type LnkupNotification,
  type NotificationFilter,
} from "@/lib/notifications/types"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const FILTERS: Array<{ id: NotificationFilter; label: string }> = [
  { id: "all", label: "All" },
  { id: "unread", label: "Unread" },
  { id: "action", label: "Action" },
]

type NotificationsScreenProps = {
  role: NotificationRole
  prefsHref: string
}

function severityColor(severity?: LnkupNotification["severity"]): string {
  if (severity === "urgent") return colors.red600
  if (severity === "warning") return colors.amber700
  if (severity === "success") return colors.green700
  return colors.gray500
}

export function NotificationsScreen({ role, prefsHref }: NotificationsScreenProps) {
  const { auth, session } = useAuth()
  const userId = session?.user.id
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<LnkupNotification[]>([])
  const [filter, setFilter] = useState<NotificationFilter>("all")
  const didFocusLoad = useRef(false)
  const markingAll = useRef(false)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") {
        setRefreshing(true)
      } else {
        setLoading(true)
      }
      setError(null)
      try {
        const next = await fetchNotifications(role, { auth, userId })
        setItems(next)
        void syncAppBadge(getUnreadCount(next))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, role, userId],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  useFocusEffect(
    useCallback(() => {
      if (!didFocusLoad.current) {
        didFocusLoad.current = true
        return
      }
      void load("refresh")
    }, [load]),
  )

  const unreadCount = useMemo(() => getUnreadCount(items), [items])

  const visible = useMemo(() => {
    if (filter === "unread") return items.filter((n) => !n.readAt)
    if (filter === "action") {
      return items.filter((n) => isActionRequiredNotification(n) && !n.readAt)
    }
    return items
  }, [filter, items])

  async function handleOpen(item: LnkupNotification) {
    if (userId != null && !item.readAt) {
      setItems((prev) => {
        const next = prev.map((n) => (n.id === item.id ? { ...n, readAt: new Date().toISOString() } : n))
        void syncAppBadge(getUnreadCount(next))
        return next
      })
      void markRead(userId, item.id).catch(() => undefined)
      void markInboxRead(item.id, auth).catch(() => undefined)
    }
    const href = inAppHrefForNotification(item, session?.user.roles)
    if (href) {
      router.push(href as never)
      return
    }
    showToast("This item is no longer available in the app.", "info")
  }

  async function handleMarkAll() {
    if (userId == null || markingAll.current) return
    const ids = items.filter((n) => !n.readAt).map((n) => n.id)
    if (ids.length === 0) return
    markingAll.current = true
    const previous = items
    const now = new Date().toISOString()
    setItems((prev) => prev.map((n) => (n.readAt ? n : { ...n, readAt: now })))
    try {
      await markAllRead(userId, ids)
      await markInboxReadAll(ids, auth)
      void clearAppBadge()
    } catch {
      setItems(previous)
      showToast("Couldn't mark notifications as read. Try again.", "error")
    } finally {
      markingAll.current = false
    }
  }

  const rows = !loading && !error ? visible : EMPTY_NOTIFICATIONS

  return (
    <ScreenList
      title="Notifications"
      subtitle={unreadCount > 0 ? `${unreadCount} unread` : "You're caught up"}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      header={<BackLink label="Home" fallbackHref={role === "organizer" ? "/(organizer)" : "/(vendor)"} />}
      rightSlot={
        unreadCount > 0 ? (
          <Pressable
            onPress={() => void handleMarkAll()}
            style={styles.markAll}
            accessibilityRole="button"
            accessibilityLabel="Mark all notifications as read"
          >
            <Text style={styles.markAllText}>Mark all</Text>
          </Pressable>
        ) : undefined
      }
      data={rows}
      keyExtractor={(item) => item.id}
      renderItem={(item) => {
        const unread = !item.readAt
        return (
          <Pressable
            style={[styles.card, unread && styles.cardUnread]}
            onPress={() => void handleOpen(item)}
            accessibilityRole="button"
            accessibilityLabel={`${unread ? "Unread. " : ""}${item.title}`}
          >
            <View style={styles.cardTop}>
              <Text style={[styles.type, { color: severityColor(item.severity) }]}>
                {notificationTypeLabel(item.type)}
              </Text>
              {unread ? <View style={styles.dot} /> : null}
            </View>
            <Text style={styles.cardTitle}>{item.title}</Text>
            {item.message ? <Text style={styles.cardBody}>{item.message}</Text> : null}
            {item.actionLabel ? <Text style={styles.action}>{item.actionLabel} →</Text> : null}
          </Pressable>
        )
      }}
      listFooter={
        <Pressable
          style={styles.prefs}
          onPress={() => router.push(prefsHref as never)}
          accessibilityRole="button"
        >
          <Text style={styles.prefsText}>Notification preferences</Text>
          <Text style={styles.prefsHint}>Push toggle on Profile / More</Text>
        </Pressable>
      }
      listHeader={
        <>
          <FilterChipRow
            options={FILTERS.map((chip) => ({
              value: chip.id,
              label: chip.label,
            }))}
            value={filter}
            onChange={setFilter}
          />

          {loading ? <LoadingPanel label="Loading notifications…" variant="skeleton" count={4} /> : null}

          {!loading && error ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
          ) : null}

          {!loading && !error && visible.length === 0 ? (
            <EmptyPanel
              title={filter === "all" ? "No notifications" : "Nothing here"}
              message={
                filter === "action"
                  ? "No action items right now."
                  : filter === "unread"
                    ? "You're all caught up."
                    : "Updates from applications, payments, and events will show here."
              }
            />
          ) : null}
        </>
      }
    />
  )
}

const EMPTY_NOTIFICATIONS: LnkupNotification[] = []

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
  type: {
    fontSize: typeScale.micro,
    fontWeight: "700",
    textTransform: "uppercase",
    letterSpacing: 0.6,
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
