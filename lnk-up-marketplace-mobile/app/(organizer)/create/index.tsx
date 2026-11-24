import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { InlineBanner } from "@/components/ui/banner"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Body, DisplayTitle, Eyebrow, Meta } from "@/components/ui/typography"
import {
  createBuilderDraft,
  fetchBuilderConfig,
  fetchBuilderLibrary,
  fetchRecentEvents,
  listBuilderDrafts,
  type BuilderConfig,
  type BuilderDraftSummary,
  type BuilderLibrary,
  type BuilderMethod,
  type BuilderRecentEvent,
} from "@/lib/api/event-builder"
import { appErrorFromWp } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { spacing } from "@/lib/theme"

type Sheet = "template" | "duplicate" | null

/** Create: choose how to start. Every path ends in a server draft edited by the native builder. */
export default function CreateChooserScreen() {
  const { auth } = useAuth()
  const [config, setConfig] = useState<BuilderConfig | null | undefined>(undefined)
  const [drafts, setDrafts] = useState<BuilderDraftSummary[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [sheet, setSheet] = useState<Sheet>(null)
  const [library, setLibrary] = useState<BuilderLibrary | null>(null)
  const [recent, setRecent] = useState<BuilderRecentEvent[] | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const cfg = await fetchBuilderConfig(auth)
      setConfig(cfg)
      if (cfg) setDrafts(await listBuilderDrafts(auth))
    } catch (err) {
      setConfig(null)
      setError(appErrorFromWp(err).message)
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  async function start(body: { method: BuilderMethod; quick?: boolean; template_id?: string; duplicate_event?: number }) {
    if (busy) return
    setBusy(true)
    setSheet(null)
    try {
      const payload = await createBuilderDraft(auth, body)
      router.push(`/(organizer)/create/${payload.draft.id}` as never)
    } catch (err) {
      setError(appErrorFromWp(err).message)
    } finally {
      setBusy(false)
    }
  }

  async function openSheet(next: Exclude<Sheet, null>) {
    setSheet(next)
    try {
      if (next === "template" && !library) setLibrary(await fetchBuilderLibrary(auth))
      if (next === "duplicate" && !recent) setRecent(await fetchRecentEvents(auth))
    } catch (err) {
      setSheet(null)
      setError(appErrorFromWp(err).message)
    }
  }

  const header = <BackLink label="Events" fallbackHref="/(organizer)/events" />

  if (config === undefined) {
    return (
      <Screen title="Create" hideTitle header={header}>
        <LoadingPanel label="Loading…" variant="skeleton" count={3} />
      </Screen>
    )
  }

  if (config === null) {
    return (
      <Screen title="Create" header={header}>
        {error ? <ErrorPanel title="Couldn't load" message={error} onAction={() => void load()} /> : null}
        <EmptyPanel
          title="Create an event"
          message="The new event builder isn't turned on for your account yet. You can still create events with the classic flow."
          actionLabel="Create event"
          onAction={() => router.replace({ pathname: "/(organizer)/launch", params: { classic: "1" } } as never)}
        />
      </Screen>
    )
  }

  const flags = config.flags ?? {}

  return (
    <Screen title="Create" hideTitle header={header} testID="create-chooser">
      <View style={styles.hero}>
        <Eyebrow>Create</Eyebrow>
        <DisplayTitle accessibilityRole="header">How would you like to start?</DisplayTitle>
        <Body>Keep your ticketing platform — manage your vendors with LNKup.</Body>
      </View>
      {error ? <InlineBanner tone="error" message={error} onDismiss={() => setError(null)} /> : null}

      <ListGroup>
        {flags.event_import_v3 ? (
          <ListRow icon="download-outline" iconTone="accent" title="Import an existing event" meta="Paste a POSH, Eventbrite, Eventeny or website link" onPress={() => router.push("/(organizer)/create/import" as never)} />
        ) : null}
        <ListRow icon="flash-outline" title="Quick create" meta="Name, date, place and one vendor option" onPress={() => void start({ method: "single", quick: true })} />
        <ListRow icon="calendar-outline" title="Create a single event" meta="Full setup with several vendor options" onPress={() => void start({ method: "single" })} />
      </ListGroup>

      {flags.portfolio_builder_v3 ? (
        <ListGroup title="More than one date">
          <ListRow icon="repeat-outline" title="Recurring event" meta="Weekly markets, monthly pop-ups" onPress={() => void start({ method: "recurring" })} />
          <ListRow icon="map-outline" title="Multi-city tour" meta="One setup, many cities" onPress={() => void start({ method: "tour" })} />
          <ListRow icon="albums-outline" title="Event series" meta="Related events, their own dates and venues" onPress={() => void start({ method: "series" })} />
        </ListGroup>
      ) : null}

      <ListGroup title="Reuse what you have">
        <ListRow icon="copy-outline" title="Duplicate an event" meta="Bookings and payments are never copied" onPress={() => void openSheet("duplicate")} />
        <ListRow icon="grid-outline" title="Use a template" meta="Your templates or a starter structure" onPress={() => void openSheet("template")} />
      </ListGroup>

      <ListGroup title="Continue a draft">
        {drafts.length === 0 ? (
          <ListRow icon="document-outline" title="No drafts yet" meta="Anything you start is saved automatically" showChevron={false} />
        ) : (
          drafts.slice(0, 8).map((d) => (
            <ListRow
              key={d.id}
              icon="document-text-outline"
              title={d.title || "Untitled draft"}
              meta={`${d.progress}% ready${d.missing.length ? ` · Needs ${d.missing.slice(0, 2).join(", ").toLowerCase()}` : ""}`}
              onPress={() => router.push(`/(organizer)/create/${d.id}` as never)}
            />
          ))
        )}
      </ListGroup>
      {busy ? <Meta>Setting up your draft…</Meta> : null}

      <BottomSheet visible={sheet === "template"} onClose={() => setSheet(null)} title="Start from a template">
        {!library ? (
          <LoadingPanel label="Loading templates…" variant="skeleton" count={2} />
        ) : (
          <View style={styles.sheet}>
            {library.templates.length ? (
              <ListGroup title="Your templates">
                {library.templates.map((t) => (
                  <ListRow key={t.id} icon="grid-outline" title={t.name} meta={`v${t.version} · ${t.booths.map((b) => b.label).join(", ") || "No booth types"}`} onPress={() => void start({ method: "template", template_id: String(t.id) })} />
                ))}
              </ListGroup>
            ) : null}
            <ListGroup title="Starter structures">
              {library.starters.map((s) => (
                <ListRow key={s.key} icon="sparkles-outline" title={s.name} meta={s.categories.join(" · ")} onPress={() => void start({ method: "template", template_id: `starter:${s.key}` })} />
              ))}
            </ListGroup>
            <Meta>Starters add vendor categories and common questions. You set prices and spaces.</Meta>
          </View>
        )}
      </BottomSheet>

      <BottomSheet visible={sheet === "duplicate"} onClose={() => setSheet(null)} title="Duplicate an event">
        {!recent ? (
          <LoadingPanel label="Loading events…" variant="skeleton" count={2} />
        ) : recent.length === 0 ? (
          <Meta>No events to copy yet.</Meta>
        ) : (
          <View style={styles.sheet}>
            <ListGroup>
              {recent.map((e) => (
                <ListRow key={e.id} icon="copy-outline" title={e.title} meta={[formatShortDate(e.date), e.city].filter(Boolean).join(" · ")} onPress={() => void start({ method: "duplicate", duplicate_event: e.id })} />
              ))}
            </ListGroup>
            <Meta>Applications, bookings, payments and check-ins are never copied. You'll confirm the new date and place.</Meta>
          </View>
        )}
      </BottomSheet>
    </Screen>
  )
}

const styles = StyleSheet.create({
  hero: { gap: spacing.xs },
  sheet: { gap: spacing.md },
})
