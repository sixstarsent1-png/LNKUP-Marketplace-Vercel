import { useEffect, useState } from "react"
import { Redirect, Tabs, useGlobalSearchParams, useSegments } from "expo-router"
import * as Linking from "expo-linking"
import { AppState, View, type AppStateStatus } from "react-native"

import { tabIcon, useTabBarScreenOptions } from "@/components/tab-bar"
import { fetchVendorRequests, subscribeVendorRequests } from "@/lib/api/vendor-requests"
import { vendorLifecycleCounts } from "@/lib/vendor/vendor-stage"
import { useAuth } from "@/lib/auth/auth-context"
import { shellReturnTo } from "@/lib/navigation/current-href"

const BADGE_REFRESH_MS = 60_000

export default function VendorLayout() {
  const { isAuthenticated, isVendor, isOrganizer, isPartner, auth } = useAuth()
  const { screenOptions, tabIconSize } = useTabBarScreenOptions()
  const [actionCount, setActionCount] = useState(0)
  const segments = useSegments()
  const params = useGlobalSearchParams()
  const linkingUrl = Linking.useLinkingURL()

  useEffect(() => {
    if (!isAuthenticated || !isVendor) return
    let active = true
    let timer: ReturnType<typeof setInterval> | null = null

    const unsubscribe = subscribeVendorRequests(auth, ({ items }) => {
      if (active) setActionCount(vendorLifecycleCounts(items).action)
    })
    const refresh = () => {
      void fetchVendorRequests(auth)
        .then(({ items }) => {
          if (active) setActionCount(vendorLifecycleCounts(items).action)
        })
        .catch(() => undefined)
    }
    const startPolling = () => {
      if (timer) return
      timer = setInterval(refresh, BADGE_REFRESH_MS)
    }
    const stopPolling = () => {
      if (timer) clearInterval(timer)
      timer = null
    }

    refresh()
    if (AppState.currentState === "active") startPolling()
    const sub = AppState.addEventListener("change", (next: AppStateStatus) => {
      if (next === "active") {
        refresh()
        startPolling()
      } else {
        stopPolling()
      }
    })

    return () => {
      active = false
      unsubscribe()
      stopPolling()
      sub.remove()
    }
  }, [auth, isAuthenticated, isVendor])

  if (!isAuthenticated) {
    const returnTo = shellReturnTo("(vendor)", segments, params, linkingUrl)
    return <Redirect href={{ pathname: "/login", params: { returnTo } }} />
  }

  if (!isVendor) {
    return <Redirect href={isOrganizer ? "/(organizer)" : isPartner ? "/(partner)" : "/no-access"} />
  }

  return (
    <View style={{ flex: 1 }}>
      <Tabs screenOptions={screenOptions}>
        <Tabs.Screen
          name="index"
          options={{ title: "Home", tabBarIcon: tabIcon("home", "home-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="find"
          options={{ title: "Explore", tabBarIcon: tabIcon("compass", "compass-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="requests"
          options={{
            title: "Bookings",
            tabBarBadge: actionCount > 0 ? actionCount : undefined,
            tabBarAccessibilityLabel: actionCount > 0 ? `Bookings, ${actionCount} need action` : "Bookings",
            tabBarIcon: tabIcon("ticket", "ticket-outline", tabIconSize),
          }}
        />
        <Tabs.Screen
          name="wallet"
          options={{ title: "Wallet", tabBarIcon: tabIcon("wallet", "wallet-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="more"
          options={{
            title: "Profile",
            tabBarIcon: tabIcon("person-circle", "person-circle-outline", tabIconSize),
          }}
        />
        <Tabs.Screen name="profile" options={{ href: null }} />
        <Tabs.Screen name="documents" options={{ href: null }} />
        <Tabs.Screen name="favorites" options={{ href: null }} />
        <Tabs.Screen name="growth" options={{ href: null }} />
        <Tabs.Screen name="reviews" options={{ href: null }} />
        <Tabs.Screen name="passport" options={{ href: null }} />
        <Tabs.Screen name="operations" options={{ href: null }} />
        <Tabs.Screen name="notifications" options={{ href: null }} />
        <Tabs.Screen name="notification-settings" options={{ href: null }} />
        <Tabs.Screen name="saved-searches" options={{ href: null }} />
        <Tabs.Screen name="referrals" options={{ href: null }} />
      </Tabs>
    </View>
  )
}
