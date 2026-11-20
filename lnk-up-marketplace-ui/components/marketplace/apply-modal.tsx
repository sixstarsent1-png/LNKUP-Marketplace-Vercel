"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { CheckCircle2, Loader2, PartyPopper, AlertCircle } from "lucide-react"
import type { EventItem } from "@/lib/data"
import { isLiveApiEnabled, getWpRegistrationUrl, getVendorLoginUrl } from "@/lib/api/config"
import type { WpApplyContext } from "@/lib/api/types"
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

  const opportunityId = event.opportunityId
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
          setContext(data as WpApplyContext)
          if (data.default_category) {
            setCategory(data.default_category)
          } else if (data.categories?.[0]) {
            setCategory(data.categories[0])
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
  }, [open, live, opportunityId, isAuthenticated])

  async function handleSubmit() {
    if (live && opportunityId) {
      setSubmitting(true)
      setSubmitError(null)
      try {
        const response = await fetch("/api/applications/apply", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            opportunity_id: opportunityId,
            selected_category: category,
            application_message: message,
            accepted_terms: agreed,
          }),
        })
        const data = await response.json()

        if (response.status === 401) {
          router.push("/login")
          return
        }

        if (response.status === 403 && data.code === "marketplace_closed") {
          trackLnkupEvent({
            event: "apply_prelaunch_blocked",
            source: "apply_modal",
            opportunityId,
            eventId: event.id,
          })
          setContextErrorCode("marketplace_closed")
          setContextError(data.message || "Applications are not open yet.")
          return
        }

        if (response.status === 409 || data.code === "duplicate") {
          setContextErrorCode("duplicate")
          setContextError(data.message || "You already applied to this opportunity.")
          return
        }

        if (!response.ok) {
          throw new Error(data.message || "Application failed")
        }

        setSuccessMessage(data.message || "Application submitted.")
        trackLnkupEvent({
          event: "apply_submitted",
          source: "apply_modal",
          opportunityId,
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
  const loginUrl = getVendorLoginUrl({ intent: "vendor", redirect: "/vendor/find" })
  const marketplaceClosed = contextErrorCode === "marketplace_closed"
  const duplicateApply = contextErrorCode === "duplicate" || context?.eligibility?.block_reason === "duplicate"
  const blocked =
    marketplaceClosed ||
    duplicateApply ||
    (context?.eligibility?.can_apply === false && !duplicateApply)
  const blockMessage =
    context?.button_state?.reason ||
    contextError ||
    (context?.eligibility?.block_reason && !duplicateApply
      ? "You cannot apply to this opportunity."
      : null)

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
                  : "Cannot apply"}
            </DialogTitle>
            <DialogDescription>
              {duplicateApply
                ? `${blockMessage ?? "You already applied."}${duplicateStatus ? ` Status: ${duplicateStatus}.` : ""}`
                : blockMessage}
            </DialogDescription>
          </DialogHeader>
          {duplicateApply && (
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

          <div className="flex flex-col gap-4" data-testid="lnkup-apply-form">
            <div className="flex flex-col gap-2">
              <Label>Category</Label>
              <Select value={category} onValueChange={setCategory}>
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
                onChange={(e) => setMessage(e.target.value)}
                placeholder="Tell the organizer what you'll bring to the event…"
              />
            </div>

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
              Submit application
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
        if (!o) setTimeout(reset, 200)
      }}
    >
      {children ? <DialogTrigger render={children as React.ReactElement} /> : null}
      {dialogBody}
    </Dialog>
  )
}
