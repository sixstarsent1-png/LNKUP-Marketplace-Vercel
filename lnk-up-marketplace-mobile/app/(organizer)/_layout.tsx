import { useEffect, useState } from "react"
import { AppState, View, type AppStateStatus } from "react-native"
import { Redirect, Tabs, useGlobalSearchParams, useSegments } from "expo-router"
import * as Linking from "expo-linking"

import { tabIcon, useTabBarScreenOptions } from "@/components/tab-bar"
import {
  fetchOrganizerRequests,
  subscribeOrganizerRequests,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import { useAuth } from "@/lib/auth/auth-context"
import { shellReturnTo } from "@/lib/navigation/current-href"

const BADGE_REFRESH_MS = 60_000

function countPending(items: OrganizerVendorRequest[]): number {
  return items.filter((i) => i.status === "submitted" || i.status === "under_review").length
}

export default function OrganizerLayout() {
  const { isAuthenticated, isOrganizer, isVendor, isPartner, auth } = useAuth()
  const { screenOptions, tabIconSize } = useTabBarScreenOptions()
  const [pendingCount, setPendingCount] = useState(0)
  const segments = useSegments()
  const params = useGlobalSearchParams()
  const linkingUrl = Linking.useLinkingURL()

  useEffect(() => {
    if (!isAuthenticated || !isOrganizer) return
    // Any screen that loads requests updates the badge; the timer only runs in the foreground.
    const unsubscribe = subscribeOrganizerRequests(auth, ({ items }) => setPendingCount(countPending(items)))
    let timer: ReturnType<typeof setInterval> | null = null
    const refresh = () => {
      void fetchOrganizerRequests(auth).catch(() => undefined)
    }
    const start = () => {
      if (timer) return
      refresh()
      timer = setInterval(refresh, BADGE_REFRESH_MS)
    }
    const stop = () => {
      if (timer) clearInterval(timer)
      timer = null
    }
    if (AppState.currentState === "active") start()
    const sub = AppState.addEventListener("change", (next: AppStateStatus) => {
      if (next === "active") start()
      else stop()
    })
    return () => {
      unsubscribe()
      stop()
      sub.remove()
    }
  }, [auth, isAuthenticated, isOrganizer])

  if (!isAuthenticated) {
    const returnTo = shellReturnTo("(organizer)", segments, params, linkingUrl)
    return <Redirect href={{ pathname: "/login", params: { returnTo } }} />
  }

  if (!isOrganizer) {
    return <Redirect href={isVendor ? "/(vendor)" : isPartner ? "/(partner)" : "/no-access"} />
  }

  return (
    <View style={{ flex: 1 }}>
      <Tabs screenOptions={screenOptions}>
        <Tabs.Screen
          name="index"
          options={{ title: "Home", tabBarIcon: tabIcon("home", "home-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="events"
          options={{ title: "Events", tabBarIcon: tabIcon("calendar", "calendar-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="vendors"
          options={{
            title: "Vendors",
            tabBarBadge: pendingCount > 0 ? pendingCount : undefined,
            tabBarAccessibilityLabel:
              pendingCount > 0 ? `Vendors, ${pendingCount} to review` : "Vendors",
            tabBarIcon: tabIcon("people", "people-outline", tabIconSize),
          }}
        />
        <Tabs.Screen
          name="money"
          options={{ title: "Money", tabBarIcon: tabIcon("cash", "cash-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="more"
          options={{
            title: "More",
            tabBarIcon: tabIcon("ellipsis-horizontal", "ellipsis-horizontal", tabIconSize),
          }}
        />
        <Tabs.Screen name="notifications" options={{ href: null }} />
        <Tabs.Screen name="notification-settings" options={{ href: null }} />
        <Tabs.Screen name="event-comms/[eventId]" options={{ href: null }} />
        <Tabs.Screen name="launch" options={{ href: null }} />
        <Tabs.Screen name="referrals" options={{ href: null }} />
        <Tabs.Screen name="packet" options={{ href: null }} />
        <Tabs.Screen name="profile" options={{ href: null }} />
      </Tabs>
    </View>
  )
}
