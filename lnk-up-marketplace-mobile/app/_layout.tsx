import { Stack } from "expo-router"
import { StatusBar } from "expo-status-bar"
import { useEffect } from "react"
import * as SplashScreen from "expo-splash-screen"
import "react-native-reanimated"

import { AuthProvider, useAuth } from "@/lib/auth/auth-context"
import { DemoDataBanner } from "@/components/demo-data-banner"
import { OfflineBanner } from "@/components/offline-banner"
import { PendingDestinationRouter } from "@/components/pending-destination-router"
import { FeedbackHost } from "@/components/ui/feedback-host"
import { PushBootstrap } from "@/components/push-bootstrap"
import { StripeAppProvider } from "@/components/stripe-app-provider"
import { WebPaymentHost } from "@/components/web-payment-host"
import { useLnkupFonts } from "@/lib/fonts/use-lnkup-fonts"
import { checkAndApplyOtaUpdate } from "@/lib/updates/check-for-ota"
import { colors } from "@/lib/theme"

SplashScreen.preventAutoHideAsync().catch(() => undefined)

export { ErrorBoundary } from "expo-router"

function RootNavigator() {
  const { isLoading } = useAuth()
  const fontsLoaded = useLnkupFonts()

  useEffect(() => {
    void checkAndApplyOtaUpdate()
  }, [])

  useEffect(() => {
    if (!isLoading && fontsLoaded) {
      SplashScreen.hideAsync().catch(() => undefined)
    }
  }, [isLoading, fontsLoaded])

  if (isLoading || !fontsLoaded) {
    return null
  }

  return (
    <>
      <StatusBar style="dark" />
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.white },
        }}
      >
        <Stack.Screen name="index" />
        <Stack.Screen name="login" />
        <Stack.Screen name="signup" />
        <Stack.Screen name="(vendor)" />
        <Stack.Screen name="(organizer)" />
        <Stack.Screen name="(partner)" />
        <Stack.Screen name="pass/[applicationId]" options={{ headerShown: false }} />
        <Stack.Screen name="apply/[opportunityId]" options={{ headerShown: false }} />
        <Stack.Screen name="opportunity/[opportunityId]" options={{ headerShown: false }} />
        <Stack.Screen name="stripe-redirect" options={{ headerShown: false }} />
        <Stack.Screen name="storefront/vendor" options={{ headerShown: false }} />
        <Stack.Screen name="storefront/organizer" options={{ headerShown: false }} />
        <Stack.Screen name="no-access" />
        <Stack.Screen name="booking/[applicationId]" options={{ headerShown: false }} />
      </Stack>
      <PendingDestinationRouter />
      <FeedbackHost />
    </>
  )
}

export default function RootLayout() {
  return (
    <AuthProvider>
      <StripeAppProvider>
        <OfflineBanner />
        <DemoDataBanner />
        <PushBootstrap />
        <RootNavigator />
        <WebPaymentHost />
      </StripeAppProvider>
    </AuthProvider>
  )
}
