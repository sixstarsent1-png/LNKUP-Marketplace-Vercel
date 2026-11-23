import { useCallback, useEffect, useState } from "react"
import { Platform, StyleSheet, Switch, View } from "react-native"
import type { Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { FilterChipRow } from "@/components/ui/chip"
import {
  fetchCommsPreferences,
  updateCommsPreferences,
  type CommsPreferences,
  type EmailMode,
  type PreferenceCategory,
  type PreferencesUpdate,
} from "@/lib/api/comms"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { clearPushRegistration, syncPushRegistration } from "@/lib/push/notifications"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

type Props = { role: "vendor" | "organizer" | "partner" }

const INBOX_FOR_ROLE: Record<Props["role"], Href> = {
  vendor: "/(vendor)/notifications",
  organizer: "/(organizer)/notifications",
  partner: "/(partner)/notifications",
}

const EMAIL_OPTIONS: ReadonlyArray<{ value: EmailMode; label: string }> = [
  { value: "immediate", label: "Instant" },
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "off", label: "Off" },
]
const START_OPTIONS = ["20:00", "21:00", "22:00", "23:00"].map((v) => ({ value: v, label: v }))
const END_OPTIONS = ["06:00", "07:00", "08:00", "09:00"].map((v) => ({ value: v, label: v }))

function deviceTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || ""
  } catch {
    return ""
  }
}

export function NotificationSettingsScreen({ role }: Props) {
  const { auth } = useAuth()
  const [prefs, setPrefs] = useState<CommsPreferences | null>(null)
  const [unavailable, setUnavailable] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const next = await fetchCommsPreferences(auth)
      if (!next) {
        setUnavailable(true)
        return
      }
      setPrefs(next)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  async function save(update: PreferencesUpdate, optimistic: CommsPreferences) {
    const previous = prefs
    setPrefs(optimistic)
    setSaving(true)
    try {
      const tz = deviceTimezone()
      const saved = await updateCommsPreferences(tz && optimistic.timezone !== tz ? { ...update, timezone: tz } : update, auth)
      setPrefs(saved)
    } catch {
      setPrefs(previous)
      showToast("Couldn't save notification settings. Try again.", "error")
    } finally {
      setSaving(false)
    }
  }

  function updateCategory(cat: PreferenceCategory, change: { email?: EmailMode; push?: "on" | "off" }) {
    if (!prefs || cat.locked) return
    const categories = prefs.categories.map((c) => (c.key === cat.key ? { ...c, ...change } : c))
    void save({ categories: [{ key: cat.key, ...change }] }, { ...prefs, categories })
  }

  async function toggleMaster(next: boolean) {
    if (!prefs) return
    await save({ pushEnabled: next }, { ...prefs, pushEnabled: next })
    if (next) {
      const token = await syncPushRegistration(auth, { prompt: true })
      if (!token && Platform.OS !== "web") {
        showToast("Allow notifications for LNKup in your phone's Settings to receive push alerts.", "info")
      }
    } else {
      await clearPushRegistration(auth)
    }
  }

  function updateQuiet(change: Partial<CommsPreferences["quietHours"]>) {
    if (!prefs) return
    const quietHours = { ...prefs.quietHours, ...change }
    void save({ quietHours }, { ...prefs, quietHours })
  }

  const visible = prefs?.categories.filter((c) => c.roles.includes(role)) ?? []

  return (
    <Screen
      title="Notification settings"
      subtitle="Choose how LNKup reaches you"
      header={<BackLink label="Notifications" fallbackHref={INBOX_FOR_ROLE[role]} />}
    >
      {loading ? <LoadingPanel label="Loading your settings…" variant="skeleton" count={4} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}
      {!loading && unavailable ? <PushPreferencesCard /> : null}

      {!loading && prefs ? (
        <>
          {Platform.OS !== "web" ? (
            <View style={styles.card}>
              <View style={styles.row}>
                <View style={styles.flex}>
                  <Text style={styles.label}>Push notifications</Text>
                  <Text style={styles.hint}>Alerts on this phone. Your phone settings still decide whether they appear.</Text>
                </View>
                <Switch
                  value={prefs.pushEnabled}
                  onValueChange={(value) => void toggleMaster(value)}
                  disabled={saving}
                  trackColor={{ false: colors.gray200, true: colors.lime }}
                  thumbColor={colors.white}
                  accessibilityLabel="Push notifications"
                />
              </View>
            </View>
          ) : null}

          {visible.length === 0 ? <EmptyPanel title="No settings yet" message="There are no notification settings for your account." /> : null}

          {visible.map((cat) => (
            <View key={cat.key} style={styles.card}>
              <View style={styles.row}>
                <View style={styles.flex}>
                  <Text style={styles.label}>
                    {cat.label}
                    {cat.promotional ? <Text style={styles.badge}>  Marketing</Text> : null}
                  </Text>
                  <Text style={styles.hint}>{cat.description}</Text>
                </View>
                {cat.locked ? null : (
                  <Switch
                    value={cat.push === "on"}
                    onValueChange={(value) => updateCategory(cat, { push: value ? "on" : "off" })}
                    disabled={saving || !prefs.pushEnabled}
                    trackColor={{ false: colors.gray200, true: colors.lime }}
                    thumbColor={colors.white}
                    accessibilityLabel={`${cat.label} push`}
                  />
                )}
              </View>
              {cat.locked ? (
                <Text style={styles.locked}>Always on — these affect your account and bookings.</Text>
              ) : (
                <>
                  <Text style={styles.sectionLabel}>Email</Text>
                  <FilterChipRow options={EMAIL_OPTIONS} value={cat.email} onChange={(email) => updateCategory(cat, { email })} />
                </>
              )}
            </View>
          ))}

          <View style={styles.card}>
            <View style={styles.row}>
              <View style={styles.flex}>
                <Text style={styles.label}>Quiet hours</Text>
                <Text style={styles.hint}>Non-urgent push alerts wait until quiet hours end. Urgent event and payment alerts still arrive.</Text>
              </View>
              <Switch
                value={prefs.quietHours.enabled}
                onValueChange={(enabled) => updateQuiet({ enabled })}
                disabled={saving}
                trackColor={{ false: colors.gray200, true: colors.lime }}
                thumbColor={colors.white}
                accessibilityLabel="Quiet hours"
              />
            </View>
            {prefs.quietHours.enabled ? (
              <>
                <Text style={styles.sectionLabel}>From</Text>
                <FilterChipRow options={START_OPTIONS} value={prefs.quietHours.start} onChange={(start) => updateQuiet({ start })} />
                <Text style={styles.sectionLabel}>Until</Text>
                <FilterChipRow options={END_OPTIONS} value={prefs.quietHours.end} onChange={(end) => updateQuiet({ end })} />
                <Text style={styles.hint}>Time zone: {deviceTimezone() || prefs.timezone}</Text>
              </>
            ) : null}
          </View>
        </>
      ) : null}
    </Screen>
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
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  flex: {
    flex: 1,
    gap: 2,
  },
  label: {
    fontSize: typeScale.callout,
    fontWeight: "700",
    color: colors.black,
  },
  badge: {
    fontSize: typeScale.micro,
    fontWeight: "600",
    color: colors.gray500,
  },
  hint: {
    fontSize: typeScale.footnote,
    lineHeight: 18,
    color: colors.gray500,
  },
  sectionLabel: {
    marginTop: spacing.xs,
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  locked: {
    fontSize: typeScale.footnote,
    color: colors.gray500,
  },
})
