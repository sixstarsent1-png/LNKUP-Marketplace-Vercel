"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, PartyPopper, AlertCircle } from "lucide-react"
import type { EventItem } from "@/lib/data"
import { isLiveApiEnabled, getWpRegistrationUrl, getVendorLoginUrl } from "@/lib/api/config"
import type { WpApplyContext, WpApplyDraftSummary } from "@/lib/api/types"
import {
  applyRequest,
  invalidFieldIds,
  newIdempotencyKey,
  type ApplyAnswerValue,
} from "@/lib/api/apply-client"
import { useApplyDraft } from "@/lib/hooks/use-apply-draft"
import { ApplyQuestions } from "@/components/marketplace/apply-questions"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import {
  normalizeVendorRequestStatus,
  vendorRequestStatusLabel,
} from "@/lib/api/vendor-requests"
import { useAuth } from "@/lib/hooks/use-auth"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

const REAPPLY_NOTES: Record<string, string> = {
  organizer_allowed: "The organizer re-opened this booth for you. You can apply again.",
  invited_again: "The organizer invited you again. You can apply again.",
  opportunity_changed: "This booth changed since your last application. You can apply again.",
}

/** Submit refusals that mean the booth changed while the vendor was filling in the form. */
const CHANGED_WHILE_APPLYING: Record<string, string> = {
  deadline: "Applications closed",
  full: "This booth is full",
  closed: "Applications closed",
  invite_only: "Invite only",
}

const SUBMIT_BLOCKING_CODES = new Set(["duplicate", "previously_declined", ...Object.keys(CHANGED_WHILE_APPLYING)])

function reapplyNoteFor(previous: WpApplyContext["previous_application"]): string | null {
  if (!previous || previous.reapply !== "allowed") return null
  return REAPPLY_NOTES[previous.reason] ?? "You withdrew an earlier application for this booth. You can apply again."
}

type ApplyModalProps = {
  event: EventItem
  children?: React.ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
  onSubmitted?: () => void
}

