import { useCallback, useEffect, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import { ApplyQuestions } from "@/components/apply/apply-questions"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import {
  fetchTourApplyContext,
  newIdempotencyKey,
  saveTourApplyDraft,
  submitTourApply,
  tourStopResultLabel,
  type TourApplyContext,
  type TourApplyInput,
  type TourApplyResult,
  type TourApplySlot,
  type TourPerStop,
} from "@/lib/api/apply"
import { appErrorFromWp, WpFetchError, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const SAVE_DELAY_MS = 1000

/** Apply to several tour stops with one form; each stop becomes its own application. */
export default function TourApplyScreen() {
  const { tourId } = useLocalSearchParams<{ tourId: string }>()
  const id = Number(tourId)
  const { auth, isAuthenticated } = useAuth()
  const [context, setContext] = useState<TourApplyContext | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [message, setMessage] = useState("")
  const [setupNotes, setSetupNotes] = useState("")
  const [perStop, setPerStop] = useState<TourPerStop>({})
  const [accepted, setAccepted] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState<TourApplyResult | null>(null)
  const groupId = useRef(newIdempotencyKey())
  const idempotencyKey = useRef(newIdempotencyKey())
  const revision = useRef<number | null>(null)
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async () => {
    if (!Number.isFinite(id) || id <= 0) {
      setError({ code: "not_found", title: "Tour not found", message: "This tour link is not valid.", retryable: false })
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    try {
      const ctx = await fetchTourApplyContext(id, auth ?? undefined)
      setContext(ctx)
      if (ctx.draft) {
        groupId.current = ctx.draft.groupId
        revision.current = ctx.draft.revision
        setSelected(ctx.draft.selections)
        setMessage(ctx.draft.message)
        setSetupNotes(ctx.draft.setupNotes)
        setPerStop(ctx.draft.perStop)
      }
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
    return () => {
      if (saveTimer.current) clearTimeout(saveTimer.current)
    }
  }, [load])

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/tour/apply/${id}` } }} />
  }

  const slots = context?.slots ?? []

  function input(next?: Partial<Pick<TourApplyInput, "selected" | "message" | "setupNotes" | "perStop">>): TourApplyInput {
    return {
      groupId: groupId.current,
      slots,
      selected: next?.selected ?? selected,
      message: next?.message ?? message,
      setupNotes: next?.setupNotes ?? setupNotes,
      perStop: next?.perStop ?? perStop,
    }
  }

  function queueSave(next: Parameters<typeof input>[0]) {
    if (saveTimer.current) clearTimeout(saveTimer.current)
    const body = input(next)
    saveTimer.current = setTimeout(() => {
      saveTourApplyDraft(id, body, revision.current, auth ?? undefined)
        .then((rev) => {
          if (rev != null) revision.current = rev
        })
        .catch((err) => {
          if (err instanceof WpFetchError && err.code === "lnkup_draft_conflict") void load()
        })
    }, SAVE_DELAY_MS)
  }

  function toggle(slot: TourApplySlot) {
    const next = selected.includes(slot.opportunityId)
      ? selected.filter((v) => v !== slot.opportunityId)
      : [...selected, slot.opportunityId]
    setSelected(next)
    queueSave({ selected: next })
  }

  function updateStop(opportunityId: number, patch: TourPerStop[string]) {
    const key = String(opportunityId)
    const next = { ...perStop, [key]: { ...perStop[key], ...patch } }
    setPerStop(next)
    queueSave({ perStop: next })
  }

  async function handleSubmit() {
    if (!accepted || !selected.length || submitting) return
    if (saveTimer.current) clearTimeout(saveTimer.current)
    setSubmitting(true)
    setError(null)
    try {
      setResult(await submitTourApply(id, { ...input(), idempotencyKey: idempotencyKey.current }, auth ?? undefined))
      idempotencyKey.current = newIdempotencyKey()
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setSubmitting(false)
    }
  }

  const selectedSlots = slots.filter((s) => selected.includes(s.opportunityId))

  return (
    <Screen
      title={context?.title ? `Apply · ${context.title}` : "Apply to several stops"}
      header={<BackLink label="Tour" fallbackHref={`/tour/${id}` as Href} />}
      footer={
        context && !result ? (
          <View style={styles.footer}>
            <Button
              label={`Apply to ${selected.length} stop${selected.length === 1 ? "" : "s"}`}
              loadingLabel="Submitting…"
              loading={submitting}
              disabled={!accepted || !selected.length}
              fullWidth
              onPress={() => void handleSubmit()}
              testID="tour-apply-submit"
            />
          </View>
        ) : null
      }
    >
      {loading ? <LoadingPanel label="Loading stops…" variant="skeleton" count={3} /> : null}
      {!loading && error && !context ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {result ? (
        <View style={styles.wrap} testID="tour-apply-results">
          <SectionTitle>
            {result.allSucceeded ? `Sent ${result.submitted} application${result.submitted === 1 ? "" : "s"}` : `${result.submitted} of ${result.total} sent`}
          </SectionTitle>
          {result.results.map((row) => (
            <Surface key={row.opportunityId}>
              <Body>{row.place}</Body>
              <Meta>{tourStopResultLabel(row.result)}</Meta>
            </Surface>
          ))}
          <Button label="View bookings" icon="ticket-outline" fullWidth onPress={() => router.replace("/(vendor)/requests")} />
          {!result.allSucceeded ? (
            <Button label="Fix and resend" variant="secondary" fullWidth onPress={() => setResult(null)} />
          ) : null}
        </View>
      ) : null}

      {!loading && context && !result ? (
        <View style={styles.wrap}>
          <Meta>One form, one application per stop. Each organizer decision and payment stays separate.</Meta>
          {error ? <ErrorPanel title={error.title} message={error.message} onAction={() => setError(null)} actionLabel="Dismiss" /> : null}
          {slots.length === 0 ? <Body>No stops are accepting vendors right now.</Body> : null}
          {slots.map((slot) => {
            const checked = selected.includes(slot.opportunityId)
            return (
              <Pressable
                key={slot.opportunityId}
                disabled={!slot.canApply}
                onPress={() => toggle(slot)}
                style={[styles.stopRow, checked && styles.stopRowOn, !slot.canApply && styles.stopRowOff]}
                accessibilityRole="checkbox"
                accessibilityState={{ checked, disabled: !slot.canApply }}
                aria-checked={checked}
                testID="tour-apply-stop"
              >
                <View style={[styles.checkbox, checked && styles.checkboxOn]} />
                <View style={styles.stopText}>
                  <Text style={styles.stopTitle}>{slot.stopLabel}</Text>
                  <Meta>
                    {[slot.date, slot.title, `$${slot.fee}`].filter(Boolean).join(" · ")}
                  </Meta>
                  {!slot.canApply ? <Meta>{blockLabel(slot.blockReason)}</Meta> : null}
                </View>
              </Pressable>
            )
          })}

          <FormField
            label="Message to the organizer"
            value={message}
            onChangeText={(text) => {
              setMessage(text)
              queueSave({ message: text })
            }}
            multiline
          />
          <FormField
            label="Setup notes (optional)"
            value={setupNotes}
            onChangeText={(text) => {
              setSetupNotes(text)
              queueSave({ setupNotes: text })
            }}
            multiline
          />

          {selectedSlots
            .filter((slot) => slot.categories.length > 1 || slot.questions.length > 0)
            .map((slot) => {
              const stop = perStop[String(slot.opportunityId)] ?? {}
              const current = stop.selected_category || slot.defaultCategory
              return (
                <Surface key={slot.opportunityId}>
                  <SectionTitle>{slot.stopLabel}</SectionTitle>
                  {slot.categories.length > 1 ? (
                    <View style={styles.chips} accessibilityRole="radiogroup" accessibilityLabel={`Category for ${slot.stopLabel}`}>
                      {slot.categories.map((cat) => (
                        <Pressable
                          key={cat}
                          onPress={() => updateStop(slot.opportunityId, { selected_category: cat })}
                          style={[styles.chip, cat === current && styles.chipActive]}
                          accessibilityRole="radio"
                          accessibilityState={{ selected: cat === current }}
                        >
                          <Text style={[styles.chipText, cat === current && styles.chipTextActive]}>{cat}</Text>
                        </Pressable>
                      ))}
                    </View>
                  ) : null}
                  <ApplyQuestions
                    questions={slot.questions}
                    answers={stop.answers ?? {}}
                    invalid={[]}
                    documents={[]}
                    onChange={(questionId, value) =>
                      updateStop(slot.opportunityId, { answers: { ...(stop.answers ?? {}), [questionId]: value } })
                    }
                  />
                </Surface>
              )
            })}

          <Pressable
            style={styles.termsRow}
            onPress={() => setAccepted((v) => !v)}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: accepted }}
            aria-checked={accepted}
          >
            <View style={[styles.checkbox, accepted && styles.checkboxOn]} />
            <Text style={styles.termsText}>I understand I only pay for stops that accept me.</Text>
          </Pressable>
        </View>
      ) : null}
    </Screen>
  )
}

function blockLabel(reason: string): string {
  switch (reason) {
    case "duplicate":
      return "Already applied"
    case "previously_declined":
      return "The organizer declined your earlier application"
    case "full":
      return "No spots left"
    case "deadline":
      return "Deadline passed"
    default:
      return "Not accepting applications"
  }
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.md },
  footer: { paddingHorizontal: spacing.lg, paddingVertical: spacing.md, borderTopWidth: 1, borderTopColor: colors.gray200 },
  stopRow: {
    flexDirection: "row",
    gap: spacing.md,
    alignItems: "flex-start",
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    minHeight: 44,
  },
  stopRowOn: { borderColor: colors.black },
  stopRowOff: { opacity: 0.6 },
  stopText: { flex: 1, gap: 2 },
  stopTitle: { fontSize: typeScale.body, fontWeight: "700", color: colors.black },
  checkbox: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: colors.gray400, marginTop: 1 },
  checkboxOn: { backgroundColor: colors.lime, borderColor: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm, marginVertical: spacing.sm },
  chip: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    minHeight: 44,
    justifyContent: "center",
  },
  chipActive: { backgroundColor: colors.black, borderColor: colors.black },
  chipText: { fontSize: typeScale.footnote, fontWeight: "700", color: colors.gray700 },
  chipTextActive: { color: colors.white },
  termsRow: {
    flexDirection: "row",
    gap: spacing.md,
    alignItems: "flex-start",
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
  },
  termsText: { flex: 1, fontSize: typeScale.body, color: colors.gray700, lineHeight: 20 },
})
