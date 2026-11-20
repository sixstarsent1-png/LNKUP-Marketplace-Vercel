"use client"

import Image from "next/image"
import { cn } from "@/lib/utils"
import {
  CalendarDays,
  MapPin,
  Users,
  Clock,
  Bookmark,
  ChevronRight,
} from "lucide-react"
import {
  Card,
  Pill,
  StatusBadge,
  VerificationBadge,
} from "./primitives"
import { Rating } from "./ui-kit"
import {
  type EventItem,
  type Vendor,
  type VendorRequest,
  formatCurrency,
} from "@/lib/data"

/* ---------- Opportunity card (vendor find events) ---------- */
export function OpportunityCard({
  event,
  saved,
  onSave,
  onApply,
  priority,
}: {
  event: EventItem
  saved?: boolean
  onSave?: () => void
  onApply?: () => void
  priority?: boolean
}) {
  return (
    <Card className="overflow-hidden">
      <div className="relative h-40 w-full">
        <Image
          src={event.image || "/placeholder.svg"}
          alt={event.name}
          fill
          priority={priority}
          className="object-cover"
          sizes="(max-width: 640px) 100vw, 400px"
        />
        <div className="absolute inset-x-0 top-0 flex items-start justify-between p-3">
          <div className="flex gap-2">
            {event.verified ? <VerificationBadge /> : null}
            {event.trending ? (
              <Pill className="bg-lime text-lime-foreground">Trending</Pill>
            ) : null}
          </div>
          <button
            onClick={onSave}
            aria-label={saved ? "Remove from saved" : "Save event"}
            className={cn(
              "flex size-8 items-center justify-center rounded-full backdrop-blur transition-colors",
              saved
                ? "bg-lime text-lime-foreground"
                : "bg-background/80 text-foreground",
            )}
          >
            <Bookmark className={cn("size-4", saved && "fill-current")} />
          </button>
        </div>
        <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-foreground/70 to-transparent p-3">
          <span className="inline-flex items-center gap-1 rounded-full bg-background/90 px-2 py-1 text-xs font-semibold text-foreground">
            <Clock className="size-3" />
            {event.deadline}
          </span>
        </div>
      </div>

      <div className="p-4">
        <h3 className="font-heading text-base font-bold leading-tight text-balance">
          {event.name}
        </h3>
        <div className="mt-2 space-y-1.5 text-sm text-muted-foreground">
          <div className="flex items-center gap-1.5">
            <CalendarDays className="size-4 shrink-0" />
            {event.date}
          </div>
          <div className="flex items-center gap-1.5">
            <MapPin className="size-4 shrink-0" />
            {event.venue} · {event.city}, {event.state}
          </div>
          <div className="flex items-center gap-1.5">
            <Users className="size-4 shrink-0" />
            {event.attendance.toLocaleString()} expected · {event.organizer}
          </div>
        </div>

        <div className="mt-3 flex flex-wrap gap-1.5">
          {event.categories.slice(0, 3).map((c) => (
            <Pill key={c}>{c}</Pill>
          ))}
          {event.categories.length > 3 ? (
            <Pill>+{event.categories.length - 3}</Pill>
          ) : null}
        </div>

        <div className="mt-4 flex items-end justify-between border-t border-border pt-3">
          <div>
            <div className="font-heading text-xl font-bold">
              {formatCurrency(event.fee)}
            </div>
            <div className="text-xs text-muted-foreground">
              vendor fee · {event.spotsRemaining} spots left
            </div>
          </div>
          <button
            onClick={onApply}
            className="rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background transition-transform active:scale-95"
          >
            Apply
          </button>
        </div>
      </div>
    </Card>
  )
}

