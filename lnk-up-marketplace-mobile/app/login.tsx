import { useEffect, useState } from "react"
import {
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { Redirect, router, useLocalSearchParams } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { ErrorPanel } from "@/components/panel-states"
import { Surface } from "@/components/ui/surface"
import { Body, DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { LegalLinks } from "@/components/legal-links"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import type { HomeHref } from "@/lib/auth/roles"
import { constrainedColumnStyle, useBreakpoint } from "@/lib/layout/use-breakpoint"
import { setPendingDestination } from "@/lib/navigation/pending-destination"
import { colors, fonts, layout, radii, spacing } from "@/lib/theme"

export default function LoginScreen() {
  const { returnTo } = useLocalSearchParams<{ returnTo?: string }>()
  const {
    isAuthenticated,
    isLoading,
    login,
    enterDemoSession,
    resolveHome,
    lastError,
    clearError,
  } = useAuth()
  const { primaryButtonPaddingVertical } = useBreakpoint()
  const [username, setUsername] = useState("")
  const [appPassword, setAppPassword] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const [localError, setLocalError] = useState<string | null>(null)
  const [homeHref, setHomeHref] = useState<HomeHref | null>(null)
  const [signupRole, setSignupRole] = useState<"vendor" | "organizer">("vendor")

  // Remember the deep link; PendingDestinationRouter opens it after sign-in if the role allows it.
  useEffect(() => {
    const value = Array.isArray(returnTo) ? returnTo[0] : returnTo
    if (value) setPendingDestination(value)
  }, [returnTo])

  useEffect(() => {
    let active = true
    ;(async () => {
      if (!isAuthenticated || isLoading) return
      const home = await resolveHome()
      if (active) setHomeHref(home)
    })()
    return () => {
      active = false
    }
  }, [isAuthenticated, isLoading, resolveHome])

  if (isAuthenticated && homeHref) {
    return <Redirect href={homeHref} />
  }

  async function goHome() {
    router.replace(await resolveHome())
  }

  async function handleSubmit() {
    clearError()
    setLocalError(null)

    if (!username.trim() || !appPassword.trim()) {
      setLocalError("Enter your LNKup username and password.")
      return
    }

    if (!isLiveApiEnabled()) {
      setLocalError(
        "Live API is off. Continue with mock data, or set EXPO_PUBLIC_LNKUP_LIVE_API=true.",
      )
      return
    }

    setSubmitting(true)
    try {
      await login(username, appPassword)
      await goHome()
    } catch {
      // lastError set by AuthProvider
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDemo(role: "vendor" | "organizer") {
    clearError()
    setLocalError(null)
    await enterDemoSession({ displayName: username, role })
    router.replace(role === "organizer" ? "/(organizer)" : "/(vendor)")
  }

  function handleCreateAccount() {
    router.push({ pathname: "/signup", params: { role: signupRole } })
  }

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === "ios" ? "padding" : undefined}
      >
        <View style={[styles.container, constrainedColumnStyle(layout.formMaxWidth)]}>
          <Image
            source={require("@/assets/brand/lnkup-logo.png")}
            style={styles.logo}
            accessibilityIgnoresInvertColors
            accessibilityLabel="LNKup"
          />
          <DisplayTitle style={styles.title}>Sign in</DisplayTitle>
          <Body style={styles.subtitle}>
            Use your LNKup username and password — the same credentials as thelnkup.com.
          </Body>

          <Surface elevated style={styles.formCard}>
            <View style={styles.field}>
              <Meta style={styles.label}>Username</Meta>
              <TextInput
                autoCapitalize="none"
                autoCorrect={false}
                accessibilityLabel="Username"
                autoComplete="username"
                textContentType="username"
                value={username}
                onChangeText={setUsername}
                placeholder="your_username"
                placeholderTextColor={colors.gray400}
                style={styles.input}
              />
            </View>

            <View style={styles.field}>
              <Meta style={styles.label}>Password</Meta>
              <TextInput
                autoCapitalize="none"
                autoCorrect={false}
                secureTextEntry
                accessibilityLabel="Password"
                autoComplete="current-password"
                textContentType="password"
                returnKeyType="go"
                onSubmitEditing={() => void handleSubmit()}
                value={appPassword}
                onChangeText={setAppPassword}
                placeholder="Your website password"
                placeholderTextColor={colors.gray400}
                style={styles.input}
              />
            </View>

            {localError ? <ErrorPanel title="Check your input" message={localError} /> : null}

            {lastError ? <ErrorPanel title={lastError.title} message={lastError.message} /> : null}

            <Pressable
              style={[
                styles.primary,
                { paddingVertical: primaryButtonPaddingVertical },
                submitting && styles.disabled,
              ]}
              onPress={handleSubmit}
              disabled={submitting}
              accessibilityRole="button"
              accessibilityLabel="Sign in"
              accessibilityState={{ disabled: submitting, busy: submitting }}
            >
              <SectionTitle style={styles.primaryText}>
                {submitting ? "Signing in…" : "Sign in"}
              </SectionTitle>
            </Pressable>
          </Surface>

          <View style={styles.signupBlock}>
            <Meta style={styles.signupLabel}>Create account as</Meta>
            <View style={styles.demoRow}>
              <Pressable
                style={[styles.roleChip, signupRole === "vendor" && styles.roleChipActive]}
                onPress={() => setSignupRole("vendor")}
              >
                <Meta style={[styles.roleChipText, signupRole === "vendor" && styles.roleChipTextActive]}>
                  Vendor
                </Meta>
              </Pressable>
              <Pressable
                style={[styles.roleChip, signupRole === "organizer" && styles.roleChipActive]}
                onPress={() => setSignupRole("organizer")}
              >
                <Meta
                  style={[
                    styles.roleChipText,
                    signupRole === "organizer" && styles.roleChipTextActive,
                  ]}
                >
                  Organizer
                </Meta>
              </Pressable>
            </View>
            <Pressable style={styles.secondaryOutline} onPress={handleCreateAccount}>
              <Meta style={styles.secondaryOutlineText}>Create free account</Meta>
            </Pressable>
          </View>

          <LegalLinks />

          {!isLiveApiEnabled() ? (
            <Surface variant="hero" style={styles.demoBlock}>
              <Meta style={styles.demoHint}>Demo mode — fixtures only</Meta>
              <View style={styles.demoRow}>
                <Pressable style={styles.demoBtn} onPress={() => void handleDemo("vendor")}>
                  <Meta style={styles.demoBtnText}>Mock as vendor</Meta>
                </Pressable>
                <Pressable style={styles.demoBtn} onPress={() => void handleDemo("organizer")}>
                  <Meta style={styles.demoBtnText}>Mock as organizer</Meta>
                </Pressable>
              </View>
            </Surface>
          ) : null}

          <Meta style={styles.hint}>
            Use the same password you use on thelnkup.com.
            {__DEV__ ? ` Live API: ${isLiveApiEnabled() ? "on" : "off (fixtures)"}` : ""}
          </Meta>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  flex: {
    flex: 1,
  },
  container: {
    flex: 1,
    padding: spacing.xl,
    justifyContent: "center",
    gap: spacing.md,
  },
  logo: {
    width: 120,
    height: 40,
    resizeMode: "contain",
    alignSelf: "center",
    marginBottom: spacing.sm,
  },
  title: {
    fontSize: 32,
    textAlign: "center",
  },
  subtitle: {
    textAlign: "center",
    marginBottom: spacing.sm,
  },
  formCard: {
    gap: spacing.md,
  },
  field: {
    gap: spacing.xs,
  },
  label: {
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: 16,
    fontFamily: fonts.body,
    color: colors.black,
    backgroundColor: colors.gray50,
  },
  primary: {
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    marginTop: spacing.sm,
  },
  primaryText: {
    color: colors.white,
    fontSize: 16,
  },
  signupBlock: {
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  signupLabel: {
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  demoRow: {
    gap: spacing.sm,
    flexDirection: "row",
    flexWrap: "wrap",
  },
  roleChip: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.gray50,
  },
  roleChipActive: {
    borderColor: colors.black,
    backgroundColor: colors.lime,
  },
  roleChipText: {
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  roleChipTextActive: {
    color: colors.black,
  },
  secondaryOutline: {
    borderWidth: 2,
    borderColor: colors.lime,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  secondaryOutlineText: {
    fontFamily: fonts.bodySemi,
    color: colors.black,
    fontSize: 15,
  },
  demoBlock: {
    gap: spacing.sm,
  },
  demoHint: {
    textAlign: "center",
  },
  demoBtn: {
    flex: 1,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.sm,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  demoBtnText: {
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  disabled: {
    opacity: 0.6,
  },
  hint: {
    textAlign: "center",
    marginTop: spacing.sm,
  },
})
