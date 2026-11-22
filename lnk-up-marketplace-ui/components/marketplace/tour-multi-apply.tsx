"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import Link from "next/link"
import { CheckCircle2, Loader2 } from "lucide-react"
import { applyRequest, newIdempotencyKey, type ApplyAnswerValue } from "@/lib/api/apply-client"
import {
  tourStopResultLabel,
  type TourApplyContext,
  type TourApplyResult,
  type TourApplySlot,
} from "@/lib/api/tour-apply"
import { ApplyQuestions } from "@/components/marketplace/apply-questions"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

type PerStop = Record<string, { selected_category?: string; answers?: Record<string, ApplyAnswerValue> }>

const SAVE_DELAY_MS = 1000

/**
 * Apply to several tour stops at once. Renders nothing when multi-stop apply is off for this
 * deployment (the page keeps its per-stop Apply buttons) or the viewer is not a signed-in vendor.
 */
export function TourMultiApply({ tourId, onSubmitted }: { tourId: string; onSubmitted: () => void }) {
  const [context, setContext] = useState<TourApplyContext | null>(null)
  const [unavailable, setUnavailable] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [message, setMessage] = useState("")
  const [setupNotes, setSetupNotes] = useState("")
  const [perStop, setPerStop] = useState<PerStop>({})
  const [agreed, setAgreed] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [result, setResult] = useState<TourApplyResult | null>(null)
  const groupId = useRef<string>(newIdempotencyKey())
  const idempotencyKey = useRef<string>(newIdempotencyKey())
  const revision = useRef<number | null>(null)
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async () => {
    setLoadError(null)
    const response = await applyRequest<TourApplyContext>(`/api/tours/${encodeURIComponent(tourId)}/apply-context`)
    if (!response.ok) {
      if ([401, 403, 404].includes(response.status)) {
        setUnavailable(true)
        return
      }
      setLoadError(response.message)
      return
    }
    const ctx = response.data as TourApplyContext
    setContext(ctx)
    if (ctx.draft) {
      groupId.current = ctx.draft.groupId
      revision.current = ctx.draft.revision
      setSelected(ctx.draft.selections.map((s) => s.opportunity_id))
      setMessage(ctx.draft.shared.application_message ?? "")
      setSetupNotes(ctx.draft.shared.setup_notes ?? "")
      setPerStop(ctx.draft.perStop as PerStop)
    }
  }, [tourId])

  useEffect(() => {
    void load()
    return () => {
      if (saveTimer.current) clearTimeout(saveTimer.current)
    }
  }, [load])

  const slots = useMemo(() => context?.slots ?? [], [context])
  const selectable = slots.filter((s) => s.canApply)

  function payload(next?: { selected?: number[]; message?: string; setupNotes?: string; perStop?: PerStop }) {
    const ids = next?.selected ?? selected
    return {
      group_id: groupId.current,
      selections: slots
        .filter((s) => ids.includes(s.opportunityId))
        .map((s) => ({ event_id: s.eventId, opportunity_id: s.opportunityId })),
      shared: { application_message: next?.message ?? message, setup_notes: next?.setupNotes ?? setupNotes },
      per_stop: next?.perStop ?? perStop,
    }
  }

  function queueSave(next: Parameters<typeof payload>[0]) {
    if (saveTimer.current) clearTimeout(saveTimer.current)
    const body = payload(next)
    saveTimer.current = setTimeout(async () => {
      const saved = await applyRequest<{ draft: { revision: number } }>(
        `/api/tours/${encodeURIComponent(tourId)}/apply-draft`,
        { method: "PUT", body: { ...body, revision: revision.current } },
      )
      if (saved.ok && saved.data) revision.current = saved.data.draft.revision
      if (saved.code === "lnkup_draft_conflict") void load()
    }, SAVE_DELAY_MS)
  }

  function toggle(slot: TourApplySlot, checked: boolean) {
    const next = checked ? [...selected, slot.opportunityId] : selected.filter((id) => id !== slot.opportunityId)
    setSelected(next)
    queueSave({ selected: next })
  }

  function updateStop(opportunityId: number, patch: PerStop[string]) {
    const key = String(opportunityId)
    const next = { ...perStop, [key]: { ...perStop[key], ...patch } }
    setPerStop(next)
    queueSave({ perStop: next })
  }

  async function handleSubmit() {
    if (saveTimer.current) clearTimeout(saveTimer.current)
    setSubmitting(true)
    setSubmitError(null)
    const response = await applyRequest<TourApplyResult>(`/api/tours/${encodeURIComponent(tourId)}/apply`, {
      method: "POST",
      body: { ...payload(), accepted_terms: agreed },
      idempotencyKey: idempotencyKey.current,
    })
    setSubmitting(false)
    if (!response.ok || !response.data) {
      setSubmitError(response.message)
      return
    }
    setResult(response.data)
    if (response.data.summary.submitted > 0) onSubmitted()
    // A retry of stops that still need input is a new attempt.
    idempotencyKey.current = newIdempotencyKey()
  }

  if (unavailable) return null
  if (loadError) {
    return (
      <Card className="rounded-2xl">
        <CardContent className="flex flex-col gap-2 py-4 text-sm">
          <p>{loadError}</p>
          <Button size="sm" variant="outline" onClick={() => void load()}>
            Try again
          </Button>
        </CardContent>
      </Card>
    )
  }
  if (!context) {
    return (
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" /> Loading multi-stop application…
      </p>
    )
  }
  if (!selectable.length && !result) return null

  return (
    <Card className="rounded-2xl" data-testid="tour-multi-apply">
      <CardContent className="flex flex-col gap-4 py-4">
        <div>
          <h2 className="text-base font-semibold">Apply to several stops</h2>
          <p className="text-sm text-muted-foreground">
            One form, one application per stop. Each organizer decision and payment stays separate.
          </p>
        </div>

        {result ? (
          <TourApplyResults result={result} />
        ) : (
          <>
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-sm font-medium">Stops</legend>
              {slots.map((slot) => (
                <Label key={slot.opportunityId} className="flex items-start gap-2 text-sm font-normal">
                  <Checkbox
                    checked={selected.includes(slot.opportunityId)}
                    disabled={!slot.canApply}
                    onCheckedChange={(checked) => toggle(slot, checked === true)}
                    className="mt-0.5"
                  />
                  <span>
                    <span className="font-medium">{slot.stopLabel}</span>
                    {slot.date ? ` · ${slot.date}` : ""}
                    {slot.title ? ` · ${slot.title}` : ""}
                    {` · $${slot.fee}`}
                    {!slot.canApply && (
                      <span className="block text-xs text-muted-foreground">{blockLabel(slot)}</span>
                    )}
                  </span>
                </Label>
              ))}
            </fieldset>

            <div className="flex flex-col gap-2">
              <Label htmlFor="tour-apply-message">Message to the organizer</Label>
              <Textarea
                id="tour-apply-message"
                rows={3}
                value={message}
                onChange={(e) => {
                  setMessage(e.target.value)
                  queueSave({ message: e.target.value })
                }}
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="tour-apply-notes">Setup notes (optional)</Label>
              <Textarea
                id="tour-apply-notes"
                rows={2}
                value={setupNotes}
                onChange={(e) => {
                  setSetupNotes(e.target.value)
                  queueSave({ setupNotes: e.target.value })
                }}
              />
            </div>

            {slots
              .filter((slot) => selected.includes(slot.opportunityId))
              .filter((slot) => slot.categories.length > 1 || slot.questions.length > 0)
              .map((slot) => {
                const stop = perStop[String(slot.opportunityId)] ?? {}
                return (
                  <div key={slot.opportunityId} className="flex flex-col gap-3 rounded-xl bg-muted p-3">
                    <p className="text-sm font-medium">{slot.stopLabel}</p>
                    {slot.categories.length > 1 && (
                      <Select
                        value={stop.selected_category || slot.defaultCategory}
                        onValueChange={(value) => updateStop(slot.opportunityId, { selected_category: String(value ?? "") })}
                      >
                        <SelectTrigger className="w-full" aria-label={`Category for ${slot.stopLabel}`}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {slot.categories.map((c) => (
                            <SelectItem key={c} value={c}>
                              {c}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                    <ApplyQuestions
                      questions={slot.questions}
                      answers={(stop.answers ?? {}) as Record<string, ApplyAnswerValue>}
                      invalid={[]}
                      documents={[]}
                      idPrefix={`tour-${slot.opportunityId}`}
                      onChange={(id, value) =>
                        updateStop(slot.opportunityId, { answers: { ...(stop.answers ?? {}), [id]: value } })
                      }
                    />
                  </div>
                )
              })}

            <Label className="flex items-start gap-2 rounded-xl bg-muted p-3 text-sm font-normal">
              <Checkbox checked={agreed} onCheckedChange={(v) => setAgreed(v === true)} className="mt-0.5" />
              <span className="text-muted-foreground">I understand payment is only required for stops that accept me.</span>
            </Label>

            {submitError && (
              <p className="text-sm text-destructive" role="alert">
                {submitError}
              </p>
            )}

            <Button disabled={!agreed || !selected.length || submitting} onClick={() => void handleSubmit()}>
              {submitting ? <Loader2 className="animate-spin" data-icon="inline-start" /> : <CheckCircle2 data-icon="inline-start" />}
              Apply to {selected.length} stop{selected.length === 1 ? "" : "s"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}

function blockLabel(slot: TourApplySlot): string {
  if (slot.blockReason === "duplicate") return "Already applied"
  if (slot.blockReason === "previously_declined") return "The organizer declined your earlier application"
  if (slot.blockReason === "full") return "No spots left"
  if (slot.blockReason === "deadline") return "Deadline passed"
  return "Not accepting applications"
}

function TourApplyResults({ result }: { result: TourApplyResult }) {
  return (
    <div className="flex flex-col gap-3" data-testid="tour-multi-apply-results">
      <p className="text-sm font-medium">
        {result.summary.all_succeeded
          ? `Sent ${result.summary.submitted + result.summary.already} application${result.summary.total === 1 ? "" : "s"}.`
          : `${result.summary.submitted + result.summary.already} of ${result.summary.total} sent. Check the stops below.`}
      </p>
      <ul className="flex flex-col gap-2 text-sm">
        {result.results.map((row) => (
          <li key={row.opportunity_id} className="flex flex-col rounded-xl bg-muted p-2">
            <span className="font-medium">{row.city || row.event_name}</span>
            <span className="text-muted-foreground">
              {tourStopResultLabel(row.result)}
              {row.result === "needs_input" && row.fields?.length ? " (answer the organizer's questions)" : ""}
            </span>
          </li>
        ))}
      </ul>
      <Button size="sm" variant="outline" nativeButton={false} render={<Link href="/vendor/requests" />}>
        View vendor requests
      </Button>
    </div>
  )
}
