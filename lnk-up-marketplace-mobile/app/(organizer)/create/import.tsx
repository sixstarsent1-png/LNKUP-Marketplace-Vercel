import { useEffect, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, useLocalSearchParams } from "expo-router"

import { LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { Body, DisplayTitle, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import {
  createImportDraft,
  IMPORT_FIELD_LABELS,
  importBlocked,
  keepableFields,
  previewImport,
  providerLabel,
  type ImportField,
  type ImportPreview,
} from "@/lib/api/event-import"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

const ORIGIN: Record<string, string> = { structured: "From listing", page_meta: "From page preview", account: "From your account", file: "From file" }

/** Import an event from its public listing; WordPress reads the page, the app reviews it. */
export default function CreateImportScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ url?: string }>()
  const [url, setUrl] = useState(typeof params.url === "string" ? params.url : "")
  const [reading, setReading] = useState(false)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [keep, setKeep] = useState<Record<string, boolean>>({})
  const [occurrence, setOccurrence] = useState(-1)
  const autoRan = useRef(false)

  async function read(input?: string) {
    const value = (input ?? url).trim()
    if (!providerLabel(value)) {
      setError("Paste a full link that starts with https://")
      return
    }
    setReading(true)
    setError(null)
    setPreview(null)
    try {
      const pv = await previewImport(auth, value)
      setPreview(pv)
      setKeep(Object.fromEntries(keepableFields(pv).map((f) => [f, true])))
      setOccurrence(pv.occurrences.length > 1 ? 0 : -1)
    } catch (err) {
      setError(appErrorFromWp(err).message)
    } finally {
      setReading(false)
    }
  }

  // A shared or deep-linked URL (?url=) is read once, as soon as the param is available.
  useEffect(() => {
    if (autoRan.current || typeof params.url !== "string" || !params.url) return
    autoRan.current = true
    setUrl(params.url)
    void read(params.url)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.url])

  async function continueToBuilder() {
    if (!preview || creating) return
    setCreating(true)
    try {
      const fields = (Object.keys(keep) as ImportField[]).filter((k) => keep[k])
      const payload = await createImportDraft(auth, { url: preview.url, keep: preview.fields.image_url ? [...fields, "image_url"] : fields, useImage: Boolean(preview.fields.image_url), occurrence })
      ;(payload.warnings ?? []).slice(0, 1).forEach((w) => showToast(w, "info"))
      router.replace(`/(organizer)/create/${payload.draft.id}` as never)
    } catch (err) {
      setError(appErrorFromWp(err).message)
    } finally {
      setCreating(false)
    }
  }

  const label = providerLabel(url)
  const blocked = preview ? importBlocked(preview) : false
  const found = preview ? keepableFields(preview) : []

  return (
    <Screen
      title="Import"
      hideTitle
      header={<BackLink label="Create" fallbackHref={"/(organizer)/create" as never} />}
      footer={
        preview ? (
          <StickyActionBar insideTabs>
            <Button label="Try another" variant="secondary" onPress={() => { setPreview(null); setUrl("") }} />
            <Button label={found.length ? "Continue" : "Continue with link"} style={styles.flex} disabled={blocked} loading={creating} loadingLabel="Creating…" onPress={() => void continueToBuilder()} />
          </StickyActionBar>
        ) : null
      }
    >
      <View style={styles.hero}>
        <Eyebrow>Import</Eyebrow>
        <DisplayTitle accessibilityRole="header">Bring in an event you already list</DisplayTitle>
        <Body>We read only what the listing shares publicly. Attendee tickets stay on your platform.</Body>
      </View>

      <FormField
        label="Event link"
        value={url}
        onChangeText={setUrl}
        placeholder="https://posh.vip/e/your-event"
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        returnKeyType="go"
        onSubmitEditing={() => void read(url)}
        hint={url.trim() ? (label ? `Detected: ${label}` : "That doesn't look like a full link yet.") : "POSH, Eventbrite, Eventeny, TicketLeap, Luma and most event websites."}
        testID="import-url"
      />
      {!preview ? <Button label="Read link" icon="download-outline" loading={reading} loadingLabel="Reading…" onPress={() => void read(url)} testID="import-read" /> : null}
      {reading ? <LoadingPanel label="Reading public event details…" variant="skeleton" count={2} /> : null}
      {error ? <InlineBanner tone="error" message={error} onDismiss={() => setError(null)} /> : null}

      {preview ? (
        <View style={styles.review} testID="import-review">
          <Surface style={styles.source}>
            <Text style={styles.sourceLabel}>{preview.provider.label}</Text>
            <Meta style={styles.sourceUrl} numberOfLines={1}>{preview.url.replace(/^https?:\/\//, "")}</Meta>
            <Meta style={styles.sourceUrl}>{preview.message}</Meta>
          </Surface>

          {preview.relation.state === "other" || preview.relation.state === "claimed_other" ? (
            <InlineBanner tone="error" title="Already managed on LNKup" message={preview.relation.message} />
          ) : preview.relation.state === "own" || preview.relation.state === "own_listing" ? (
            <InlineBanner
              tone="info"
              title={`Already on LNKup: ${preview.relation.event_title ?? "your event"}`}
              message={preview.relation.message}
              actionLabel="Open event"
              onAction={() => router.replace(`/(organizer)/events/${preview.relation.event_id}` as never)}
            />
          ) : preview.relation.state === "unclaimed_listing" ? (
            <InlineBanner tone="info" message={preview.relation.message} />
          ) : null}
          {preview.duplicates.length ? (
            <InlineBanner tone="attention" title="Looks like an event you already have" message={preview.duplicates.map((d) => `${d.title}${d.city ? ` · ${d.city}` : ""}`).join(" · ")} />
          ) : null}
          {preview.warnings.map((w) => (
            <InlineBanner key={w} tone="attention" message={w} />
          ))}

          {found.length ? (
            <View style={styles.fields}>
              <SectionTitle>Review imported details</SectionTitle>
              <Meta>Untick anything you don't want. You can edit every field next.</Meta>
              {IMPORT_FIELD_LABELS.filter(([k]) => preview.fields[k]).map(([k, name]) => (
                <Pressable
                  key={k}
                  onPress={() => setKeep((prev) => ({ ...prev, [k]: !prev[k] }))}
                  style={[styles.pick, keep[k] && styles.pickOn]}
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: Boolean(keep[k]) }}
                  accessibilityLabel={`${name}: ${preview.fields[k]}`}
                >
                  <AppIcon name={keep[k] ? "checkbox" : "square-outline"} size={22} color={colors.black} />
                  <View style={styles.flex}>
                    <Text style={styles.pickLabel}>{name} · {ORIGIN[preview.provenance[k] ?? "structured"] ?? "From listing"}</Text>
                    <Text style={styles.pickValue} numberOfLines={k === "description" ? 4 : 2}>
                      {k === "date" || k === "end_date" ? formatShortDate(preview.fields[k] ?? "") : preview.fields[k]}
                    </Text>
                  </View>
                </Pressable>
              ))}
            </View>
          ) : null}

          {preview.occurrences.length > 1 ? (
            <View style={styles.fields}>
              <SectionTitle>{preview.occurrences.length} dates on this listing</SectionTitle>
              <Meta>Pick the date to import now. Each date is its own LNKup event.</Meta>
              <View style={styles.chips}>
                {preview.occurrences.map((o, i) => (
                  <FilterChip key={`${o.date}-${i}`} label={`${formatShortDate(o.date)}${o.city ? ` · ${o.city}` : ""}`} selected={occurrence === i} onPress={() => setOccurrence(i)} />
                ))}
              </View>
            </View>
          ) : null}

          {preview.missing.length && preview.status !== "link_only" && preview.status !== "unreachable" ? (
            <Meta>You'll add: {preview.missing.map((m) => m.label).join(", ")}</Meta>
          ) : null}
          <Meta>Ticket prices and attendee capacity are not imported — vendor booth pricing is set separately in LNKup.</Meta>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  hero: { gap: spacing.xs },
  flex: { flex: 1 },
  review: { gap: spacing.md },
  source: { gap: 2, backgroundColor: colors.black, borderColor: colors.black },
  sourceLabel: { fontFamily: fonts.bodyBold, fontSize: typeScale.bodyLarge, color: colors.white },
  sourceUrl: { color: colors.gray200 },
  fields: { gap: spacing.sm },
  pick: { flexDirection: "row", gap: spacing.sm, alignItems: "flex-start", padding: spacing.md, borderRadius: radii.md, borderWidth: 1, borderColor: colors.gray200, backgroundColor: colors.white },
  pickOn: { borderColor: colors.black, backgroundColor: colors.limeMuted },
  pickLabel: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.gray500 },
  pickValue: { fontFamily: fonts.bodySemi, fontSize: typeScale.body, color: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
})
