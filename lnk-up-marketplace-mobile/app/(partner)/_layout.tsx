import { Redirect, Tabs, useGlobalSearchParams, useSegments } from "expo-router"
import * as Linking from "expo-linking"
import { View } from "react-native"

import { tabIcon, useTabBarScreenOptions } from "@/components/tab-bar"
import { useAuth } from "@/lib/auth/auth-context"
import { shellReturnTo } from "@/lib/navigation/current-href"

/** Partner home for City Connectors, affiliates and strategic partner contacts. */
export default function PartnerLayout() {
  const { isAuthenticated, isPartner, isOrganizer, isVendor } = useAuth()
  const { screenOptions, tabIconSize } = useTabBarScreenOptions()
  const segments = useSegments()
  const params = useGlobalSearchParams()
  const linkingUrl = Linking.useLinkingURL()

  if (!isAuthenticated) {
    const returnTo = shellReturnTo("(partner)", segments, params, linkingUrl)
    return <Redirect href={{ pathname: "/login", params: { returnTo } }} />
  }

  if (!isPartner) {
    return <Redirect href={isOrganizer ? "/(organizer)" : isVendor ? "/(vendor)" : "/no-access"} />
  }

  return (
    <View style={{ flex: 1 }}>
      <Tabs screenOptions={screenOptions}>
        <Tabs.Screen
          name="index"
          options={{ title: "Home", tabBarIcon: tabIcon("home", "home-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="referrals"
          options={{ title: "Referrals", tabBarIcon: tabIcon("people", "people-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="leads"
          options={{ title: "Leads", tabBarIcon: tabIcon("flag", "flag-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="earnings"
          options={{ title: "Earnings", tabBarIcon: tabIcon("wallet", "wallet-outline", tabIconSize) }}
        />
        <Tabs.Screen
          name="more"
          options={{
            title: "More",
            tabBarIcon: tabIcon("ellipsis-horizontal", "ellipsis-horizontal", tabIconSize),
          }}
        />
        <Tabs.Screen name="cities" options={{ href: null }} />
        <Tabs.Screen name="notifications" options={{ href: null }} />
        <Tabs.Screen name="notification-settings" options={{ href: null }} />
      </Tabs>
    </View>
  )
}
