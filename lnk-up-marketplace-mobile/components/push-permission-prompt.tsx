import { useEffect, useState } from "react"
import { Platform, StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { useAuth } from "@/lib/auth/auth-context"
import { getPushPermissionState, syncPushRegistration } from "@/lib/push/notifications"
import {
  loadPromptRecord,
  recordPromptDismissed,
  shouldShowPushPrompt,
  type PromptRecord,
} from "@/lib/push/permission-prompt"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const COPY = {
  vendor: {
    title: "Know the moment you're approved",
    body: "Get alerts for application decisions, payment deadlines and event-day changes.",
  },
  organizer: {
    title: "Know when vendors apply and pay",
    body: "Get alerts for new applications, confirmed bookings and check-in activity.",
  },
} as const

/** Contextual push opt-in shown on Home instead of prompting at sign-in. */
export function PushPermissionPrompt({ role }: { role: "vendor" | "organizer" }) {
  const { auth } = useAuth()
  const [visible, setVisible] = useState(false)
  const [record, setRecord] = useState<PromptRecord | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (Platform.OS === "web") return
    let active = true
    void Promise.all([getPushPermissionState(), loadPromptRecord()]).then(([state, saved]) => {
      if (!active) return
      setRecord(saved)
      setVisible(shouldShowPushPrompt(state, saved, Date.now()))
    })
    return () => {
      active = false
    }
  }, [])

  if (!visible) return null

  async function handleEnable() {
    setBusy(true)
    await syncPushRegistration(auth, { prompt: true })
    setBusy(false)
    setVisible(false)
  }

  async function handleLater() {
    setVisible(false)
    await recordPromptDismissed(record, Date.now())
  }

  return (
    <View style={styles.card} accessibilityRole="summary">
      <Text style={styles.title}>{COPY[role].title}</Text>
      <Text style={styles.body}>{COPY[role].body}</Text>
      <View style={styles.actions}>
        <Button label="Turn on notifications" variant="accent" size="sm" loading={busy} onPress={() => void handleEnable()} />
        <Button label="Not now" variant="secondary" size="sm" onPress={() => void handleLater()} />
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  title: {
    fontSize: typeScale.callout,
    fontWeight: "700",
    color: colors.white,
  },
  body: {
    fontSize: typeScale.footnote,
    lineHeight: 18,
    color: colors.gray200,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.sm,
    flexWrap: "wrap",
  },
})
