"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { Loader2, ExternalLink, Plus, Trash2 } from "lucide-react"
import { vendorCategories } from "@/lib/data"
import {
  createFeeRow,
  normalizeFeeRows,
  type LaunchFeeRow,
  type OrganizerLaunchDraft,
} from "@/lib/api/organizer-launch"
import { useOrganizerLaunch } from "@/lib/hooks/use-organizer-launch"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { PanelContent, ErrorState } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  LocationField,
  isLocationSubmittable,
  type LocationStatus,
  type LocationSuggestion,
} from "@/components/forms/location-field"

const STEPS = ["Event", "Opportunity", "Review"] as const

export default function OrganizerLaunchPage() {
  const router = useRouter()
  const { draft, setDraft, loading, saving, publishing, error, publishResult, saveDraft, publish, reload } =
    useOrganizerLaunch()
  const [step, setStep] = useState(0)
  const [validationErrors, setValidationErrors] = useState<Array<{ field: string; message: string }>>([])
  const [locationStatus, setLocationStatus] = useState<LocationStatus>("")
  const [locationSuggestions, setLocationSuggestions] = useState<LocationSuggestion[]>([])

  const feeRows = draft.opportunity.feeRows?.length
    ? draft.opportunity.feeRows
    : normalizeFeeRows(draft).length > 0
      ? normalizeFeeRows(draft)
      : [createFeeRow()]

  useEffect(() => {
    trackLnkupEvent({ event: "launch_event_started", source: "organizer_launch" })
  }, [])

  function patchDraft(patch: {
    event?: Partial<OrganizerLaunchDraft["event"]>
    opportunity?: Partial<OrganizerLaunchDraft["opportunity"]>
    status?: OrganizerLaunchDraft["status"]
  }) {
    setDraft((prev) => ({
      ...prev,
      ...patch,
      event: { ...prev.event, ...(patch.event ?? {}) },
      opportunity: { ...prev.opportunity, ...(patch.opportunity ?? {}) },
    }))
  }

  function setFeeRows(nextRows: LaunchFeeRow[]) {
    const categories = nextRows.map((row) => row.category).filter(Boolean)
    const primary = nextRows[0]
    patchDraft({
      opportunity: {
        feeRows: nextRows,
        categories,
        vendorFee: primary?.vendorFee ?? 0,
        spotsTotal: primary?.spotsTotal ?? 1,
      },
    })
  }

  function updateFeeRow(id: string, patch: Partial<LaunchFeeRow>) {
    setFeeRows(feeRows.map((row) => (row.id === id ? { ...row, ...patch } : row)))
  }

  function addFeeRow() {
    setFeeRows([...feeRows, createFeeRow()])
  }

  function removeFeeRow(id: string) {
    if (feeRows.length <= 1) {
      setFeeRows([createFeeRow()])
      return
    }
    setFeeRows(feeRows.filter((row) => row.id !== id))
  }

  async function handleSaveDraft() {
    const next = {
      ...draft,
      opportunity: {
        ...draft.opportunity,
        feeRows,
        categories: feeRows.map((row) => row.category).filter(Boolean),
      },
    }
    await saveDraft(next)
    trackLnkupEvent({ event: "launch_event_draft_saved", source: "organizer_launch" })
  }

  async function handlePublish() {
    trackLnkupEvent({ event: "launch_event_publish_clicked", source: "organizer_launch" })
    if (!isLocationSubmittable(locationStatus)) {
      setStep(0)
      setValidationErrors([{ field: "location", message: "Confirm the event city and state before publishing." }])
      return
    }
    const next = {
      ...draft,
      opportunity: {
        ...draft.opportunity,
        feeRows,
        categories: feeRows.map((row) => row.category).filter(Boolean),
        vendorFee: feeRows[0]?.vendorFee ?? 0,
        spotsTotal: feeRows[0]?.spotsTotal ?? 1,
      },
    }
    const result = await publish(next)
    if (result.status === "validation_error") {
      setValidationErrors(result.validationErrors ?? [])
      setLocationSuggestions(result.locationSuggestions ?? [])
      if (result.validationErrors?.some((e) => e.field === "location")) setStep(0)
      return
    }
    if (result.status === "published" && result.eventId) {
      trackLnkupEvent({ event: "launch_event_published", source: "organizer_launch", eventId: result.eventId })
      router.push(result.eventUrl ?? `/organizer/events/${result.eventId}`)
      return
    }
    if (result.status === "handoff_required") {
      trackLnkupEvent({ event: "launch_event_handoff_clicked", source: "organizer_launch" })
    }
  }

  if (publishResult?.status === "handoff_required") {
    return (
      <div className="flex flex-col gap-6" data-testid="launch-handoff">
        <PageHeader title="Finish on LNKup web" subtitle={publishResult.message} />
        <div className="rounded-2xl border p-4 text-sm">
          <p className="font-medium">{draft.event.title || "Your event"}</p>
          <p className="text-muted-foreground">
            {draft.event.city}, {draft.event.state} ·{" "}
            {feeRows.map((row) => row.category).filter(Boolean).join(", ") || "No categories"}
          </p>
        </div>
        {publishResult.wordpressUrl && (
          <Button
            nativeButton={false}
            render={<a href={publishResult.wordpressUrl} target="_blank" rel="noopener noreferrer" />}
          >
            Open Launch Event on LNKup web
            <ExternalLink data-icon="inline-end" />
          </Button>
        )}
        <Button variant="outline" nativeButton={false} render={<Link href="/organizer" />}>
          Back to organizer home
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6" data-testid="organizer-launch">
      <PageHeader
        title="Launch event"
        subtitle="Create your event and first vendor opportunity. Optional photos/videos can be added on LNKup web Create Event."
      />

      <div className="flex gap-2">
        {STEPS.map((label, i) => (
          <button
            key={label}
            type="button"
            onClick={() => setStep(i)}
            className={`rounded-full px-3 py-1 text-xs font-medium ${i === step ? "bg-primary text-primary-foreground" : "bg-muted"}`}
          >
            {label}
          </button>
        ))}
      </div>

      <PanelContent state={loading ? "loading" : error ? "error" : "content"} retry={reload}>
        {step === 0 && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-2 sm:col-span-2">
              <Label>Event title *</Label>
              <Input
                value={draft.event.title}
                onChange={(e) => patchDraft({ event: { title: e.target.value } })}
                data-testid="launch-event-title"
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Event date *</Label>
              <Input type="date" value={draft.event.date ?? ""} onChange={(e) => patchDraft({ event: { date: e.target.value } })} />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Event type</Label>
              <Input value={draft.event.eventType ?? ""} onChange={(e) => patchDraft({ event: { eventType: e.target.value } })} />
            </div>
            <LocationField
              required
              city={draft.event.city ?? ""}
              state={draft.event.state ?? ""}
              onChange={({ city, state }) => patchDraft({ event: { city, state } })}
              onStatusChange={setLocationStatus}
              externalSuggestions={locationSuggestions}
              externalMessage={validationErrors.find((e) => e.field === "location")?.message}
            />
            <div className="flex flex-col gap-2 sm:col-span-2">
              <Label>Venue</Label>
              <Input value={draft.event.venueName ?? ""} onChange={(e) => patchDraft({ event: { venueName: e.target.value } })} />
            </div>
            <div className="flex flex-col gap-2 sm:col-span-2">
              <Label>Description</Label>
              <Textarea value={draft.event.description ?? ""} onChange={(e) => patchDraft({ event: { description: e.target.value } })} rows={3} />
            </div>
          </div>
        )}

        {step === 1 && (
          <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-2">
              <div>
                <Label>Vendor type booth prices *</Label>
                <p className="mt-1 text-xs text-muted-foreground">
                  Add one row per vendor category with its booth fee and spots.
                </p>
              </div>
              <Button type="button" size="sm" variant="outline" onClick={addFeeRow} data-testid="launch-add-fee-row">
                <Plus data-icon="inline-start" />
                Add type
              </Button>
            </div>

            <div className="flex flex-col gap-3" data-testid="launch-fee-rows">
              {feeRows.map((row, index) => (
                <div key={row.id} className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1.4fr_1fr_1fr_auto]">
                  <div className="flex flex-col gap-2">
                    <Label>Category {index + 1}</Label>
                    <select
                      className="h-9 rounded-md border bg-background px-2 text-sm"
                      value={row.category}
                      onChange={(e) => updateFeeRow(row.id, { category: e.target.value })}
                      data-testid={`launch-fee-category-${index}`}
                    >
                      <option value="">Select category</option>
                      {vendorCategories.map((cat) => (
                        <option key={cat} value={cat}>
                          {cat}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="flex flex-col gap-2">
                    <Label>Vendor fee ($)</Label>
                    <Input
                      type="number"
                      min={0}
                      step="0.01"
                      value={row.vendorFee}
                      onChange={(e) => updateFeeRow(row.id, { vendorFee: Number(e.target.value) })}
                      data-testid={`launch-fee-amount-${index}`}
                    />
                  </div>
                  <div className="flex flex-col gap-2">
                    <Label>Spots</Label>
                    <Input
                      type="number"
                      min={1}
                      value={row.spotsTotal}
                      onChange={(e) => updateFeeRow(row.id, { spotsTotal: Number(e.target.value) })}
                      data-testid={`launch-fee-spots-${index}`}
                    />
                  </div>
                  <div className="flex items-end">
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => removeFeeRow(row.id)}
                      aria-label={`Remove fee row ${index + 1}`}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label>Deadline</Label>
                <Input
                  type="date"
                  value={draft.opportunity.applicationDeadline ?? ""}
                  onChange={(e) => patchDraft({ opportunity: { applicationDeadline: e.target.value } })}
                />
              </div>
            </div>
            <div className="flex flex-col gap-2">
              <Label>Application instructions</Label>
              <Textarea
                value={draft.opportunity.applicationInstructions ?? ""}
                onChange={(e) => patchDraft({ opportunity: { applicationInstructions: e.target.value } })}
                rows={2}
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label>Required documents</Label>
              <div className="flex flex-wrap gap-2">
                {(
                  [
                    ["none", "None"],
                    ["platform_defaults", "Platform defaults"],
                    ["custom", "Custom (set on LNKup web)"],
                  ] as const
                ).map(([value, label]) => (
                  <Button
                    key={value}
                    type="button"
                    size="sm"
                    variant={draft.opportunity.requiredDocumentsMode === value ? "default" : "outline"}
                    onClick={() => patchDraft({ opportunity: { requiredDocumentsMode: value } })}
                  >
                    {label}
                  </Button>
                ))}
              </div>
            </div>
          </div>
        )}

        {step === 2 && (
          <div className="rounded-2xl border p-4 text-sm">
            <p className="font-semibold">{draft.event.title || "Untitled event"}</p>
            <p className="text-muted-foreground">
              {draft.event.date} · {draft.event.city}, {draft.event.state}
            </p>
            <ul className="mt-3 space-y-1">
              {feeRows.map((row) => (
                <li key={row.id}>
                  {row.category || "Unnamed type"} · ${row.vendorFee} · {row.spotsTotal} spots
                </li>
              ))}
            </ul>
            {validationErrors.length > 0 && (
              <ul className="mt-3 list-disc pl-5 text-destructive">
                {validationErrors.map((e) => (
                  <li key={e.field}>{e.message}</li>
                ))}
              </ul>
            )}
          </div>
        )}

        <div className="mt-6 flex flex-wrap gap-2">
          {step < 2 && (
            <Button onClick={() => setStep((s) => s + 1)}>Next</Button>
          )}
          {step > 0 && (
            <Button variant="outline" onClick={() => setStep((s) => s - 1)}>
              Back
            </Button>
          )}
          <Button variant="outline" onClick={handleSaveDraft} disabled={saving}>
            {saving && <Loader2 className="animate-spin" data-icon="inline-start" />}
            Save draft
          </Button>
          {step === 2 && (
            <Button onClick={handlePublish} disabled={publishing} data-testid="launch-publish-button">
              {publishing && <Loader2 className="animate-spin" data-icon="inline-start" />}
              Publish opportunity
            </Button>
          )}
        </div>
        {error && <ErrorState description={error} onRetry={reload} />}
      </PanelContent>
    </div>
  )
}