export function ApplyModal({
  event,
  children,
  open: controlledOpen,
  onOpenChange,
  onSubmitted,
}: ApplyModalProps) {
  const live = isLiveApiEnabled()
  const router = useRouter()
  const { isAuthenticated, loading: authLoading } = useAuth()
  const [internalOpen, setInternalOpen] = useState(false)
  const open = controlledOpen ?? internalOpen
  const setOpen = onOpenChange ?? setInternalOpen

  const [agreed, setAgreed] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [message, setMessage] = useState("")
  const [category, setCategory] = useState(event.categoriesNeeded[0] ?? "")
  const [context, setContext] = useState<WpApplyContext | null>(null)
  const [contextLoading, setContextLoading] = useState(false)
  const [contextError, setContextError] = useState<string | null>(null)
  const [contextErrorCode, setContextErrorCode] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState("")
  const [setupNotes, setSetupNotes] = useState("")
  const [answers, setAnswers] = useState<Record<string, ApplyAnswerValue>>({})
  const [invalidFields, setInvalidFields] = useState<string[]>([])
  const [priceChanged, setPriceChanged] = useState(false)
  const idempotencyKey = useRef<string>(newIdempotencyKey())

  const opportunityId = event.opportunityId
  const numericOpportunityId = opportunityId ? Number(opportunityId) : null
  const draftsEnabled = Boolean(context?.features?.drafts && context?.eligibility?.can_apply)

  const loadDraft = useCallback((draft: WpApplyDraftSummary) => {
    if (draft.fields?.selected_category) setCategory(draft.fields.selected_category)
    setMessage(draft.fields?.application_message ?? "")
    setSetupNotes(draft.fields?.setup_notes ?? "")
    setAnswers((draft.answers ?? {}) as Record<string, ApplyAnswerValue>)
  }, [])

  const draft = useApplyDraft({
    opportunityId: numericOpportunityId,
    enabled: draftsEnabled,
    initial: context?.draft,
    onConflict: loadDraft,
  })

  function queueDraftSave(next: Partial<{ category: string; message: string; setupNotes: string; answers: Record<string, ApplyAnswerValue> }>) {
    draft.schedule(
      {
        selected_category: next.category ?? category,
        application_message: next.message ?? message,
        setup_notes: next.setupNotes ?? setupNotes,
      },
      next.answers ?? answers,
    )
  }
  const categories =
    context?.categories?.length
      ? context.categories
      : event.categoriesNeeded.length
        ? event.categoriesNeeded
        : ["General"]

  function reset() {
    setSubmitted(false)
    setAgreed(false)
    setMessage("")
    setCategory(event.categoriesNeeded[0] ?? "")
    setContext(null)
    setContextError(null)
    setContextErrorCode(null)
    setSubmitError(null)
    setSuccessMessage("")
    setSetupNotes("")
    setAnswers({})
    setInvalidFields([])
    setPriceChanged(false)
    idempotencyKey.current = newIdempotencyKey()
  }

  useEffect(() => {
    if (!open || !live || !opportunityId) return
    if (!isAuthenticated) return

    let cancelled = false
    setContextLoading(true)
    setContextError(null)
    setContextErrorCode(null)

    fetch(`/api/applications/apply-context/${opportunityId}`)
      .then(async (response) => {
        const data = await response.json()
        if (!response.ok) {
          if (!cancelled && data.code) {
            setContextErrorCode(String(data.code))
          }
          throw new Error(data.message || "Could not load apply form")
        }
        if (!cancelled) {
          const ctx = data as WpApplyContext
          setContext(ctx)
          if (ctx.default_category) {
            setCategory(ctx.default_category)
          } else if (ctx.categories?.[0]) {
            setCategory(ctx.categories[0])
          }
          if (ctx.draft && ctx.features?.drafts) {
            loadDraft(ctx.draft)
          }
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setContextError(error instanceof Error ? error.message : "Could not load apply form")
        }
      })
      .finally(() => {
        if (!cancelled) setContextLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [open, live, opportunityId, isAuthenticated, loadDraft])

  async function handleSubmit() {
    if (live && opportunityId) {
      setSubmitting(true)
      setSubmitError(null)
      setInvalidFields([])
      try {
        let result
        if (draftsEnabled) {
          // Drafts: persist the latest edits, then finalize the draft (price is re-checked server-side).
          draft.schedule({ selected_category: category, application_message: message, setup_notes: setupNotes }, answers)
          const saved = await draft.flush()
          if (saved === "conflict") {
            setSubmitError("This application was updated on another device. Review it and submit again.")
            return
          }
          result = await applyRequest<{ message?: string }>(`/api/applications/drafts/${opportunityId}/submit`, {
            method: "POST",
            body: { accepted_terms: agreed, accept_current_price: priceChanged },
            idempotencyKey: idempotencyKey.current,
          })
        } else {
          result = await applyRequest<{ message?: string }>("/api/applications/apply", {
            method: "POST",
            body: {
              opportunity_id: opportunityId,
              selected_category: category,
              application_message: message,
              setup_notes: setupNotes,
              answers,
              accepted_terms: agreed,
            },
            idempotencyKey: idempotencyKey.current,
          })
        }

        if (result.status === 401) {
          router.push("/login")
          return
        }

        if (result.code === "marketplace_closed") {
          trackLnkupEvent({
            event: "apply_prelaunch_blocked",
            source: "apply_modal",
            opportunityId: String(opportunityId),
            eventId: event.id,
          })
          setContextErrorCode("marketplace_closed")
          setContextError(result.message || "Applications are not open yet.")
          return
        }

        if (result.code && SUBMIT_BLOCKING_CODES.has(result.code)) {
          setContextErrorCode(result.code)
          setContextError(result.message)
          return
        }

        if (result.code === "lnkup_answers_required" || result.code === "lnkup_invalid_answer") {
          setInvalidFields(invalidFieldIds(result))
          setSubmitError(result.message)
          return
        }

        if (result.code === "lnkup_price_changed") {
          setPriceChanged(true)
          setSubmitError(result.message)
          return
        }

        if (!result.ok) {
          throw new Error(result.message || "Application failed")
        }

        setSuccessMessage(result.data?.message || "Application submitted.")
        trackLnkupEvent({
          event: "apply_submitted",
          source: "apply_modal",
          opportunityId: String(opportunityId),
          eventId: event.id,
        })
        setSubmitted(true)
        onSubmitted?.()
      } catch (error) {
        setSubmitError(error instanceof Error ? error.message : "Application failed")
      } finally {
        setSubmitting(false)
      }
      return
    }

    setSubmitted(true)
  }

  const registrationUrl = getWpRegistrationUrl({
    role: "vendor",
    redirect: "/vendor/onboarding",
  })
  const loginUrl = getVendorLoginUrl({ intent: "vendor", redirect: opportunityId ? `/vendor/find?opportunityId=${opportunityId}&apply=1` : "/vendor/find" })
  const marketplaceClosed = contextErrorCode === "marketplace_closed"
  const duplicateApply = contextErrorCode === "duplicate" || context?.eligibility?.block_reason === "duplicate"
  const declined =
    contextErrorCode === "previously_declined" || context?.eligibility?.block_reason === "previously_declined"
  const changedTitle = contextErrorCode ? CHANGED_WHILE_APPLYING[contextErrorCode] : undefined
  const blocked =
    marketplaceClosed ||
    duplicateApply ||
    declined ||
    Boolean(changedTitle) ||
    (context?.eligibility?.can_apply === false && !duplicateApply)
  const blockMessage = declined
    ? "This organizer declined your earlier application. You can apply again if they invite you or change this opportunity."
    : contextError ||
      (context?.eligibility?.block_reason && !duplicateApply
        ? "You cannot apply to this opportunity."
        : null)
  const reapplyNote = reapplyNoteFor(context?.previous_application)

  const duplicateStatus = context?.eligibility?.existing_application_status
    ? vendorRequestStatusLabel(normalizeVendorRequestStatus(context.eligibility.existing_application_status))
    : undefined

  const dialogBody = (
    <DialogContent className="sm:max-w-md">
      {submitted ? (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <span className="flex size-14 items-center justify-center rounded-full bg-accent">
            <PartyPopper className="size-7 text-accent-foreground" />
          </span>
          <DialogHeader className="items-center">
            <DialogTitle>Application submitted</DialogTitle>
            <DialogDescription className="text-center">
              {successMessage ||
                "Application submitted. You'll be notified when the organizer reviews it."}
            </DialogDescription>
          </DialogHeader>
          <Button className="w-full" nativeButton={false} render={<Link href="/vendor/requests" />}>
            View vendor requests
          </Button>
          <DialogClose render={<Button variant="outline" className="w-full" />}>Close</DialogClose>
        </div>
      ) : live && opportunityId && (authLoading || contextLoading) ? (
        <div className="flex flex-col items-center gap-3 py-8">
          <Loader2 className="size-8 animate-spin text-muted-foreground" />
          <p className="text-sm text-muted-foreground">Loading application…</p>
        </div>
      ) : live && opportunityId && !isAuthenticated ? (
        <>
          <DialogHeader>
            <DialogTitle>Sign in to apply</DialogTitle>
            <DialogDescription>
              Apply for free. Pay only if accepted. Sign in with your LNKup account to continue.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Button nativeButton={false} render={<Link href={loginUrl} />}>
              Sign in to apply
            </Button>
            <Button variant="outline" nativeButton={false} render={<a href={registrationUrl} />}>
              Create vendor profile
            </Button>
          </div>
        </>
      ) : live && opportunityId && blocked ? (
        <>
          <DialogHeader>
            <DialogTitle>
              {marketplaceClosed
                ? "Applications not open yet"
                : duplicateApply
                  ? "You already applied"
                  : declined
                    ? "Application declined"
                    : (changedTitle ?? "Cannot apply")}
            </DialogTitle>
            <DialogDescription>
              {duplicateApply
                ? `${blockMessage ?? "You already applied."}${duplicateStatus ? ` Status: ${duplicateStatus}.` : ""}`
                : blockMessage}
            </DialogDescription>
          </DialogHeader>
          {(duplicateApply || declined) && (
            <Button nativeButton={false} render={<Link href="/vendor/requests" />}>
              View vendor requests
            </Button>
          )}
          {marketplaceClosed && (
            <Button variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
              Find more events
            </Button>
          )}
        </>
      ) : (
        <>
          <DialogHeader>
            <DialogTitle>Apply with your LNKup profile</DialogTitle>
            <DialogDescription>
              Your profile does most of the work. Add a short note and submit your application for free.
            </DialogDescription>
          </DialogHeader>

          {context?.profile && live && (
            <div className="rounded-xl bg-muted p-3 text-sm" data-testid="lnkup-apply-profile-snapshot">
              <p className="font-medium">{context.profile.business_name}</p>
              <p className="text-muted-foreground">
                {[context.profile.city, context.profile.state].filter(Boolean).join(", ")}
              </p>
            </div>
          )}

          {((context?.opportunity?.gallery as Array<{ url?: string; type?: string; thumb?: string }> | undefined)
            ?.length ||
            event?.gallery?.length) ? (
            <div
              className="grid grid-cols-3 gap-2"
              data-testid="lnkup-apply-event-gallery"
            >
              {(
                (context?.opportunity?.gallery as Array<{
                  url?: string
                  type?: string
                  thumb?: string
                }>) ??
                event?.gallery ??
                []
              )
                .slice(0, 6)
                .map((media, idx) => (
                  <div
                    key={`${media.url ?? idx}`}
                    className="relative aspect-square overflow-hidden rounded-xl bg-muted"
                  >
                    {media.type === "video" ? (
                      <video
                        src={media.url}
                        className="size-full object-cover"
                        controls
                        preload="metadata"
                      />
                    ) : (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={media.thumb || media.url || ""}
                        alt=""
                        className="size-full object-cover"
                      />
                    )}
                  </div>
                ))}
            </div>
          ) : null}

          {contextError && !blocked && (
            <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
              <AlertCircle className="mt-0.5 size-4 shrink-0" />
              <span>{contextError}</span>
            </div>
          )}

          {reapplyNote && (
            <p className="rounded-xl bg-muted p-3 text-sm" data-testid="lnkup-apply-reapply-note">
              {reapplyNote}
            </p>
          )}

          <div className="flex flex-col gap-4" data-testid="lnkup-apply-form">
            <div className="flex flex-col gap-2">
              <Label>Category</Label>
              <Select
                value={category}
                onValueChange={(value) => {
                  const next = String(value ?? "")
                  setCategory(next)
                  queueDraftSave({ category: next })
                }}
              >
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {categories.map((c) => (
                    <SelectItem key={c} value={c}>
                      {c}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="apply-message">Short message</Label>
              <Textarea
                id="apply-message"
                rows={3}
                value={message}
                onChange={(e) => {
                  setMessage(e.target.value)
                  queueDraftSave({ message: e.target.value })
                }}
                placeholder="Tell the organizer what you'll bring to the event…"
              />
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="apply-setup-notes">Setup notes (optional)</Label>
              <Textarea
                id="apply-setup-notes"
                rows={2}
                value={setupNotes}
                onChange={(e) => {
                  setSetupNotes(e.target.value)
                  queueDraftSave({ setupNotes: e.target.value })
                }}
                placeholder="Power, tent size, vehicle access…"
              />
            </div>

            <ApplyQuestions
              questions={context?.questions ?? []}
              answers={answers}
              invalid={invalidFields}
              documents={context?.vendor_documents ?? []}
              idPrefix="apply-q"
              onChange={(id, value) => {
                const next = { ...answers, [id]: value }
                setAnswers(next)
                queueDraftSave({ answers: next })
              }}
            />

            {draftsEnabled && draft.state !== "idle" && (
              <p className="text-xs text-muted-foreground" role="status" data-testid="lnkup-apply-draft-state">
                {draft.state === "saving"
                  ? "Saving…"
                  : draft.state === "saved"
                    ? "Saved. You can finish this later on any device."
                    : draft.state === "conflict"
                      ? "Updated from another device."
                      : "Couldn't save your progress. Your answers are still here."}
              </p>
            )}

            <Label className="flex items-start gap-2 rounded-xl bg-muted p-3 text-sm font-normal">
              <Checkbox
                checked={agreed}
                onCheckedChange={(v) => setAgreed(v === true)}
                className="mt-0.5"
              />
              <span className="text-muted-foreground">
                I understand payment is only required if I am accepted.
              </span>
            </Label>

            {submitError && (
              <div className="flex flex-col gap-2">
                <p className="text-sm text-destructive" role="alert">
                  {submitError}
                </p>
                <Button variant="outline" size="sm" onClick={handleSubmit} disabled={submitting}>
                  Try again
                </Button>
              </div>
            )}

            <Button className="w-full" disabled={!agreed || submitting} onClick={handleSubmit}>
              {submitting ? (
                <Loader2 className="animate-spin" data-icon="inline-start" />
              ) : (
                <CheckCircle2 data-icon="inline-start" />
              )}
              {priceChanged ? "Submit at the new price" : "Submit application"}
            </Button>
          </div>
        </>
      )}
    </DialogContent>
  )

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        setOpen(o)
        if (!o) {
          void draft.flush()
          setTimeout(reset, 200)
        }
      }}
    >
      {children ? <DialogTrigger render={children as React.ReactElement} /> : null}
      {dialogBody}
    </Dialog>
  )
}