/* ---------- Compact event card (organizer events) ---------- */
export function EventCard({
  event,
  requests,
  approved,
  paid,
  revenue,
  readiness,
  onManage,
}: {
  event: EventItem
  requests: number
  approved: number
  paid: number
  revenue: number
  readiness: number
  onManage?: () => void
}) {
  return (
    <Card className="overflow-hidden">
      <div className="flex gap-3 p-3">
        <div className="relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl">
          <Image
            src={event.image || "/placeholder.svg"}
            alt={event.name}
            fill
            className="object-cover"
            sizes="96px"
          />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="truncate font-heading text-base font-bold">
              {event.name}
            </h3>
          </div>
          <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
            <CalendarDays className="size-3.5" />
            {event.shortDate}
            <span className="mx-1">·</span>
            <MapPin className="size-3.5" />
            {event.city}, {event.state}
          </div>
          <div className="mt-2 flex items-center gap-2">
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-secondary">
              <div
                className="h-full rounded-full bg-lime"
                style={{ width: `${readiness}%` }}
              />
            </div>
            <span className="text-xs font-semibold text-muted-foreground">
              {readiness}% ready
            </span>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-4 gap-px overflow-hidden border-y border-border bg-border text-center">
        {[
          { label: "Requests", value: requests },
          { label: "Approved", value: approved },
          { label: "Paid", value: paid },
          { label: "Revenue", value: formatCurrency(revenue) },
        ].map((s) => (
          <div key={s.label} className="bg-card px-1 py-2.5">
            <div className="font-heading text-sm font-bold">{s.value}</div>
            <div className="text-[10px] uppercase tracking-wide text-muted-foreground">
              {s.label}
            </div>
          </div>
        ))}
      </div>

      <div className="p-3">
        <button
          onClick={onManage}
          className="flex w-full items-center justify-center gap-1 rounded-full bg-secondary py-2.5 text-sm font-semibold text-foreground hover:bg-secondary/70"
        >
          Manage Event
          <ChevronRight className="size-4" />
        </button>
      </div>
    </Card>
  )
}

/* ---------- Vendor card ---------- */
export function VendorCard({
  vendor,
  primaryAction,
  onPrimary,
  onView,
}: {
  vendor: Vendor
  primaryAction?: string
  onPrimary?: () => void
  onView?: () => void
}) {
  return (
    <Card className="p-4">
      <div className="flex items-start gap-3">
        <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-foreground font-heading text-base font-bold text-background">
          {vendor.name
            .split(" ")
            .slice(0, 2)
            .map((w) => w[0])
            .join("")}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <h3 className="truncate font-heading text-base font-bold">
              {vendor.name}
            </h3>
            {vendor.verified ? (
              <VerificationBadge label="" className="px-1 py-1" />
            ) : null}
          </div>
          <p className="text-sm text-muted-foreground">{vendor.category}</p>
          <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
            <span className="flex items-center gap-1">
              <MapPin className="size-3.5" />
              {vendor.city}, {vendor.state}
            </span>
            <Rating value={vendor.rating} />
            <span>{vendor.completedEvents} events</span>
          </div>
        </div>
      </div>
      <div className="mt-3 flex gap-2">
        <button
          onClick={onView}
          className="flex-1 rounded-full border border-border py-2 text-sm font-semibold text-foreground hover:bg-secondary"
        >
          View Profile
        </button>
        {primaryAction ? (
          <button
            onClick={onPrimary}
            className="flex-1 rounded-full bg-foreground py-2 text-sm font-semibold text-background active:scale-95"
          >
            {primaryAction}
          </button>
        ) : null}
      </div>
    </Card>
  )
}

/* ---------- Request card (vendor requests) ---------- */
export function RequestCard({
  request,
  onAction,
}: {
  request: VendorRequest
  onAction?: () => void
}) {
  const cta =
    request.status === "payment_due"
      ? "Pay Now"
      : request.status === "paid"
        ? "View Pass"
        : request.status === "rejected"
          ? "See Reason"
          : "View"

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="truncate font-heading text-base font-bold">
            {request.eventName}
          </h3>
          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span className="flex items-center gap-1">
              <CalendarDays className="size-3.5" />
              {request.shortDate}
            </span>
            <span className="flex items-center gap-1">
              <MapPin className="size-3.5" />
              {request.city}, {request.state}
            </span>
            <span>{request.category}</span>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            {request.organizer} · applied {request.appliedOn}
          </p>
        </div>
        <StatusBadge status={request.status} />
      </div>
      <div className="mt-3 flex items-center justify-between border-t border-border pt-3">
        <div className="text-sm">
          <span className="font-heading font-bold">
            {formatCurrency(request.fee)}
          </span>
          <span className="text-muted-foreground"> vendor fee</span>
        </div>
        <button
          onClick={onAction}
          className={cn(
            "rounded-full px-4 py-2 text-sm font-semibold active:scale-95",
            request.status === "payment_due"
              ? "bg-lime text-lime-foreground"
              : "bg-secondary text-foreground hover:bg-secondary/70",
          )}
        >
          {cta}
        </button>
      </div>
    </Card>
  )
}
