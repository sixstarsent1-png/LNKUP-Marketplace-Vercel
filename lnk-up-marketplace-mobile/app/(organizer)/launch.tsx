import { useCallback, useEffect, useRef, useState } from "react"
import { Pressable, Share, StyleSheet, View } from "react-native"
import { router } from "expo-router"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { LoadingPanel } from "@/components/panel-states"
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
import { newClientToken } from "@/lib/api/client-token"
import { appErrorFromWp } from "@/lib/api/errors"
import { getOpportunityApplyUrl } from "@/lib/api/config"
import {
  clearDraft,
  draftHasContent,
  emptyFeeRow,
  emptyLaunchDraft,
  firstInvalidStep,
  LAUNCH_CATEGORIES,
  LAUNCH_STEPS,
  loadDraft,
  publishLaunch,
  publishServerDraft,
  saveDraft,
  saveServerDraft,
  validateLaunchStep,
  type OrganizerLaunchDraft,
  type OrganizerLaunchFeeRow,
  type OrganizerLaunchPublishResult,
} from "@/lib/api/organizer-launch"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { colors, fonts, layout, radii, spacing, typography, typeScale } from "@/lib/theme"

const FEE_PRESETS = [
  { label: "Free", value: 0 },
  { label: "$100", value: 100 },
  { label: "$250", value: 250 },
  { label: "$500", value: 500 },
] as const

const DOC_MODES: Array<{ value: NonNullable<OrganizerLaunchDraft["opportunity"]["requiredDocumentsMode"]>; label: string; hint: string }> = [
  { value: "platform_defaults", label: "Standard documents", hint: "LNKup's default insurance and permit checks" },
  { value: "none", label: "No documents", hint: "Vendors can apply without uploads" },
]

const TIME = /^\d{2}:\d{2}$/
const LAST_STEP = LAUNCH_STEPS.length - 1

function withToken(draft: OrganizerLaunchDraft): OrganizerLaunchDraft {
  return draft.clientToken ? draft : { ...draft, clientToken: newClientToken() }
}

