import { useCallback, useEffect, useState } from "react"
import { ActivityIndicator, Platform, Pressable, StyleSheet, Switch, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import {
  DEFAULT_PUSH_CATEGORIES,
  PUSH_CATEGORY_LABELS,
  fetchPushPreferences,
  updatePushPreferences,
  type PushCategories,
  type PushCategoryKey,
} from "@/lib/api/push"
import { useAuth } from "@/lib/auth/auth-context"
import { clearPushRegistration, syncPushRegistration } from "@/lib/push/notifications"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const CATEGORY_KEYS = Object.keys(DEFAULT_PUSH_CATEGORIES) as PushCategoryKey[]

export function PushPreferencesCard() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [enabled, setEnabled] = useState(true)
  const [categories, setCategories] = useState<PushCategories>({ ...DEFAULT_PUSH_CATEGORIES })
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const prefs = await fetchPushPreferences(auth)
      setEnabled(prefs.pushEnabled)
      setCategories(prefs.categories)
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
      <Text style={styles.body}>
        Choose which alerts to receive on this device. Requires a physical device and an EAS project
        ID for delivery.
      </Text>

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
})
