import { useState } from "react"
import { StyleSheet, View } from "react-native"
import { Redirect, router } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { Body, DisplayTitle, Meta } from "@/components/ui/typography"
import { getWpRegistrationUrl } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, layout, spacing } from "@/lib/theme"

/** Signed-in account with no vendor, organizer or partner role. */
export default function NoAccessScreen() {
  const { isAuthenticated, isVendor, isOrganizer, isPartner, session, logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)

  if (!isAuthenticated) return <Redirect href="/login" />
  if (isOrganizer) return <Redirect href="/(organizer)" />
  if (isVendor) return <Redirect href="/(vendor)" />
  if (isPartner) return <Redirect href="/(partner)" />

  async function handleSignOut() {
    if (signingOut) return
    setSigningOut(true)
    try {
      await logout()
      router.replace("/login")
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.container}>
        <DisplayTitle accessibilityRole="header">This account isn't set up for LNKup</DisplayTitle>
        <Body>
          {session?.user.name ? `${session.user.name}, your` : "Your"} login works, but it doesn't have a vendor or
          organizer account yet.
        </Body>
        <Surface elevated style={styles.card}>
          <Meta>Create a vendor or organizer account with a different email, or contact LNKup support to add a role to
            this one.</Meta>
          <Button
            label="Create a vendor account"
            onPress={() => void openExternalUrl(getWpRegistrationUrl({ role: "vendor" }))}
          />
          <Button
            label="Create an organizer account"
            variant="secondary"
            onPress={() => void openExternalUrl(getWpRegistrationUrl({ role: "organizer" }))}
          />
        </Surface>
        <Button label={signingOut ? "Signing out…" : "Sign out"} variant="ghost" loading={signingOut} onPress={handleSignOut} />
      </View>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  container: {
    flex: 1,
    padding: spacing.xl,
    gap: spacing.md,
    justifyContent: "center",
    maxWidth: layout.formMaxWidth,
    width: "100%",
    alignSelf: "center",
  },
  card: { gap: spacing.md },
})
