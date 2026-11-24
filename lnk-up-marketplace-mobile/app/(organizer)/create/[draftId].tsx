import { useCallback, useEffect, useRef, useState } from "react"
import { Pressable, Share, StyleSheet, View } from "react-native"
import { router, useLocalSearchParams } from "expo-router"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { LocationField } from "@/components/location-field"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StepProgress } from "@/components/ui/step-progress"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { Body, DisplayTitle, Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import {
  blankStop,
  blockingChecks,
  BUILDER_STEPS,
  fetchBuilderConfig,
  fetchBuilderDates,
  fetchBuilderPreview,
  getBuilderDraft,
  isPortfolioMethod,
  mergePatch,
  nativeStepFor,
  newOptionKey,
  patchFor,
  publishBuilderDraft,
  saveBuilderDraft,
  type BuilderConfig,
  type BuilderDates,
  type BuilderPreview,
  type BuilderPublishResult,
  type BuilderReport,
  type BuilderState,
  type BuilderStep,
  type BuilderStop,
} from "@/lib/api/event-builder"
import { appErrorFromWp } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

const TIME = /^([01]\d|2[0-3]):[0-5]\d$/
const DEFAULT_CATEGORIES = ["Food Trucks", "Food", "Retail", "Clothing", "Beauty", "Art", "Drinks", "Services"]
const WEEKDAYS: Array<[string, string]> = [["mon", "Mon"], ["tue", "Tue"], ["wed", "Wed"], ["thu", "Thu"], ["fri", "Fri"], ["sat", "Sat"], ["sun", "Sun"]]
type SaveState = "saved" | "saving" | "dirty" | "error" | "conflict"

function setIn<T extends Record<string, unknown>>(obj: T, path: string, value: unknown): T {
  const parts = path.split(".")
  const copy: Record<string, unknown> = { ...obj }
  let node = copy
  parts.forEach((part, i) => {
    if (i === parts.length - 1) {
      node[part] = value
      return
    }
    const child = node[part]
    node[part] = Array.isArray(child) ? [...child] : { ...(child as Record<string, unknown>) }
    node = node[part] as Record<string, unknown>
  })
  return copy as T
}

/** Native event builder: one server draft, autosaved with revisions, published through WordPress. */
export default function CreateDraftScreen() {
  const { auth } = useAuth()
  const { draftId } = useLocalSearchParams<{ draftId: string }>()
  const id = Number(draftId)
  const [state, setState] = useState<BuilderState | null>(null)
  const [report, setReport] = useState<BuilderReport | null>(null)
  const [config, setConfig] = useState<BuilderConfig | null>(null)
  const [step, setStep] = useState<BuilderStep>("info")
  const [loadError, setLoadError] = useState<string | null>(null)
  const [save, setSave] = useState<SaveState>("saved")
  const [banner, setBanner] = useState<{ tone: "error" | "attention" | "success" | "info"; message: string } | null>(null)
  const [publishing, setPublishing] = useState(false)
  const [result, setResult] = useState<BuilderPublishResult | null>(null)
  const [preview, setPreview] = useState<BuilderPreview | null>(null)
  const [dates, setDates] = useState<BuilderDates | null>(null)
  const revision = useRef(0)
  const pending = useRef<Record<string, unknown>>({})
  const saving = useRef(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const sourceEvent = useRef(0)

  const load = useCallback(async () => {
    setLoadError(null)
    try {
      const [payload, cfg] = await Promise.all([getBuilderDraft(auth, id), fetchBuilderConfig(auth)])
      revision.current = payload.draft.revision
      sourceEvent.current = payload.draft.source_event
      pending.current = {}
      setState(payload.draft.state)
      setReport(payload.report)
      setConfig(cfg)
      setSave("saved")
    } catch (err) {
      setLoadError(appErrorFromWp(err).message)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [load])

  const flush = useCallback(async (): Promise<boolean> => {
    if (timer.current) clearTimeout(timer.current)
    if (saving.current) return false
    const patch = pending.current
    if (!Object.keys(patch).length) return true
    pending.current = {}
    saving.current = true
    setSave("saving")
    const res = await saveBuilderDraft(auth, id, revision.current, patch)
    saving.current = false
    switch (res.kind) {
      case "saved":
        revision.current = res.payload.draft.revision
        setReport(res.payload.report)
        if (!Object.keys(pending.current).length) {
          setSave("saved")
          return true
        }
        setSave("dirty")
        return flush()
      case "conflict":
        pending.current = mergePatch(patch, pending.current)
        setSave("conflict")
        setBanner({ tone: "attention", message: res.message })
        return false
      case "error":
        pending.current = mergePatch(patch, pending.current)
        setSave("error")
        return false
      default: {
        const exhaustive: never = res
        return exhaustive
      }
    }
  }, [auth, id])

  const update = useCallback(
    (path: string, value: unknown) => {
      // Lists (options, sources, stops) are sent whole; the server replaces lists rather than merging them.
      const top = path.split(".")[0]
      const listPath = ["options", "sources"].includes(top) ? top : path.startsWith("portfolio.stops") ? "portfolio.stops" : null
      setState((prev) => {
        if (!prev) return prev
        const next = setIn(prev as unknown as Record<string, unknown>, path, value) as unknown as BuilderState
        const patchValue = listPath ? listPath.split(".").reduce<unknown>((o, k) => (o as Record<string, unknown>)[k], next) : value
        pending.current = mergePatch(pending.current, patchFor(listPath ?? path, patchValue))
        return next
      })
      setSave("dirty")
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => void flush(), 800)
    },
    [flush],
  )

  useEffect(() => {
    if (step !== "publish" || !state) return
    let active = true
    void flush().then(() => fetchBuilderPreview(auth, id).then((p) => active && setPreview(p)).catch(() => undefined))
    return () => {
      active = false
    }
  }, [step, state, auth, id, flush])

  useEffect(() => {
    if (!state || state.method !== "recurring" || step !== "info") return
    const t = setTimeout(() => {
      void flush().then(() => fetchBuilderDates(auth, id).then(setDates).catch(() => setDates(null)))
    }, 900)
    return () => clearTimeout(t)
  }, [state?.portfolio?.recurrence, state?.event?.date, state?.method, step, auth, id, flush])

  async function publish(confirm = false) {
    if (publishing) return
    setPublishing(true)
    setBanner(null)
    const ok = await flush()
    if (!ok && save === "conflict") {
      setPublishing(false)
      return
    }
    const res = await publishBuilderDraft(auth, id, confirm)
    setPublishing(false)
    switch (res.kind) {
      case "published":
      case "portfolio":
        setResult(res)
        return
      case "scheduled":
        showToast("Scheduled. It goes live at the time you chose.", "success")
        router.replace("/(organizer)/events" as never)
        return
      case "not_ready": {
        const first = res.checks[0]
        setBanner({ tone: "error", message: first ? `${first.label}: ${first.message}` : res.message })
        if (first) setStep(nativeStepFor(first.step))
        return
      }
      case "confirm": {
        const go = await confirmAction({
          title: "Notify booked vendors?",
          message: `${res.booked} booked vendor${res.booked === 1 ? "" : "s"} will be told about the date or place change. Existing bookings and payments stay as they are.`,
          confirmLabel: "Save and notify",
        })
        if (go) void publish(true)
        return
      }
      case "error":
        setBanner({ tone: "error", message: res.message })
        return
      default: {
        const exhaustive: never = res
        return exhaustive
      }
    }
  }

  if (loadError) {
    return (
      <Screen title="Create" header={<BackLink label="Create" fallbackHref={"/(organizer)/create" as never} />}>
        <ErrorPanel title="Couldn't open this draft" message={loadError} onAction={() => void load()} />
      </Screen>
    )
  }
  if (!state) {
    return (
      <Screen title="Create" hideTitle>
        <LoadingPanel label="Opening draft…" variant="skeleton" count={3} />
      </Screen>
    )
  }

  if (result && (result.kind === "published" || result.kind === "portfolio")) {
    return <PublishedView result={result} />
  }

  const ev = state.event
  const portfolio = isPortfolioMethod(state.method)
  const perStop = state.method === "tour" || state.method === "series"
  const steps = state.quick ? BUILDER_STEPS.filter((s) => s.key !== "applications") : BUILDER_STEPS
  const index = Math.max(0, steps.findIndex((s) => s.key === step))
  const blocking = blockingChecks(report)
  const categories = config?.categories?.length ? config.categories : DEFAULT_CATEGORIES
  const isEdit = sourceEvent.current > 0
  const saveLabel = { saved: "Saved", saving: "Saving…", dirty: "Unsaved changes", error: "Not saved — will retry", conflict: "Changed elsewhere" }[save]

  function go(nextIndex: number) {
    void flush()
    setBanner(null)
    setStep(steps[Math.min(Math.max(nextIndex, 0), steps.length - 1)].key)
  }

  return (
    <Screen
      title={ev.name || "New event"}
      hideTitle
      header={<BackLink label="Create" fallbackHref={"/(organizer)/create" as never} />}
      footer={
        <StickyActionBar insideTabs>
          {index > 0 ? <Button label="Back" variant="secondary" onPress={() => go(index - 1)} /> : null}
          {index < steps.length - 1 ? (
            <Button label="Continue" style={styles.flex} onPress={() => go(index + 1)} testID="builder-continue" />
          ) : (
            <Button label={isEdit ? "Save changes" : "Publish"} icon="rocket-outline" variant="accent" style={styles.flex} loading={publishing} loadingLabel="Publishing…" onPress={() => void publish()} testID="builder-publish" />
          )}
        </StickyActionBar>
      }
    >
      <View style={styles.header}>
        <Eyebrow>{isEdit ? "Editing a live event" : portfolio ? { recurring: "Recurring event", tour: "Multi-city tour", series: "Event series" }[state.method as "recurring" | "tour" | "series"] : state.quick ? "Quick create" : "New event"}</Eyebrow>
        <DisplayTitle accessibilityRole="header" numberOfLines={2}>{ev.name || "New event"}</DisplayTitle>
        <Meta accessibilityLiveRegion="polite">{saveLabel} · {report?.progress ?? 0}% ready</Meta>
      </View>
      <StepProgress steps={steps.map((s) => s.label)} current={index} />
      {banner ? (
        <InlineBanner tone={banner.tone} message={banner.message} onDismiss={() => setBanner(null)} actionLabel={save === "conflict" ? "Reload" : undefined} onAction={save === "conflict" ? () => void load() : undefined} />
      ) : null}

      {step === "info" ? (
        <View style={styles.form}>
          <FormField label="Event name" value={ev.name} onChangeText={(v) => update("event.name", v)} placeholder="Charlotte Night Market" maxLength={140} testID="builder-name" />
          {!state.quick ? (
            <FormField label="Description" value={ev.description} onChangeText={(v) => update("event.description", v)} multiline placeholder="What is the event like? Who comes?" hint="Describe what actually happens. Avoid unconfirmed claims." />
          ) : null}

          {state.method === "recurring" ? (
            <RecurrenceEditor state={state} dates={dates} update={update} />
          ) : perStop ? (
            <StopsEditor state={state} update={update} />
          ) : (
            <>
              <IsoDatePickerField label="Date" value={ev.date} onChange={(v) => update("event.date", v)} />
              <TimeRow ev={ev} update={update} />
            </>
          )}

          {!perStop ? (
            <>
              <LocationField city={ev.city} state={ev.state} onChange={({ city, state: st }) => { update("event.city", city); update("event.state", st) }} />
              <FormField label="Venue" value={ev.venue_name} onChangeText={(v) => update("event.venue_name", v)} placeholder="Uptown Green" />
              <FormField label="Street address" value={ev.venue_address} onChangeText={(v) => update("event.venue_address", v)} hint="Only shared with vendors after they book." />
              {state.confirmed !== undefined && blocking.some((c) => c.key === "confirm_location" || c.key === "geo_conflict") ? (
                <Button label="Confirm this location" variant="secondary" icon="checkmark-circle-outline" onPress={() => { update("event.location_confirmed", true); update("confirmed", Array.from(new Set([...(state.confirmed ?? []), "location"]))) }} />
              ) : null}
            </>
          ) : null}
        </View>
      ) : null}

      {step === "options" ? (
        <View style={styles.form}>
          <Meta>One option per kind of vendor space. Prices are booth fees; LNKup adds its checkout fee for vendors.</Meta>
          {state.options.map((o, i) => (
            <Surface key={o.key} style={styles.card}>
              <View style={styles.cardHead}>
                <SectionTitle style={styles.cardTitle}>{o.name || o.category || `Option ${i + 1}`}</SectionTitle>
                <Button label="Remove" variant="ghost" size="sm" icon="trash-outline" accessibilityLabel={`Remove option ${i + 1}`} onPress={() => update("options", state.options.filter((_, j) => j !== i))} />
              </View>
              <View style={styles.chips}>
                {categories.slice(0, 10).map((c) => (
                  <FilterChip key={c} label={c} selected={o.category === c} onPress={() => update("options", state.options.map((x, j) => (j === i ? { ...x, category: c, name: x.name || c } : x)))} />
                ))}
              </View>
              <FormField label="Name" value={o.name} onChangeText={(v) => update("options", state.options.map((x, j) => (j === i ? { ...x, name: v } : x)))} placeholder="Food truck — standard" />
              <View style={styles.row}>
                <View style={styles.flex}>
                  <FormField label="Booth fee ($)" value={String(o.fee ?? 0)} keyboardType="decimal-pad" onChangeText={(raw) => update("options", state.options.map((x, j) => (j === i ? { ...x, fee: Number(raw.replace(/[^0-9.]/g, "")) || 0 } : x)))} />
                </View>
                <View style={styles.flex}>
                  <FormField label="Spaces" value={o.spots ? String(o.spots) : ""} keyboardType="number-pad" onChangeText={(raw) => update("options", state.options.map((x, j) => (j === i ? { ...x, spots: Number(raw.replace(/[^0-9]/g, "")) || 0 } : x)))} />
                </View>
              </View>
            </Surface>
          ))}
          <AddRow label="Add a vendor option" onPress={() => update("options", [...state.options, { key: newOptionKey(), name: "", category: "", fee: 0, spots: 10 }])} />
          {report ? <Meta>{report.summary.types} option{report.summary.types === 1 ? "" : "s"} · {report.summary.spaces} spaces · up to ${report.summary.max_base_fees} in base fees if every space sells</Meta> : null}
        </View>
      ) : null}

      {step === "applications" ? (
        <View style={styles.form}>
          <SectionTitle>How vendors join</SectionTitle>
          <ListGroup>
            {(
              [
                ["review", "Review each application", "You approve every vendor"],
                ["invite", "Invite only", "Only vendors you invite can apply"],
                ["closed", "Closed for now", "Publish the listing, open applications later"],
              ] as const
            ).map(([mode, title, meta]) => (
              <ListRow key={mode} icon={state.applications.mode === mode ? "radio-button-on" : "radio-button-off"} iconTone={state.applications.mode === mode ? "accent" : "default"} title={title} meta={meta} showChevron={false} onPress={() => update("applications.mode", mode)} />
            ))}
          </ListGroup>
          <SectionTitle>Documents</SectionTitle>
          <ListGroup>
            {(
              [
                ["none", "No documents", "Vendors apply without uploads"],
                ["platform_defaults", "Standard documents", "Insurance and permit checks"],
              ] as const
            ).map(([mode, title, meta]) => (
              <ListRow key={mode} icon={state.applications.documents.mode === mode ? "radio-button-on" : "radio-button-off"} iconTone={state.applications.documents.mode === mode ? "accent" : "default"} title={title} meta={meta} showChevron={false} onPress={() => update("applications.documents", { mode, items: [] })} />
            ))}
          </ListGroup>
          <SectionTitle>Application deadline</SectionTitle>
          <View style={styles.chips}>
            <FilterChip label="A week before" selected={state.payments.deadline.mode === "default"} onPress={() => update("payments.deadline", { ...state.payments.deadline, mode: "default" })} />
            {[3, 14, 30].map((d) => (
              <FilterChip key={d} label={`${d} days before`} selected={state.payments.deadline.mode === "relative" && state.payments.deadline.days_before === d} onPress={() => update("payments.deadline", { ...state.payments.deadline, mode: "relative", days_before: d })} />
            ))}
          </View>
          {state.applications.questions.length ? <Meta>{state.applications.questions.length} application question(s) — edit them on LNKup web.</Meta> : null}
        </View>
      ) : null}

      {step === "publish" ? (
        <View style={styles.form}>
          {preview ? (
            <Surface style={styles.previewCard} testID="builder-preview">
              <Eyebrow style={styles.light}>Vendor preview</Eyebrow>
              <SectionTitle style={[styles.light, styles.previewTitle]}>{preview.title || "Untitled event"}</SectionTitle>
              <Meta style={styles.lightMuted}>{[preview.date, preview.time, preview.place].filter(Boolean).join(" · ")}</Meta>
              {preview.options.map((o) => (
                <Text key={o.name} style={styles.light}>{o.name} · {o.free ? "Free" : o.price != null ? `$${o.price}` : ""}</Text>
              ))}
              {preview.sources.length ? <Meta style={styles.lightMuted}>{preview.disclosure}</Meta> : null}
            </Surface>
          ) : (
            <LoadingPanel label="Building preview…" variant="skeleton" count={1} />
          )}

          <SectionTitle>{blocking.length ? "Before you publish" : "Ready to publish"}</SectionTitle>
          <ListGroup>
            {blocking.length === 0 ? (
              <ListRow icon="checkmark-circle" iconTone="accent" title="Everything required is in place" showChevron={false} />
            ) : (
              blocking.map((c) => (
                <ListRow key={c.key} icon="alert-circle-outline" iconTone="danger" title={c.label} meta={c.message} onPress={c.step !== "publish" ? () => setStep(nativeStepFor(c.step)) : undefined} showChevron={c.step !== "publish"} />
              ))
            )}
          </ListGroup>

          {portfolio && !isEdit ? (
            <View style={styles.chips}>
              <FilterChip label={state.method === "recurring" ? "Publish all dates now" : "Publish selected stops now"} selected={state.portfolio.publish_mode === "all"} onPress={() => update("portfolio.publish_mode", "all")} />
              <FilterChip label="Save dates as drafts" selected={state.portfolio.publish_mode === "drafts"} onPress={() => update("portfolio.publish_mode", "drafts")} />
            </View>
          ) : null}
          {state.sources.length || state.import.provider || (perStop && state.portfolio.stops.some((s) => s.url)) ? (
            <Pressable style={styles.attest} onPress={() => update("publish.attest", !state.publish.attest)} accessibilityRole="checkbox" accessibilityState={{ checked: state.publish.attest }} testID="builder-attest">
              <AppIcon name={state.publish.attest ? "checkbox" : "square-outline"} size={22} color={colors.black} />
              <View style={styles.flex}>
                <Text style={styles.attestTitle}>I organize this event or am authorized to manage its vendors</Text>
                <Meta>Linking a listing does not prove ownership. Disputed events are reviewed by LNKup.</Meta>
              </View>
            </Pressable>
          ) : null}
          <Meta>Images, questions and per-option details can be refined on LNKup web at any time.</Meta>
        </View>
      ) : null}
    </Screen>
  )
}

function TimeRow({ ev, update }: { ev: BuilderState["event"]; update: (path: string, value: unknown) => void }) {
  return (
    <View style={styles.row}>
      <View style={styles.flex}>
        <FormField label="Start time" value={ev.start_time} onChangeText={(v) => update("event.start_time", v)} placeholder="10:00" keyboardType="numbers-and-punctuation" error={ev.start_time && !TIME.test(ev.start_time) ? "Use HH:MM (24-hour)" : undefined} />
      </View>
      <View style={styles.flex}>
        <FormField label="End time" value={ev.end_time} onChangeText={(v) => update("event.end_time", v)} placeholder="18:00" keyboardType="numbers-and-punctuation" error={ev.end_time && !TIME.test(ev.end_time) ? "Use HH:MM (24-hour)" : undefined} />
      </View>
    </View>
  )
}

function RecurrenceEditor({ state, dates, update }: { state: BuilderState; dates: BuilderDates | null; update: (path: string, value: unknown) => void }) {
  const rec = state.portfolio.recurrence
  return (
    <Surface style={styles.card} testID="builder-schedule">
      <SectionTitle style={styles.cardTitle}>Schedule</SectionTitle>
      <View style={styles.chips}>
        {(
          [
            ["weekly", "Weekly"],
            ["biweekly", "Every 2 weeks"],
            ["monthly_weekday", "Monthly"],
          ] as const
        ).map(([f, label]) => (
          <FilterChip key={f} label={label} selected={rec.frequency === f} onPress={() => update("portfolio.recurrence.frequency", f)} />
        ))}
      </View>
      {rec.frequency !== "monthly_weekday" ? (
        <View style={styles.chips}>
          {WEEKDAYS.map(([d, label]) => (
            <FilterChip key={d} label={label} selected={rec.weekdays.includes(d)} onPress={() => update("portfolio.recurrence.weekdays", rec.weekdays.includes(d) ? rec.weekdays.filter((x) => x !== d) : [...rec.weekdays, d])} />
          ))}
        </View>
      ) : null}
      <IsoDatePickerField label="First date" value={rec.start || state.event.date} onChange={(v) => { update("portfolio.recurrence.start", v); update("event.date", v) }} />
      <FormField label="Number of dates" value={rec.count ? String(rec.count) : ""} keyboardType="number-pad" hint="Up to 52 at a time." onChangeText={(raw) => { update("portfolio.recurrence.until", ""); update("portfolio.recurrence.count", Math.min(52, Number(raw.replace(/[^0-9]/g, "")) || 0)) }} />
      <TimeRow ev={state.event} update={update} />
      {dates ? (
        <View style={styles.dates}>
          <Text style={styles.datesTitle}>{dates.count ? `${dates.count} dates · ${formatShortDate(dates.first)} – ${formatShortDate(dates.last)}` : "No upcoming dates yet"}</Text>
          <View style={styles.chips}>
            {dates.occurrences.slice(0, 26).map((o) => (
              <FilterChip
                key={o.date}
                label={`${formatShortDate(o.date)}${o.excluded ? " · skipped" : ""}`}
                selected={!o.excluded && !o.past}
                onPress={() => update("portfolio.recurrence.exclude", rec.exclude.includes(o.date) ? rec.exclude.filter((x) => x !== o.date) : [...rec.exclude, o.date])}
              />
            ))}
          </View>
          <Meta>Tap a date to skip it. {dates.deadline}.</Meta>
        </View>
      ) : null}
    </Surface>
  )
}

function StopsEditor({ state, update }: { state: BuilderState; update: (path: string, value: unknown) => void }) {
  const stops = state.portfolio.stops
  const noun = state.method === "tour" ? "stop" : "event"
  const patchStop = (i: number, patch: Partial<BuilderStop>) => update("portfolio.stops", stops.map((s, j) => (j === i ? { ...s, ...patch } : s)))
  return (
    <View style={styles.form} testID="builder-stops">
      <SectionTitle>{state.method === "tour" ? "Tour stops" : "Series events"}</SectionTitle>
      <TimeRow ev={state.event} update={update} />
      {stops.map((s, i) => (
        <Surface key={s.key} style={styles.card}>
          <View style={styles.cardHead}>
            <SectionTitle style={styles.cardTitle}>{s.city ? `${s.city}${s.state ? `, ${s.state}` : ""}` : `${noun === "stop" ? "Stop" : "Event"} ${i + 1}`}</SectionTitle>
            <Button label="Remove" variant="ghost" size="sm" icon="trash-outline" accessibilityLabel={`Remove ${noun} ${i + 1}`} onPress={() => update("portfolio.stops", stops.filter((_, j) => j !== i))} />
          </View>
          <IsoDatePickerField label="Date" value={s.date} onChange={(v) => patchStop(i, { date: v })} />
          <LocationField city={s.city} state={s.state} onChange={({ city, state: st }) => patchStop(i, { city, state: st })} />
          <FormField label="Venue" value={s.venue_name} onChangeText={(v) => patchStop(i, { venue_name: v })} />
          <FormField label="Street address" value={s.venue_address} onChangeText={(v) => patchStop(i, { venue_address: v })} />
          <FilterChip label={s.publish ? "Publish now" : "Hold back"} selected={s.publish} onPress={() => patchStop(i, { publish: !s.publish })} />
        </Surface>
      ))}
      <AddRow label={`Add ${noun}`} onPress={() => update("portfolio.stops", [...stops, blankStop()])} />
    </View>
  )
}

function AddRow({ label, onPress }: { label: string; onPress: () => void }) {
  return (
    <Pressable style={({ pressed }) => [styles.addRow, pressed && styles.pressed]} onPress={onPress} accessibilityRole="button">
      <AppIcon name="add-circle-outline" size={18} color={colors.black} />
      <Text style={styles.addRowText}>{label}</Text>
    </Pressable>
  )
}

function PublishedView({ result }: { result: Extract<BuilderPublishResult, { kind: "published" | "portfolio" }> }) {
  const isSeries = result.kind === "portfolio"
  const link = result.kind === "published" ? result.applyUrl || result.publicUrl : ""
  return (
    <Screen title="Published" hideTitle>
      <Surface variant="accent" accentBorderTop style={styles.done} testID="builder-published">
        <View style={styles.doneIcon}>
          <AppIcon name="checkmark" size={28} color={colors.black} />
        </View>
        <Eyebrow>{isSeries ? (result.live ? `${result.live} live` : "Created") : "Published"}</Eyebrow>
        <DisplayTitle accessibilityRole="header">{result.title || "Your event"}{isSeries ? "" : " is live"}</DisplayTitle>
        <Body>
          {result.kind === "portfolio"
            ? result.background
              ? `Your ${result.dates} dates are being created. Open the series on LNKup web to publish them.`
              : `${result.dates} dates created${result.live ? `, ${result.live} published` : " as drafts"}.`
            : "Vendors can find it now. Share the link to bring in your first applications."}
        </Body>
        {link ? <Button label="Share apply link" icon="share-outline" onPress={() => void Share.share({ message: `Apply to vend at ${result.title}: ${link}`, url: link }).catch(() => undefined)} /> : null}
        <Button
          label={result.kind === "published" ? "Open event" : "Open tours & series"}
          variant="secondary"
          onPress={() => router.replace((result.kind === "published" && result.eventId ? `/(organizer)/events/${result.eventId}` : "/(organizer)/tours") as never)}
        />
        <Button label="Create another" variant="ghost" onPress={() => router.replace("/(organizer)/create" as never)} />
      </Surface>
    </Screen>
  )
}

const styles = StyleSheet.create({
  header: { gap: 2 },
  form: { gap: spacing.md },
  row: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
  card: { gap: spacing.md },
  cardHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  cardTitle: { fontSize: typeScale.bodyLarge, flexShrink: 1 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  dates: { gap: spacing.sm },
  datesTitle: { fontFamily: fonts.bodyBold, fontSize: typeScale.body, color: colors.black },
  previewCard: { gap: spacing.xs, backgroundColor: colors.black, borderColor: colors.black },
  previewTitle: { fontSize: typeScale.headline },
  light: { color: colors.white },
  lightMuted: { color: colors.gray200 },
  attest: { flexDirection: "row", gap: spacing.sm, alignItems: "flex-start", padding: spacing.md, borderRadius: radii.md, borderWidth: 1, borderColor: colors.gray200 },
  attestTitle: { fontFamily: fonts.bodySemi, fontSize: typeScale.body, color: colors.black },
  addRow: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: spacing.sm, minHeight: 48, borderWidth: 1, borderColor: colors.gray400, borderStyle: "dashed", borderRadius: radii.md, backgroundColor: colors.white },
  addRowText: { fontFamily: fonts.bodySemi, color: colors.black, fontSize: typeScale.body },
  pressed: { opacity: 0.75 },
  done: { gap: spacing.md, alignItems: "flex-start" },
  doneIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.lime, alignItems: "center", justifyContent: "center" },
})
