import { useCallback, useEffect, useMemo, useState } from "react"
import { Alert, StyleSheet, Switch, View } from "react-native"
import { useLocalSearchParams } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { FilterChipRow } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import {
  fetchEventAudiences,
  fetchEventHistory,
  fetchEventTemplates,
  previewEventMessage,
  sendEventMessage,
  type EventGroup,
  type EventHistoryItem,
  type EventPreview,
  type EventTemplate,
} from "@/lib/api/comms"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

export default function OrganizerEventCommsScreen() {
  const { eventId, group: groupParam } = useLocalSearchParams<{ eventId: string; group?: string }>()
  const id = String(eventId ?? "")
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [eventName, setEventName] = useState("")
  const [groups, setGroups] = useState<EventGroup[]>([])
  const [templates, setTemplates] = useState<EventTemplate[]>([])
  const [history, setHistory] = useState<EventHistoryItem[]>([])
  const [group, setGroup] = useState("")
  const [template, setTemplate] = useState("announcement")
  const [title, setTitle] = useState("")
  const [body, setBody] = useState("")
  const [email, setEmail] = useState(true)
  const [push, setPush] = useState(true)
  const [preview, setPreview] = useState<EventPreview | null>(null)
  const [busy, setBusy] = useState<"preview" | "send" | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [aud, tpl, hist] = await Promise.all([fetchEventAudiences(id, auth), fetchEventTemplates(id, auth), fetchEventHistory(id, auth)])
      setEventName(aud.eventName)
      setGroups(aud.groups)
      setTemplates(tpl)
      setHistory(hist)
      const requested = typeof groupParam === "string" ? aud.groups.find((g) => g.key === groupParam && g.count > 0)?.key : undefined
      setGroup((current) => current || requested || aud.groups.find((g) => g.count > 0)?.key || "")
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id, groupParam])

  useEffect(() => {
    void load()
  }, [load])

  const selectedTemplate = useMemo(() => templates.find((t) => t.key === template), [template, templates])

  function chooseTemplate(key: string) {
    setTemplate(key)
    setPreview(null)
    const tpl = templates.find((t) => t.key === key)
    if (tpl?.title) setTitle(tpl.title)
    if (tpl?.body) setBody(tpl.body)
  }

  const input = () => ({
    group,
    template,
    title: title.trim(),
    body: body.trim(),
    channels: ["inbox", ...(email ? ["email"] : []), ...(push ? ["push"] : [])],
  })

  async function runPreview(): Promise<EventPreview | null> {
    setBusy("preview")
    try {
      const next = await previewEventMessage(id, input(), auth)
      setPreview(next)
      return next
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
      return null
    } finally {
      setBusy(null)
    }
  }

  async function handleSend() {
    if (!title.trim() || !body.trim()) {
      showToast("Add a title and a message.", "error")
      return
    }
    const p = preview ?? (await runPreview())
    if (!p) return
    if (!p.allowed) {
      showToast("This message type can't be sent to that group.", "error")
      return
    }
    if (p.rateLimit) {
      showToast(p.rateLimit, "error")
      return
    }
    Alert.alert("Send message?", `${p.recipients} vendor${p.recipients === 1 ? "" : "s"} will receive "${title.trim()}".`, [
      { text: "Cancel", style: "cancel" },
      {
        text: "Send",
        onPress: () => {
          void (async () => {
            setBusy("send")
            try {
              await sendEventMessage(id, input(), auth)
              showToast("Message queued for delivery.", "success")
              setPreview(null)
              setHistory(await fetchEventHistory(id, auth))
            } catch (err) {
              showToast(appErrorFromWp(err).message, "error")
            } finally {
              setBusy(null)
            }
          })()
        },
      },
    ])
  }

  const excluded = preview ? Object.values(preview.excluded ?? {}).reduce((sum, n) => sum + n, 0) : 0
  const noVendors = groups.every((g) => g.count === 0)

  return (
    <Screen
      title="Communications"
      subtitle={eventName || "Message your vendors"}
      header={<BackLink label="Event" fallbackHref={`/(organizer)/events/${id}`} />}
    >
      {loading ? <LoadingPanel label="Loading communications…" variant="skeleton" count={3} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {!loading && !error && noVendors ? (
        <EmptyPanel title="No vendors yet" message="Once vendors apply to this event you can message them here." />
      ) : null}

      {!loading && !error && !noVendors ? (
        <View style={styles.card}>
          <Text style={styles.label}>Recipients</Text>
          <FilterChipRow
            options={groups.filter((g) => g.count > 0).map((g) => ({ value: g.key, label: g.label, count: g.count }))}
            value={group}
            onChange={(next) => {
              setGroup(next)
              setPreview(null)
            }}
          />
          <Text style={styles.label}>Message type</Text>
          <FilterChipRow options={templates.map((t) => ({ value: t.key, label: t.label }))} value={template} onChange={chooseTemplate} />
          {selectedTemplate?.priority === "high" ? <Text style={styles.hint}>Sent as an important operational update.</Text> : null}
          <FormField label="Title" value={title} onChangeText={(v) => { setTitle(v); setPreview(null) }} maxLength={120} />
          <FormField label="Message" value={body} onChangeText={(v) => { setBody(v); setPreview(null) }} multiline maxLength={1000} hint={`${body.length}/1000`} />
          <View style={styles.row}>
            <Text style={styles.toggleLabel}>Also send by email</Text>
            <Switch value={email} onValueChange={(v) => { setEmail(v); setPreview(null) }} trackColor={{ false: colors.gray200, true: colors.lime }} thumbColor={colors.white} accessibilityLabel="Also send by email" />
          </View>
          <View style={styles.row}>
            <Text style={styles.toggleLabel}>Also send by push</Text>
            <Switch value={push} onValueChange={(v) => { setPush(v); setPreview(null) }} trackColor={{ false: colors.gray200, true: colors.lime }} thumbColor={colors.white} accessibilityLabel="Also send by push" />
          </View>

          {preview ? (
            <View style={styles.preview} accessibilityLiveRegion="polite">
              <Text style={styles.previewTitle}>
                {preview.recipients} recipient{preview.recipients === 1 ? "" : "s"} · {preview.email} by email · {preview.push} by push
              </Text>
              {excluded > 0 ? <Text style={styles.hint}>{excluded} opted out or unreachable on email/push (they still get it in their inbox).</Text> : null}
              {preview.sample.length > 0 ? <Text style={styles.hint}>{preview.sample.join(", ")}{preview.recipients > preview.sample.length ? "…" : ""}</Text> : null}
              {preview.rateLimit ? <Text style={styles.warning}>{preview.rateLimit}</Text> : null}
              {!preview.allowed ? <Text style={styles.warning}>This message type can't be sent to that group.</Text> : null}
            </View>
          ) : null}

          <View style={styles.actions}>
            <Button label="Preview" variant="secondary" loading={busy === "preview"} onPress={() => void runPreview()} disabled={!group} />
            <Button label="Send" variant="accent" loading={busy === "send"} onPress={() => void handleSend()} disabled={!group} />
          </View>
        </View>
      ) : null}

      {!loading && !error ? (
        <View style={styles.card}>
          <Text style={styles.label}>Sent messages</Text>
          {history.length === 0 ? <Text style={styles.hint}>No messages sent for this event yet.</Text> : null}
          {history.map((item) => (
            <View key={item.id} style={styles.historyRow}>
              <Text style={styles.historyTitle}>{item.title}</Text>
              <Text style={styles.hint}>
                {item.type} · {item.group} · {item.sent}/{item.recipients} sent · {new Date(item.createdAt).toLocaleString()}
              </Text>
              <Text style={styles.historyBody}>{item.body}</Text>
            </View>
          ))}
        </View>
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
  label: {
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.4,
    marginTop: spacing.xs,
  },
  hint: {
    fontSize: typeScale.footnote,
    color: colors.gray500,
    lineHeight: 18,
  },
  warning: {
    fontSize: typeScale.footnote,
    color: colors.red600,
    fontWeight: "600",
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    minHeight: 44,
  },
  toggleLabel: {
    fontSize: typeScale.body,
    color: colors.black,
  },
  preview: {
    backgroundColor: colors.gray50,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
  },
  previewTitle: {
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.black,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  historyRow: {
    borderTopWidth: 1,
    borderTopColor: colors.gray100,
    paddingTop: spacing.sm,
    gap: 2,
  },
  historyTitle: {
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.black,
  },
  historyBody: {
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
})
