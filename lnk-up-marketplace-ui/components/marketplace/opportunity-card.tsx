"use client"

import { useEffect, useRef, useState } from "react"
import dynamic from "next/dynamic"
import Image from "next/image"
import Link from "next/link"
import {
  Calendar,
  MapPin,
  Users,
  Bookmark,
  Ticket,
  Clock,
  Store,
  Loader2,
} from "lucide-react"
import type { EventItem } from "@/lib/data"
import { isLiveApiEnabled } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { useAuth } from "@/lib/hooks/use-auth"
import { useFavorites } from "@/lib/hooks/use-favorites"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { VerificationBadge } from "@/components/marketplace/badges"
import { useOpportunityApplyState } from "@/lib/hooks/use-opportunity-apply-state"
import { cn } from "@/lib/utils"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"

const ApplyModal = dynamic(
  () => import("@/components/marketplace/apply-modal").then((m) => ({ default: m.ApplyModal })),
  { ssr: false },
)

export function OpportunityCard({
  event,
  onCardClick,
  highlighted = false,
  autoOpenApply = false,
}: {
  event: EventItem
  onCardClick?: () => void
  highlighted?: boolean
  autoOpenApply?: boolean
}) {
  const [applyOpen, setApplyOpen] = useState(false)
  const cardRef = useRef<HTMLDivElement>(null)
  const [infoOpen, setInfoOpen] = useState(false)
  const [saveOpen, setSaveOpen] = useState(false)
  const almostFull = event.spotsRemaining > 0 && event.spotsRemaining <= 5
  const full = event.spotsRemaining === 0
  const live = isLiveApiEnabled()
  const { isAuthenticated } = useAuth()
  const { isFavorited, toggleFavorite, isLive: favoritesLive } = useFavorites()
  const saved = isFavorited(event.opportunityId, event.id)
  const { state: applyState, statusLabel, live: liveApi } =
    useOpportunityApplyState(event.opportunityId)

  useEffect(() => {
    if (!autoOpenApply) return
    if (highlighted) {
      cardRef.current?.scrollIntoView({ behavior: "smooth", block: "center" })
    }
    if (applyState.type === "eligible" || !liveApi) {
      setApplyOpen(true)
    }
  }, [autoOpenApply, highlighted, applyState.type, liveApi])

  function handlePrimaryAction() {
    onCardClick?.()
    if (!liveApi || !event.opportunityId) {
      trackLnkupEvent({
        event: "apply_clicked",
        source: "opportunity_card",
        opportunityId: event.opportunityId,
        eventId: event.id,
      })
      setApplyOpen(true)
      return
    }

    if (applyState.type === "logged_out") {
      setInfoOpen(true)
      return
    }

    if (applyState.type === "eligible") {
      trackLnkupEvent({
        event: "apply_clicked",
        source: "opportunity_card",
        opportunityId: event.opportunityId,
        eventId: event.id,
      })
      setApplyOpen(true)
      return
    }

    if (applyState.type === "already_applied") {
      handleAlreadyAppliedAction()
      return
    }

    setInfoOpen(true)
  }

  async function handleToggleSave() {
    if (favoritesLive && !isAuthenticated) {
      setSaveOpen(true)
      return
    }
    try {
      await toggleFavorite(event.opportunityId, event.id)
      trackLnkupEvent({
        event: "save_opportunity_clicked",
        source: "opportunity_card",
        opportunityId: event.opportunityId,
        eventId: event.id,
      })
    } catch {
      setSaveOpen(true)
    }
  }

  function primaryLabel() {
    if (!liveApi || !event.opportunityId) {
      return full ? "Waitlist full" : "Apply"
    }
    if (applyState.type === "loading") return "Loading…"
    if (applyState.type === "logged_out") return "Sign in to apply"
    if (applyState.type === "already_applied") {
      if (
        applyState.status === "payment_required" ||
        applyState.status === "approved"
      ) {
        return "Payment required"
      }
      if (applyState.status === "paid" || applyState.status === "completed") {
        return "Booked"
      }
      return "View status"
    }
    if (applyState.type === "marketplace_closed") return "Applications soon"
    if (applyState.type === "blocked") {
      if (applyState.code === "full") return "Fully booked"
      if (applyState.code === "closed" || applyState.code === "deadline") return "Closed"
      return "Unavailable"
    }
    if (full) return "Waitlist full"
    return "Apply"
  }

  function handleAlreadyAppliedAction() {
    if (applyState.type !== "already_applied") return

    if (
      applyState.status === "payment_required" ||
      applyState.status === "approved"
    ) {
      const appId = applyState.applicationId
      window.location.href = appId
        ? `/vendor/requests?pay=${appId}`
        : "/vendor/requests"
      return
    }

    if (applyState.status === "paid" || applyState.status === "completed") {
      const appId = applyState.applicationId
      window.location.href = appId
        ? `/vendor/pass/${appId}`
        : "/vendor/requests"
      return
    }

    window.location.href = "/vendor/requests"
  }

  const showAppliedBadge =
    liveApi && applyState.type === "already_applied" && statusLabel

  return (
    <>
      <Card
        ref={cardRef}
        id={event.opportunityId ? `opportunity-${event.opportunityId}` : undefined}
        className={cn(
          "gap-0 overflow-hidden rounded-2xl p-0 transition-transform duration-200 hover:-translate-y-0.5 hover:shadow-md",
          highlighted && "ring-2 ring-lime-400 ring-offset-2",
        )}
        data-testid={highlighted ? "opportunity-card-highlighted" : "opportunity-card"}
      >
        <div
          className="relative aspect-[4/5] w-full bg-muted"
          style={event.imageBackground ? { backgroundColor: event.imageBackground } : undefined}
        >
          {event.image && event.image !== "/placeholder.svg" ? (
            <Image
              src={event.image}
              alt={event.imageAlt || event.name}
              fill
              sizes="(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 360px"
              className={event.imageFit === "contain" ? "object-contain p-2" : "object-cover object-center"}
            />
          ) : (
            <div
              className="h-full w-full bg-gradient-to-br from-neutral-800 to-neutral-950"
              role="img"
              aria-label={event.name}
            />
          )}
          <div className="absolute top-3 left-3 flex gap-2">
            {event.trending && (
              <Badge className="bg-accent text-accent-foreground">Trending</Badge>
            )}
            {event.organizerVerified && <VerificationBadge label="Verified" />}
            {event.eventType && (
              <Badge variant="outline">{event.eventType}</Badge>
            )}
            {event.documentsRequired && (
              <Badge variant="secondary">Docs required</Badge>
            )}
            {(event.indoor || event.outdoor) && (
              <Badge variant="outline">
                {event.indoor && event.outdoor ? "Indoor/Outdoor" : event.indoor ? "Indoor" : "Outdoor"}
              </Badge>
            )}
            {showAppliedBadge && (
              <Badge variant="secondary" data-testid="opportunity-applied-badge">
                {statusLabel}
              </Badge>
            )}
          </div>
          <button
            type="button"
            onClick={handleToggleSave}
            aria-label={saved ? "Remove from saved" : "Save event"}
            className="absolute top-3 right-3 flex size-9 items-center justify-center rounded-full bg-background/90 text-foreground backdrop-blur transition-colors hover:bg-background"
            data-testid="opportunity-save-button"
          >
            <Bookmark className={cn("size-4", saved && "fill-foreground")} />
          </button>
        </div>

        <div className="flex flex-col gap-3 p-4">
          <div className="flex flex-col gap-1">
            <h3 className="text-pretty text-base font-semibold leading-tight">{event.name}</h3>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <span className="inline-flex items-center gap-1">
                <Calendar className="size-3.5" />
                {event.date}
              </span>
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3.5" />
                {event.city}, {event.state}
              </span>
            </div>
            <div className="flex items-center gap-1 text-sm text-muted-foreground">
              <Store className="size-3.5" />
              {event.venue} · {event.organizer}
            </div>
          </div>

          <div className="flex items-center justify-between rounded-xl bg-muted px-3 py-2 text-sm">
            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
              <Users className="size-4" />
              {event.expectedAttendance.toLocaleString()} expected
            </span>
            <span className="font-heading font-bold">
              {event.vendorFee == null ? "Fee unavailable" : event.vendorFee > 0 ? `From $${event.vendorFee}` : "Free"}
            </span>
          </div>

          <div className="flex flex-wrap gap-1.5">
            {event.categoriesNeeded.slice(0, 3).map((c) => (
              <Badge key={c} variant="secondary">
                {c}
              </Badge>
            ))}
            {event.categoriesNeeded.length > 3 && (
              <Badge variant="outline">+{event.categoriesNeeded.length - 3}</Badge>
            )}
          </div>

          <div className="flex items-center justify-between text-xs">
            <span
              className={cn(
                "inline-flex items-center gap-1 font-medium",
                full ? "text-destructive" : almostFull ? "text-warning" : "text-muted-foreground",
              )}
            >
              <Ticket className="size-3.5" />
              {full ? "Fully booked" : `${event.spotsRemaining} of ${event.totalSpots} spots left`}
            </span>
            <span className="inline-flex items-center gap-1 text-muted-foreground">
              <Clock className="size-3.5" />
              Apply by {event.deadline}
            </span>
          </div>

          <div className="flex gap-2">
            {liveApi && event.opportunityId ? (
              <Button
                className="flex-1"
                disabled={full && applyState.type !== "already_applied"}
                onClick={handlePrimaryAction}
                data-testid="opportunity-apply-button"
              >
                {applyState.type === "loading" && (
                  <Loader2 className="animate-spin" data-icon="inline-start" />
                )}
                {primaryLabel()}
              </Button>
            ) : (
              <ApplyModal event={event} open={applyOpen} onOpenChange={setApplyOpen}>
                <Button className="flex-1" disabled={full}>
                  {full ? "Waitlist full" : "Apply"}
                </Button>
              </ApplyModal>
            )}
            <Button
              variant="outline"
              size="icon"
              aria-label="Save"
              onClick={handleToggleSave}
              data-testid="opportunity-save-button-footer"
            >
              <Bookmark className={cn(saved && "fill-foreground")} />
            </Button>
          </div>
        </div>
      </Card>

      {liveApi && event.opportunityId && (
        <>
          <ApplyModal event={event} open={applyOpen} onOpenChange={setApplyOpen} />
          <Dialog open={infoOpen} onOpenChange={setInfoOpen}>
            <DialogContent className="sm:max-w-md">
              {applyState.type === "logged_out" && (
                <>
                  <DialogHeader>
                    <DialogTitle>Sign in to apply</DialogTitle>
                    <DialogDescription>
                      Apply for free. Pay only if accepted.
                    </DialogDescription>
                  </DialogHeader>
                  <div className="flex flex-col gap-2">
                    <Button nativeButton={false} render={<Link href="/login" />}>
                      Log in to apply
                    </Button>
                    <Button variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
                      Find more events
                    </Button>
                  </div>
                </>
              )}
              {applyState.type === "already_applied" && (
                <>
                  <DialogHeader>
                    <DialogTitle>You already applied</DialogTitle>
                    <DialogDescription>
                      {statusLabel
                        ? `Your application is ${statusLabel.toLowerCase()}.`
                        : "Track your application in Vendor requests."}
                    </DialogDescription>
                  </DialogHeader>
                  <div className="flex flex-col gap-2">
                    <Button onClick={handleAlreadyAppliedAction}>
                      {applyState.status === "payment_required" ||
                      applyState.status === "approved"
                        ? "Continue payment"
                        : applyState.status === "paid" || applyState.status === "completed"
                          ? "View event pass"
                          : "View vendor requests"}
                    </Button>
                    <Button variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
                      Find more events
                    </Button>
                  </div>
                </>
              )}
              {applyState.type === "marketplace_closed" && (
                <>
                  <DialogHeader>
                    <DialogTitle>Applications not open yet</DialogTitle>
                    <DialogDescription>{applyState.message}</DialogDescription>
                  </DialogHeader>
                  <Button variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
                    Find more events
                  </Button>
                </>
              )}
              {applyState.type === "blocked" && (
                <>
                  <DialogHeader>
                    <DialogTitle>
                      {applyState.code === "full" ? "Fully booked" : "Not accepting applications"}
                    </DialogTitle>
                    <DialogDescription>{applyState.reason}</DialogDescription>
                  </DialogHeader>
                  <Button variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
                    Find more events
                  </Button>
                </>
              )}
              {applyState.type === "error" && (
                <>
                  <DialogHeader>
                    <DialogTitle>Could not load apply status</DialogTitle>
                    <DialogDescription>{applyState.message}</DialogDescription>
                  </DialogHeader>
                  <Button variant="outline" onClick={() => setInfoOpen(false)}>
                    Try again
                  </Button>
                </>
              )}
            </DialogContent>
          </Dialog>
        </>
      )}

      <Dialog open={saveOpen} onOpenChange={setSaveOpen}>
        <DialogContent className="sm:max-w-md" data-testid="save-signin-dialog">
          <DialogHeader>
            <DialogTitle>Sign in to save events</DialogTitle>
            <DialogDescription>
              Saved opportunities will appear in your favorites.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Button nativeButton={false} render={<Link href={`/login?redirect=/vendor/find`} />}>
              Log in
            </Button>
            <Button variant="outline" onClick={() => setSaveOpen(false)}>
              Cancel
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