/** Create an event in six guided steps; progress is kept on the device and drafts on the server. */
export default function OrganizerLaunchScreen() {
  const { auth } = useAuth()
  const [step, setStep] = useState(0)
  const [draft, setDraft] = useState<OrganizerLaunchDraft>(() => withToken(emptyLaunchDraft()))
  const [loading, setLoading] = useState(true)
  const [savingDraft, setSavingDraft] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [errors, setErrors] = useState<Array<{ field: string; message: string }>>([])
  const [banner, setBanner] = useState<{ tone: "error" | "attention" | "success"; message: string } | null>(null)
  const [published, setPublished] = useState<OrganizerLaunchPublishResult | null>(null)
  const busy = useRef(false)
  const hydrated = useRef(false)

  useEffect(() => {
    let active = true
    ;(async () => {
      const stored = await loadDraft()
      if (!active) return
      if (stored && draftHasContent(stored)) {
        const resume = await confirmAction({
          title: "Continue your draft?",
          message: stored.event.title ? `"${stored.event.title}" is saved on this device.` : "You have an unfinished event on this device.",
          confirmLabel: "Continue draft",
          cancelLabel: "Start over",
        })
        if (!active) return
        if (resume) setDraft(withToken(stored))
        else await clearDraft()
      }
      hydrated.current = true
      setLoading(false)
    })()
    return () => {
      active = false
    }
  }, [])

  // Keep progress on the device as the organizer types (leaving never loses work).
  useEffect(() => {
    if (!hydrated.current || published) return
    const timer = setTimeout(() => {
      if (draftHasContent(draft)) void saveDraft(draft).catch(() => undefined)
    }, 600)
    return () => clearTimeout(timer)
  }, [draft, published])

  const patchEvent = useCallback((patch: Partial<OrganizerLaunchDraft["event"]>) => {
    setDraft((prev) => ({ ...prev, event: { ...prev.event, ...patch } }))
  }, [])

  const patchOpportunity = useCallback((patch: Partial<OrganizerLaunchDraft["opportunity"]>) => {
    setDraft((prev) => ({ ...prev, opportunity: { ...prev.opportunity, ...patch } }))
  }, [])

  const patchFeeRow = useCallback((index: number, patch: Partial<OrganizerLaunchFeeRow>) => {
    setDraft((prev) => ({
      ...prev,
      opportunity: {
        ...prev.opportunity,
        feeRows: prev.opportunity.feeRows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
      },
    }))
  }, [])

  function fieldError(field: string): string | undefined {
    return errors.find((e) => e.field === field)?.message
  }

  function goTo(next: number) {
    if (next > step) {
      const blockers = validateLaunchStep(draft, step)
      if (blockers.length > 0) {
        setErrors(blockers)
        setBanner({ tone: "error", message: blockers[0]?.message ?? "Fix the highlighted fields." })
        return
      }
    }
    setErrors([])
    setBanner(null)
    setStep(Math.min(Math.max(next, 0), LAST_STEP))
  }

  async function handleSaveDraft() {
    if (busy.current) return
    busy.current = true
    setSavingDraft(true)
    try {
      await saveDraft(draft)
      if (!draft.event.title?.trim()) {
        setBanner({ tone: "success", message: "Saved on this device. Add an event name to save it to your account." })
        return
      }
      const token = draft.clientToken ?? newClientToken()
      const result = draft.serverEventId
        ? { eventId: draft.serverEventId, message: "Draft saved." }
        : await saveServerDraft(draft, auth, token)
      const next = { ...draft, serverEventId: result.eventId, clientToken: token }
      setDraft(next)
      await saveDraft(next)
      setBanner({ tone: "success", message: "Draft saved to your account. Vendors can't see it until you publish." })
    } catch (err) {
      setBanner({ tone: "attention", message: `Saved on this device only. ${appErrorFromWp(err).message}` })
    } finally {
      busy.current = false
      setSavingDraft(false)
    }
  }

  async function handlePublish() {
    if (busy.current) return
    busy.current = true
    setPublishing(true)
    setErrors([])
    setBanner(null)
    try {
      const token = draft.clientToken ?? newClientToken()
      const result = draft.serverEventId
        ? await publishServerDraft(draft, draft.serverEventId, auth, `${token}-publish`)
        : await publishLaunch(draft, auth, token)
      switch (result.status) {
        case "published":
          setPublished(result)
          return
        case "partial":
          setDraft((prev) => ({ ...prev, serverEventId: result.eventId ?? prev.serverEventId, clientToken: token }))
          setBanner({
            tone: "attention",
            message: `${result.message ?? "Your event was saved, but vendor spots weren't published yet."} Tap Publish again — nothing will be duplicated.`,
          })
          return
        case "validation_error":
          setErrors(result.validationErrors ?? [])
          setBanner({ tone: "error", message: result.message ?? "Fix the highlighted fields." })
          setStep(firstInvalidStep(result.validationErrors ?? []))
          return
        case "auth_required":
        case "forbidden":
        case "error":
          setBanner({ tone: "error", message: result.message ?? "Couldn't publish. Your progress is saved — try again." })
          return
        default: {
          const exhaustive: never = result.status
          return exhaustive
        }
      }
    } finally {
      busy.current = false
      setPublishing(false)
    }
  }

  if (loading) {
    return (
      <Screen title="Create event" subtitle="Set up your event and vendor spots">
        <LoadingPanel label="Loading…" variant="skeleton" count={2} />
      </Screen>
    )
  }

  if (published) {
    const title = draft.event.title?.trim() || "Your event"
    const applyUrl = published.applicationUrl || (published.opportunityId ? getOpportunityApplyUrl(published.opportunityId) : "")
    return (
      <Screen title="Published" hideTitle>
        <Surface variant="accent" accentBorderTop style={styles.done} testID="launch-published">
          <View style={styles.doneIcon}>
            <AppIcon name="checkmark" size={28} color={colors.black} />
          </View>
          <Eyebrow>Published</Eyebrow>
          <DisplayTitle accessibilityRole="header">{title} is live</DisplayTitle>
          <Body>Vendors can now find it and apply. You'll get a notification for each new application.</Body>
          {applyUrl ? (
            <Button
              label="Share apply link"
              icon="share-outline"
              onPress={() => void Share.share({ message: `Apply to vend at ${title}: ${applyUrl}`, url: applyUrl }).catch(() => undefined)}
            />
          ) : null}
          <Button
            label="Open event"
            variant="secondary"
            onPress={() => router.replace((published.eventId ? `/(organizer)/events/${published.eventId}` : "/(organizer)/events") as never)}
          />
        </Surface>
      </Screen>
    )
  }

  const feeRowsError = fieldError("opportunity.feeRows")

  return (
    <Screen
      title="Create event"
      hideTitle
      header={<BackLink label="Back" fallbackHref="/(organizer)/events" />}
      footer={
        <StickyActionBar insideTabs>
          {step > 0 ? <Button label="Back" variant="secondary" onPress={() => goTo(step - 1)} /> : null}
          <Button label="Save draft" variant="secondary" loading={savingDraft} loadingLabel="Saving…" onPress={() => void handleSaveDraft()} />
          {step < LAST_STEP ? (
            <Button label="Continue" style={styles.flex} onPress={() => goTo(step + 1)} />
          ) : (
            <Button label="Publish" icon="rocket-outline" variant="accent" style={styles.flex} loading={publishing} loadingLabel="Publishing…" onPress={() => void handlePublish()} />
          )}
        </StickyActionBar>
      }
    >
      <View style={styles.header}>
        <DisplayTitle accessibilityRole="header">Create event</DisplayTitle>
        {draft.serverEventId ? <Meta>Draft saved to your account</Meta> : null}
      </View>
      <StepProgress steps={LAUNCH_STEPS} current={step} />

      {banner ? (
        <InlineBanner
          tone={banner.tone}
          message={errors.length > 1 ? errors.map((e) => e.message).join(" · ") : banner.message}
          onDismiss={() => setBanner(null)}
        />
      ) : null}

      {step === 0 ? (
        <View style={styles.form}>
          <FormField label="Event name" value={draft.event.title} onChangeText={(title) => patchEvent({ title })} placeholder="Charlotte Night Market" error={fieldError("event.title")} />
          <FormField label="Event type" value={draft.event.eventType ?? ""} onChangeText={(eventType) => patchEvent({ eventType })} placeholder="Market, festival, pop-up…" />
          <FormField
            label="Expected attendance"
            value={draft.event.expectedAttendance != null ? String(draft.event.expectedAttendance) : ""}
            onChangeText={(raw) => {
              const n = Number(raw.replace(/[^0-9]/g, ""))
              patchEvent({ expectedAttendance: raw && Number.isFinite(n) ? n : undefined })
            }}
            keyboardType="number-pad"
            hint="Vendors use this to decide whether to apply."
          />
          <FormField label="Description" value={draft.event.description ?? ""} onChangeText={(description) => patchEvent({ description })} multiline placeholder="What makes this event worth a vendor's day" />
        </View>
      ) : null}

      {step === 1 ? (
        <View style={styles.form}>
          <IsoDatePickerField label="Event date" value={draft.event.date} onChange={(date) => patchEvent({ date })} />
          {fieldError("event.date") ? <Meta style={styles.fieldError}>{fieldError("event.date")}</Meta> : null}
          <View style={styles.row}>
            <View style={styles.flex}>
              <FormField label="Start time" value={draft.event.startTime ?? ""} onChangeText={(startTime) => patchEvent({ startTime })} placeholder="10:00" keyboardType="numbers-and-punctuation" error={draft.event.startTime && !TIME.test(draft.event.startTime) ? "Use HH:MM (24-hour)" : undefined} />
            </View>
            <View style={styles.flex}>
              <FormField label="End time" value={draft.event.endTime ?? ""} onChangeText={(endTime) => patchEvent({ endTime })} placeholder="18:00" keyboardType="numbers-and-punctuation" error={fieldError("event.time")} />
            </View>
          </View>
          <View style={styles.row}>
            <View style={styles.flex}>
              <FormField label="City" value={draft.event.city ?? ""} onChangeText={(city) => patchEvent({ city })} error={fieldError("event.city")} />
            </View>
            <View style={styles.stateField}>
              <FormField label="State" value={draft.event.state ?? ""} onChangeText={(state) => patchEvent({ state: state.toUpperCase().slice(0, 2) })} autoCapitalize="characters" maxLength={2} error={fieldError("event.state")} />
            </View>
          </View>
          <FormField label="Venue" value={draft.event.venueName ?? ""} onChangeText={(venueName) => patchEvent({ venueName })} placeholder="Uptown Green" />
          <FormField label="Venue address" value={draft.event.venueAddress ?? ""} onChangeText={(venueAddress) => patchEvent({ venueAddress })} multiline hint="Only shared with vendors after they pay." />
        </View>
      ) : null}

      {step === 2 ? (
        <View style={styles.form}>
          <Meta>Add one row for each kind of vendor you want (food, retail…). You'll price them next.</Meta>
          {feeRowsError ? <InlineBanner tone="error" message={feeRowsError} /> : null}
          {draft.opportunity.feeRows.map((row, index) => (
            <Surface key={`type-${index}`} style={styles.rowCard}>
              <View style={styles.rowCardHeader}>
                <SectionTitle style={styles.rowCardTitle}>Vendor type {index + 1}</SectionTitle>
                {draft.opportunity.feeRows.length > 1 ? (
                  <Button label="Remove" variant="ghost" size="sm" icon="trash-outline" accessibilityLabel={`Remove vendor type ${index + 1}`} onPress={() => patchOpportunity({ feeRows: draft.opportunity.feeRows.filter((_, i) => i !== index) })} />
                ) : null}
              </View>
              <View style={styles.chips}>
                {LAUNCH_CATEGORIES.map((cat) => (
                  <FilterChip key={cat} label={cat} selected={row.category === cat} onPress={() => patchFeeRow(index, { category: cat })} />
                ))}
              </View>
              <FormField
                label="Or type a category"
                value={LAUNCH_CATEGORIES.includes(row.category as (typeof LAUNCH_CATEGORIES)[number]) ? "" : row.category}
                onChangeText={(category) => patchFeeRow(index, { category })}
                error={fieldError(`opportunity.feeRows.${index}.category`)}
              />
            </Surface>
          ))}
          <Pressable
            style={({ pressed }) => [styles.addRow, pressed && styles.pressed]}
            onPress={() => patchOpportunity({ feeRows: [...draft.opportunity.feeRows, emptyFeeRow()] })}
            accessibilityRole="button"
          >
            <AppIcon name="add-circle-outline" size={18} color={colors.black} />
            <Text style={styles.addRowText}>Add another vendor type</Text>
          </Pressable>
        </View>
      ) : null}

      {step === 3 ? (
        <View style={styles.form}>
          <Meta>Set the booth fee and how many spots each vendor type has. LNKup holds a spot when you approve a vendor.</Meta>
          {draft.opportunity.feeRows.map((row, index) => (
            <Surface key={`price-${index}`} style={styles.rowCard}>
              <SectionTitle style={styles.rowCardTitle}>{row.category || `Vendor type ${index + 1}`}</SectionTitle>
              <View style={styles.row}>
                <View style={styles.flex}>
                  <FormField
                    label="Booth fee ($)"
                    value={row.vendorFee != null ? String(row.vendorFee) : ""}
                    onChangeText={(raw) => {
                      const n = Number(raw.replace(/[^0-9.]/g, ""))
                      patchFeeRow(index, { vendorFee: raw !== "" && Number.isFinite(n) ? n : 0 })
                    }}
                    keyboardType="decimal-pad"
                    error={fieldError(`opportunity.feeRows.${index}.vendorFee`)}
                  />
                </View>
                <View style={styles.flex}>
                  <FormField
                    label="Spots"
                    value={row.spotsTotal != null ? String(row.spotsTotal) : ""}
                    onChangeText={(raw) => {
                      const n = Number(raw.replace(/[^0-9]/g, ""))
                      patchFeeRow(index, { spotsTotal: raw && Number.isFinite(n) ? n : 0 })
                    }}
                    keyboardType="number-pad"
                    error={fieldError(`opportunity.feeRows.${index}.spotsTotal`)}
                  />
                </View>
              </View>
              <View style={styles.chips}>
                {FEE_PRESETS.map((preset) => (
                  <FilterChip key={preset.label} label={preset.label} selected={row.vendorFee === preset.value} onPress={() => patchFeeRow(index, { vendorFee: preset.value })} />
                ))}
              </View>
            </Surface>
          ))}
        </View>
      ) : null}

      {step === 4 ? (
        <View style={styles.form}>
          <IsoDatePickerField label="Application deadline" value={draft.opportunity.applicationDeadline} onChange={(applicationDeadline) => patchOpportunity({ applicationDeadline })} optional />
          {fieldError("opportunity.applicationDeadline") ? <Meta style={styles.fieldError}>{fieldError("opportunity.applicationDeadline")}</Meta> : null}
          <Text style={styles.label}>Documents</Text>
          <ListGroup>
            {DOC_MODES.map((mode) => (
              <ListRow
                key={mode.value}
                icon={draft.opportunity.requiredDocumentsMode === mode.value ? "radio-button-on" : "radio-button-off"}
                iconTone={draft.opportunity.requiredDocumentsMode === mode.value ? "accent" : "default"}
                title={mode.label}
                meta={mode.hint}
                showChevron={false}
                onPress={() => patchOpportunity({ requiredDocumentsMode: mode.value })}
                accessibilityLabel={`${mode.label}${draft.opportunity.requiredDocumentsMode === mode.value ? ", selected" : ""}`}
              />
            ))}
          </ListGroup>
          <Text style={styles.label}>Setting</Text>
          <View style={styles.chips}>
            <FilterChip label="Indoor" selected={Boolean(draft.opportunity.indoor)} onPress={() => patchOpportunity({ indoor: !draft.opportunity.indoor })} />
            <FilterChip label="Outdoor" selected={Boolean(draft.opportunity.outdoor)} onPress={() => patchOpportunity({ outdoor: !draft.opportunity.outdoor })} />
          </View>
          <FormField label="Instructions for applicants" value={draft.opportunity.applicationInstructions ?? ""} onChangeText={(applicationInstructions) => patchOpportunity({ applicationInstructions })} multiline placeholder="Menu, photos of your setup, power needs…" />
        </View>
      ) : null}

      {step === LAST_STEP ? (
        <View style={styles.form}>
          <Surface variant="hero" style={styles.preview} testID="launch-preview">
            <Eyebrow>Vendor preview</Eyebrow>
            <SectionTitle style={styles.previewTitle}>{draft.event.title || "Untitled event"}</SectionTitle>
            <Meta>
              {[draft.event.date, draft.event.startTime && draft.event.endTime ? `${draft.event.startTime}–${draft.event.endTime}` : null, [draft.event.venueName, draft.event.city, draft.event.state].filter(Boolean).join(", ")]
                .filter(Boolean)
                .join(" · ")}
            </Meta>
            {draft.event.description ? <Body numberOfLines={4}>{draft.event.description}</Body> : null}
          </Surface>
          <ListGroup title="Vendor spots">
            {draft.opportunity.feeRows.map((row, index) => (
              <ListRow
                key={`review-${index}`}
                icon="storefront-outline"
                title={row.category || "Vendor type"}
                meta={`${row.spotsTotal ?? 0} spots`}
                rightSlot={<Text style={styles.fee}>{row.vendorFee ? `$${row.vendorFee}` : "Free"}</Text>}
                onPress={() => goTo(3)}
              />
            ))}
          </ListGroup>
          <ListGroup title="Applications">
            <ListRow icon="calendar-outline" title="Deadline" meta={draft.opportunity.applicationDeadline || "Open until the event"} onPress={() => goTo(4)} />
            <ListRow icon="document-attach-outline" title="Documents" meta={DOC_MODES.find((m) => m.value === draft.opportunity.requiredDocumentsMode)?.label ?? "Standard documents"} onPress={() => goTo(4)} />
          </ListGroup>
          <Meta>Photos and video can be added on LNKup web after publishing.</Meta>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  header: { gap: 2 },
  form: { gap: spacing.md },
  row: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
  stateField: { width: 96 },
  fieldError: { color: colors.red600, marginTop: -spacing.sm },
  rowCard: { gap: spacing.md },
  rowCardHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  rowCardTitle: { fontSize: typeScale.bodyLarge },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  addRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin,
    borderWidth: 1,
    borderColor: colors.gray400,
    borderStyle: "dashed",
    borderRadius: radii.md,
    backgroundColor: colors.white,
  },
  addRowText: { fontFamily: fonts.bodySemi, color: colors.black, fontSize: typeScale.body },
  pressed: { opacity: 0.75 },
  label: { ...typography.eyebrow, letterSpacing: 0.5 },
  preview: { gap: spacing.sm },
  previewTitle: { fontSize: typeScale.headline },
  fee: { fontFamily: fonts.bodyBold, fontSize: typeScale.callout, color: colors.black },
  done: { gap: spacing.md, alignItems: "flex-start" },
  doneIcon: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
  },
})
