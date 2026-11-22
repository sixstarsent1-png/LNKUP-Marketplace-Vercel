"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { BadgeCheck, CalendarPlus, Lightbulb, Send, Sparkles, Users } from "lucide-react"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { InviteJob, InvitePreview, MatchedVendor, RecruitmentStage } from "@/lib/api/match"
import { useOrganizerEvents } from "@/lib/hooks/use-organizer-events"
import {
  fetchInviteJob,
  requestInvitePreview,
  sendBatchInvites,
  updateShortlist,
  useEventVendorMatches,
  useRecruitment,
} from "@/lib/hooks/use-match"
import { EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"

const STAGE_LABELS: Record<RecruitmentStage, string> = {
  identified: "Identified",
  shortlisted: "Shortlisted",
  invited: "Invited",
  opened: "Opened invite",
  applied: "Applied",
  approved: "Approved",
  paid: "Booked",
  declined: "Declined",
}

/**
 * Organizer "Find Vendors": discoverable vendors that match one event, with
 * shortlist and organizer-approved batch invites (preview first, then send).
 */
export function FindVendorsPanel({ initialEventId }: { initialEventId?: string | null }) {
  const { items: events, loading: eventsLoading } = useOrganizerEvents()
  const upcoming = useMemo(() => events.filter((e) => e.status !== "draft"), [events])
  const [eventId, setEventId] = useState<string | null>(initialEventId ?? null)
  const [city, setCity] = useState("")
  const [verified, setVerified] = useState(false)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [message, setMessage] = useState("")
  const [preview, setPreview] = useState<InvitePreview | null>(null)
  const [job, setJob] = useState<InviteJob | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  useEffect(() => {
    if (!eventId && upcoming[0]) setEventId(upcoming[0].id)
  }, [eventId, upcoming])

  const filters = useMemo(() => ({ city: city.trim() || undefined, verified }), [city, verified])
  const { result, loading, error, retry } = useEventVendorMatches(eventId, filters)
  const { summary } = useRecruitment(eventId)

  useEffect(() => {
    setSelected(new Set())
    setPreview(null)
  }, [eventId])

  useEffect(() => {
    if (!job || job.status === "complete") return
    const timer = setTimeout(() => {
      fetchInviteJob(job.id)
        .then((next) => {
          setJob(next)
          if (next.status === "complete") void retry()
        })
        .catch((err) => console.error("[FindVendorsPanel] job", err))
    }, 4000)
    return () => clearTimeout(timer)
  }, [job, retry])

  const toggle = useCallback((profileId: string) => {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(profileId)) next.delete(profileId)
      else next.add(profileId)
      return next
    })
    setPreview(null)
  }, [])

  async function handleShortlist() {
    if (!eventId || selected.size === 0) return
    setBusy(true)
    setActionError(null)
    try {
      await updateShortlist(eventId, Array.from(selected), "add")
      trackLnkupEvent({
        event: "organizer_list_action_clicked",
        source: "organizer_find_vendors",
        metadata: { action: "shortlist", count: selected.size },
      })
      await retry()
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "We couldn't update your shortlist.")
    } finally {
      setBusy(false)
    }
  }

  async function handlePreview() {
    if (!eventId || selected.size === 0) return
    setBusy(true)
    setActionError(null)
    try {
      setPreview(await requestInvitePreview({ eventId, profileIds: Array.from(selected), message }))
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "We couldn't preview these invites.")
    } finally {
      setBusy(false)
    }
  }

  async function handleSend() {
    if (!eventId || !preview || preview.recipients.length === 0) return
    setBusy(true)
    setActionError(null)
    try {
      const next = await sendBatchInvites({
        eventId,
        profileIds: preview.recipients.map((r) => r.profileId),
        message,
      })
      setJob(next)
      setPreview(null)
      setSelected(new Set())
      trackLnkupEvent({
        event: "organizer_invite_action_clicked",
        source: "organizer_find_vendors",
        metadata: { action: "batch_invite", count: next.total },
      })
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "We couldn't send these invites.")
    } finally {
      setBusy(false)
    }
  }

  if (eventsLoading) return <SkeletonList count={2} />

  if (upcoming.length === 0) {
    return (
      <EmptyState
        icon={CalendarPlus}
        title="Publish an event to recruit vendors"
        description="Vendor recommendations appear for published events with open vendor spots."
        action={
          <Button size="sm" nativeButton={false} render={<Link href="/organizer/launch" />}>
            Create event
          </Button>
        }
      />
    )
  }

  return (
    <div className="flex flex-col gap-4" data-testid="find-vendors-panel">
      <div className="grid gap-2 sm:grid-cols-3">
        <select
          className="h-9 rounded-md border bg-background px-2 text-sm"
          value={eventId ?? ""}
          onChange={(e) => setEventId(e.target.value || null)}
          aria-label="Event"
          data-testid="find-vendors-event"
        >
          {upcoming.map((event) => (
            <option key={event.id} value={event.id}>
              {event.title}
            </option>
          ))}
        </select>
        <Input placeholder="Vendor city" value={city} onChange={(e) => setCity(e.target.value)} aria-label="Vendor city" />
        <label className="flex h-9 items-center gap-2 rounded-md border px-3 text-sm">
          <input type="checkbox" checked={verified} onChange={(e) => setVerified(e.target.checked)} />
          Verified only
        </label>
      </div>

      {summary && summary.suggestions.length > 0 && (
        <Card className="rounded-2xl" data-testid="recruitment-assistant">
          <CardContent className="flex flex-col gap-2 py-4">
            {summary.suggestions.slice(0, 3).map((s) => (
              <div key={`${s.type}-${s.title}`} className="flex items-start gap-2">
                <Lightbulb className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div>
                  <p className="text-sm font-semibold">{s.title}</p>
                  <p className="text-sm text-muted-foreground">{s.message}</p>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {job && (
        <p className="text-sm" role="status" data-testid="invite-job-status">
          {job.status === "complete"
            ? `Invites sent: ${job.sent}${job.failed ? ` · ${job.failed} skipped` : ""}.`
            : `Sending invites… ${job.sent} of ${job.total}`}
        </p>
      )}

      {actionError && (
        <p className="text-sm text-destructive" role="alert">
          {actionError}
        </p>
      )}

      {selected.size > 0 && (
        <Card className="rounded-2xl border-primary" data-testid="invite-composer">
          <CardContent className="flex flex-col gap-3 py-4">
            <p className="text-sm font-semibold">{selected.size} vendor{selected.size === 1 ? "" : "s"} selected</p>
            <Textarea
              placeholder="Add a short note (optional)"
              value={message}
              maxLength={1000}
              onChange={(e) => {
                setMessage(e.target.value)
                setPreview(null)
              }}
            />
            {preview && (
              <div className="flex flex-col gap-1 rounded-xl bg-muted p-3 text-sm" data-testid="invite-preview">
                <p className="font-semibold">{preview.subject}</p>
                <p className="whitespace-pre-line text-muted-foreground">{preview.body}</p>
                <p>
                  Will invite {preview.recipients.length} vendor{preview.recipients.length === 1 ? "" : "s"}.
                </p>
                {preview.suppressed.map((s) => (
                  <p key={s.profileId} className="text-muted-foreground">
                    Not sent to {s.businessName}: {s.reasonLabel}
                  </p>
                ))}
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" disabled={busy} onClick={() => void handleShortlist()}>
                Add to shortlist
              </Button>
              {preview ? (
                <Button size="sm" disabled={busy || preview.recipients.length === 0} onClick={() => void handleSend()}>
                  <Send data-icon="inline-start" />
                  Send invites
                </Button>
              ) : (
                <Button size="sm" disabled={busy} onClick={() => void handlePreview()}>
                  Preview invites
                </Button>
              )}
            </div>
            <p className="text-xs text-muted-foreground">Inviting a vendor doesn&apos;t book them. They still apply and pay.</p>
          </CardContent>
        </Card>
      )}

      {loading ? (
        <SkeletonList count={2} />
      ) : error ? (
        <EmptyState
          icon={Users}
          title="We couldn't load matching vendors"
          description={error}
          action={
            <Button size="sm" variant="outline" onClick={() => void retry()}>
              Try again
            </Button>
          }
        />
      ) : !result || result.status === "disabled" ? (
        <EmptyState icon={Sparkles} title="Coming soon" description="Vendor recommendations are not available yet." />
      ) : result.status === "needs_open_opportunity" ? (
        <EmptyState
          icon={Users}
          title="Open a vendor slot"
          description="Add an open vendor slot with available spaces to see matching vendors."
        />
      ) : result.items.length === 0 ? (
        <EmptyState
          icon={Users}
          title="No matching vendors yet"
          description="Add vendor categories and an address to your event, or share your application link."
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2" data-testid="find-vendors-results">
          {result.items.map((vendor) => (
            <MatchedVendorCard
              key={vendor.profileId}
              vendor={vendor}
              selected={selected.has(vendor.profileId)}
              onToggle={toggle}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function MatchedVendorCard({
  vendor,
  selected,
  onToggle,
}: {
  vendor: MatchedVendor
  selected: boolean
  onToggle: (profileId: string) => void
}) {
  const location = [vendor.city, vendor.state].filter(Boolean).join(", ")
  return (
    <Card className={`rounded-2xl ${selected ? "border-primary" : ""}`} data-testid="find-vendor-card">
      <CardContent className="flex flex-col gap-2 py-4">
        <div className="flex items-start gap-3">
          {vendor.logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={vendor.logo} alt="" className="size-10 rounded-xl object-cover" />
          ) : (
            <span className="flex size-10 items-center justify-center rounded-xl bg-muted text-sm font-semibold">
              {vendor.businessName.slice(0, 2).toUpperCase()}
            </span>
          )}
          <div className="flex flex-1 flex-col">
            <span className="flex items-center gap-1 text-sm font-semibold">
              {vendor.businessName}
              {vendor.verified && <BadgeCheck className="size-4" aria-label="Verified" />}
            </span>
            <span className="text-xs text-muted-foreground">
              {[vendor.primaryCategory, location].filter(Boolean).join(" · ")}
            </span>
          </div>
          <label className="flex items-center gap-1 text-xs">
            <input
              type="checkbox"
              checked={selected}
              onChange={() => onToggle(vendor.profileId)}
              aria-label={`Select ${vendor.businessName}`}
            />
            Select
          </label>
        </div>
        {vendor.description && <p className="line-clamp-2 text-sm text-muted-foreground">{vendor.description}</p>}
        <ul className="flex flex-col gap-0.5 text-sm">
          {vendor.reasons.slice(0, 3).map((reason) => (
            <li key={reason}>• {reason}</li>
          ))}
        </ul>
        <div className="flex flex-wrap gap-2">
          {vendor.stage && <Badge variant="outline">{STAGE_LABELS[vendor.stage]}</Badge>}
          {vendor.completedEvents > 0 && (
            <Badge variant="outline">
              {vendor.completedEvents} event{vendor.completedEvents === 1 ? "" : "s"} on LNKup
            </Badge>
          )}
          {!vendor.acceptsInvites && <Badge variant="outline">Not accepting new invites</Badge>}
        </div>
      </CardContent>
    </Card>
  )
}
