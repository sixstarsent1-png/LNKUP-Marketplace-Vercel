import { useCallback, useEffect, useState } from "react"
import { ActivityIndicator, Linking, Platform, Pressable, StyleSheet, Switch, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router } from "expo-router"

import {
  DEFAULT_PUSH_CATEGORIES,
  PUSH_CATEGORY_LABELS,
  fetchPushPreferences,
  updatePushPreferences,
  type PushCategories,
  type PushCategoryKey,
} from "@/lib/api/push"
import { useAuth } from "@/lib/auth/auth-context"
import {
  clearPushRegistration,
  getPushPermissionState,
  loadStoredPushToken,
  syncPushRegistration,
  type PushPermissionState,
} from "@/lib/push/notifications"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const CATEGORY_KEYS = Object.keys(DEFAULT_PUSH_CATEGORIES) as PushCategoryKey[]

export function PushPreferencesCard({ settingsHref }: { settingsHref?: string } = {}) {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [enabled, setEnabled] = useState(true)
  const [categories, setCategories] = useState<PushCategories>({ ...DEFAULT_PUSH_CATEGORIES })
  const [error, setError] = useState<string | null>(null)
  const [permission, setPermission] = useState<PushPermissionState>("undetermined")
  const [registered, setRegistered] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [prefs, state, token] = await Promise.all([fetchPushPreferences(auth), getPushPermissionState(), loadStoredPushToken()])
      setEnabled(prefs.pushEnabled)
      setCategories(prefs.categories)
      setPermission(state)
      setRegistered(Boolean(token))
    } catch {
      setError("Could not load notification preferences.")
    } finally {
      setLoading(false)
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  async function handleMasterToggle(next: boolean) {
    setSaving(true)
    setError(null)
    try {
      const prefs = await updatePushPreferences({ pushEnabled: next }, auth)
      setEnabled(prefs.pushEnabled)
      setCategories(prefs.categories)
      if (prefs.pushEnabled) {
        await syncPushRegistration(auth)
      } else {
        await clearPushRegistration(auth)
      }
    } catch {
      setError("Could not update push preferences.")
    } finally {
      setSaving(false)
    }
  }

  async function handleCategoryToggle(key: PushCategoryKey, next: boolean) {
    setSaving(true)
    setError(null)
    try {
      const prefs = await updatePushPreferences({ categories: { [key]: next } }, auth)
      setEnabled(prefs.pushEnabled)
      setCategories(prefs.categories)
    } catch {
      setError("Could not update category preferences.")
    } finally {
      setSaving(false)
    }
  }

  if (Platform.OS === "web") {
    return null
  }

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Push notifications</Text>
      <Text style={styles.body}>Choose which alerts you get on this device.</Text>
      <Text style={styles.status} testID="push-device-status">
        {permission === "granted"
          ? registered
            ? "Notifications are on for this device."
            : "Allowed — this device will finish connecting the next time you open LNKup."
          : permission === "denied"
            ? "Notifications are turned off for LNKup in your phone’s settings."
            : permission === "unavailable"
              ? "Push notifications aren’t available on this device."
              : "You haven’t allowed notifications on this device yet."}
      </Text>
      {permission === "denied" ? (
        <Pressable onPress={() => void Linking.openSettings()} accessibilityRole="button" style={styles.link}>
          <Text style={styles.linkText}>Open phone settings →</Text>
        </Pressable>
      ) : null}

      {loading ? (
        <ActivityIndicator color={colors.black} style={{ marginTop: spacing.sm }} />
      ) : (
        <>
          <View style={styles.row}>
            <Text style={styles.label}>Enable push</Text>
            <Switch
              value={enabled}
              onValueChange={(value) => void handleMasterToggle(value)}
              disabled={saving}
              trackColor={{ false: colors.gray200, true: colors.lime }}
              thumbColor={colors.white}
            />
          </View>

          <Text style={styles.sectionLabel}>Categories</Text>
          {CATEGORY_KEYS.map((key) => (
            <View key={key} style={styles.row}>
              <Text style={[styles.categoryLabel, !enabled && styles.dimmed]}>
                {PUSH_CATEGORY_LABELS[key]}
              </Text>
              <Switch
                value={categories[key]}
                onValueChange={(value) => void handleCategoryToggle(key, value)}
                disabled={saving || !enabled}
                trackColor={{ false: colors.gray200, true: colors.lime }}
                thumbColor={colors.white}
              />
            </View>
          ))}
        </>
      )}

      {error ? (
        <Pressable onPress={() => void load()}>
          <Text style={styles.error}>{error} Tap to retry.</Text>
        </Pressable>
      ) : null}

      {settingsHref ? (
        <Pressable onPress={() => router.push(settingsHref as never)} accessibilityRole="link" style={styles.link}>
          <Text style={styles.linkText}>All notification settings — email, digests, quiet hours →</Text>
        </Pressable>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  title: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "700",
    color: colors.black,
  },
  body: {
    fontSize: typeScale.footnote,
    lineHeight: 18,
    color: colors.gray500,
  },
  status: {
    fontSize: typeScale.footnote,
    lineHeight: 18,
    fontWeight: "600",
    color: colors.gray700,
  },
  sectionLabel: {
    marginTop: spacing.sm,
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  row: {
    marginTop: spacing.xs,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
  },
  label: {
    fontSize: typeScale.callout,
    fontWeight: "600",
    color: colors.black,
  },
  categoryLabel: {
    flex: 1,
    fontSize: typeScale.body,
    fontWeight: "500",
    color: colors.black,
  },
  dimmed: {
    color: colors.gray500,
  },
  error: {
    fontSize: typeScale.footnote,
    color: colors.gray500,
    marginTop: spacing.xs,
  },
  link: {
    marginTop: spacing.sm,
    minHeight: 44,
    justifyContent: "center",
  },
  linkText: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
})
