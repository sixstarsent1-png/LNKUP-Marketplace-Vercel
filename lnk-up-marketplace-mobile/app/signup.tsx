import { useEffect, useState } from "react"
import {
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { Redirect, router, useLocalSearchParams } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { LegalLinks } from "@/components/legal-links"
import { ErrorPanel } from "@/components/panel-states"
import { Surface } from "@/components/ui/surface"
import { Body, DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import type { HomeHref } from "@/lib/auth/roles"
import { constrainedColumnStyle, useBreakpoint } from "@/lib/layout/use-breakpoint"
import { colors, fonts, layout, radii, spacing } from "@/lib/theme"

export default function SignupScreen() {
  const params = useLocalSearchParams<{ role?: string }>()
  const {
    isAuthenticated,
    isLoading,
    register,
    resolveHome,
    lastError,
    clearError,
  } = useAuth()
  const { primaryButtonPaddingVertical } = useBreakpoint()
  const [role, setRole] = useState<"vendor" | "organizer">(
    params.role === "organizer" ? "organizer" : "vendor",
  )
  const [fullName, setFullName] = useState("")
  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [passwordConfirm, setPasswordConfirm] = useState("")
  const [acceptedTerms, setAcceptedTerms] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [localError, setLocalError] = useState<string | null>(null)
  const [homeHref, setHomeHref] = useState<HomeHref | null>(null)

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

  async function handleSubmit() {
    clearError()
    setLocalError(null)

    if (!email.trim() || !password || !passwordConfirm) {
      setLocalError("Enter your email and password.")
      return
    }
    if (password.length < 8) {
      setLocalError("Password must be at least 8 characters.")
      return
    }
    if (password !== passwordConfirm) {
      setLocalError("Passwords do not match.")
      return
    }
    if (!acceptedTerms) {
      setLocalError("Please accept the Terms and Privacy Policy to continue.")
      return
    }
    if (!isLiveApiEnabled()) {
      setLocalError("Live API is off. Turn it on to create an account.")
      return
    }

    setSubmitting(true)
    try {
      await register({
        email,
        password,
        passwordConfirm,
        role,
        fullName,
        terms: acceptedTerms,
      })
      router.replace(role === "organizer" ? "/(organizer)" : "/(vendor)")
    } catch {
      // lastError set by AuthProvider
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === "ios" ? "padding" : undefined}
      >
        <ScrollView
          contentContainerStyle={[styles.container, constrainedColumnStyle(layout.formMaxWidth)]}
          keyboardShouldPersistTaps="handled"
        >
          <Image
            source={require("@/assets/brand/lnkup-logo.png")}
            style={styles.logo}
            accessibilityIgnoresInvertColors
            accessibilityLabel="LNKup"
          />
          <DisplayTitle style={styles.title}>Create account</DisplayTitle>
          <Body style={styles.subtitle}>
            Create a vendor or organizer account in the app. You will stay signed in after you join.
          </Body>

          <Surface elevated style={styles.formCard}>
            <Meta style={styles.label}>I am a</Meta>
            <View style={styles.demoRow}>
              <Pressable
                style={[styles.roleChip, role === "vendor" && styles.roleChipActive]}
                onPress={() => setRole("vendor")}
              >
                <Meta style={[styles.roleChipText, role === "vendor" && styles.roleChipTextActive]}>
                  Vendor
                </Meta>
              </Pressable>
              <Pressable
                style={[styles.roleChip, role === "organizer" && styles.roleChipActive]}
                onPress={() => setRole("organizer")}
              >
                <Meta
                  style={[styles.roleChipText, role === "organizer" && styles.roleChipTextActive]}
                >
                  Organizer
                </Meta>
              </Pressable>
            </View>

            <View style={styles.field}>
              <Meta style={styles.label}>Full name</Meta>
              <TextInput
                autoCapitalize="words"
                value={fullName}
                onChangeText={setFullName}
                placeholder="Your name"
                placeholderTextColor={colors.gray400}
                style={styles.input}
              />
            </View>

            <View style={styles.field}>
              <Meta style={styles.label}>Email</Meta>
              <TextInput
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                textContentType="emailAddress"
                value={email}
                onChangeText={setEmail}
                placeholder="you@email.com"
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
                textContentType="newPassword"
                value={password}
                onChangeText={setPassword}
                placeholder="At least 8 characters"
                placeholderTextColor={colors.gray400}
                style={styles.input}
              />
            </View>

            <View style={styles.field}>
              <Meta style={styles.label}>Confirm password</Meta>
              <TextInput
                autoCapitalize="none"
                autoCorrect={false}
                secureTextEntry
                textContentType="newPassword"
                value={passwordConfirm}
                onChangeText={setPasswordConfirm}
                placeholder="Repeat password"
                placeholderTextColor={colors.gray400}
                style={styles.input}
              />
            </View>

            <Pressable
              style={styles.checkRow}
              onPress={() => setAcceptedTerms((current) => !current)}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: acceptedTerms }}
            >
              <View style={[styles.checkbox, acceptedTerms && styles.checkboxOn]} />
              <Meta style={styles.checkLabel}>
                I accept the Terms of Service and Privacy Policy.
              </Meta>
            </Pressable>

            <LegalLinks prefix="Read" />

            {localError ? <ErrorPanel title="Check your input" message={localError} /> : null}
            {lastError ? <ErrorPanel title={lastError.title} message={lastError.message} /> : null}

            <Pressable
              style={[
                styles.primary,
                { paddingVertical: primaryButtonPaddingVertical },
                submitting && styles.disabled,
              ]}
              onPress={() => void handleSubmit()}
              disabled={submitting}
            >
              <SectionTitle style={styles.primaryText}>
                {submitting ? "Creating account…" : "Create free account"}
              </SectionTitle>
            </Pressable>
          </Surface>

          <Pressable onPress={() => router.replace("/login")} accessibilityRole="link">
            <Meta style={styles.signIn}>Already have an account? Sign in</Meta>
          </Pressable>
        </ScrollView>
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
    padding: spacing.xl,
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
  checkRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: spacing.sm,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    marginTop: 2,
  },
  checkboxOn: {
    backgroundColor: colors.lime,
    borderColor: colors.black,
  },
  checkLabel: {
    flex: 1,
    color: colors.gray700,
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
  disabled: {
    opacity: 0.6,
  },
  signIn: {
    textAlign: "center",
    fontFamily: fonts.bodySemi,
    textDecorationLine: "underline",
    marginBottom: spacing.xl,
  },
})
