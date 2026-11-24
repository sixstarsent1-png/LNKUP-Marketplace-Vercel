"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { BadgeCheck } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { hasOpenSlots, openCategoryLabels, type EventFulfillment, type EventVendorMatchesResult, type MatchedVendor } from "@/lib/api/match"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { useOrganizerEvents } from "@/lib/hooks/use-organizer-events"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

type CardState =
  | { status: "loading" }
  | { status: "empty" }
  | { status: "error" }
  | { status: "ready"; fulfillment: EventFulfillment; vendors: MatchedVendor[] }

function actionHref(f: EventFulfillment): string {
  const view = f.actions[0]?.view
  if (view === "applications") return `/organizer/vendors?event=${f.eventId}`
  if (view === "events" || view === "operations") return `/organizer/events/${f.eventId}`
  return `/organizer/vendors?tab=find&event=${f.eventId}`
}

/**
 * "Vendor slots to fill" for the soonest upcoming event that still has open slots: remaining spots
 * by category, the most useful next action, and up to three recommended vendors.
 * Hidden when every slot is filled or nothing is accepting vendors.
 */
export function FillSlotsCard({ eventIds }: { eventIds?: string[] }) {
  const live = isLiveApiEnabled()
  const { items: events, loading: eventsLoading } = useOrganizerEvents(live && !eventIds)
  const candidates = useMemo(
    () => eventIds ?? events.filter((e) => e.status !== "draft" && e.status !== "past").map((e) => e.id),
    [eventIds, events],
  )
  const [state, setState] = useState<CardState>({ status: "loading" })
  const key = candidates.slice(0, 3).join(",")

  const load = useCallback(async () => {
    if (!live || !key) {
      setState({ status: "empty" })
      return
    }
    setState({ status: "loading" })
    try {
      let fulfillment: EventFulfillment | null = null
      for (const id of key.split(",")) {
        const response = await fetch(`/api/organizer/events/${encodeURIComponent(id)}/fulfillment`)
        const { data, error } = await parseApiResponse<EventFulfillment>(response)
        if (error) throw new Error(error.message)
        if (data && hasOpenSlots(data)) {
          fulfillment = data
          break
        }
      }
      if (!fulfillment) {
        setState({ status: "empty" })
        return
      }
      let vendors: MatchedVendor[] = []
      const matches = await fetch(`/api/match/organizer/events/${encodeURIComponent(fulfillment.eventId)}/vendors?per_page=3&exclude_applied=1`)
        .then((r) => parseApiResponse<EventVendorMatchesResult>(r))
        .catch(() => null)
      if (matches?.data?.status === "ready") vendors = matches.data.items.slice(0, 3)
      setState({ status: "ready", fulfillment, vendors })
    } catch (err) {
      console.error("[FillSlotsCard]", err)
      setState({ status: "error" })
    }
  }, [key, live])

  useEffect(() => {
    if (!eventIds && eventsLoading) return
    void load()
  }, [eventIds, eventsLoading, load])

  if (state.status === "empty") return null
  if (state.status === "loading") {
    return <div className="h-28 animate-pulse rounded-2xl bg-muted" aria-label="Loading vendor slots" />
  }
  if (state.status === "error") {
    return (
      <Card className="rounded-2xl">
        <CardContent className="flex items-center justify-between gap-3 py-4">
          <p className="text-sm text-muted-foreground">We couldn&apos;t load your open vendor slots.</p>
          <Button size="sm" variant="outline" onClick={() => void load()}>
            Try again
          </Button>
        </CardContent>
      </Card>
    )
  }

  const { fulfillment, vendors } = state
  const categories = openCategoryLabels(fulfillment)
  const action = fulfillment.actions[0]
  const findHref = `/organizer/vendors?tab=find&event=${fulfillment.eventId}`
  return (
    <Card className="rounded-2xl border-l-4 border-l-primary" data-testid="fill-slots-card">
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex items-start justify-between gap-2">
          <div>
            <p className="font-semibold">Vendor slots to fill</p>
            <p className="text-sm text-muted-foreground">
              {fulfillment.remaining > 0
                ? `${fulfillment.remaining} vendor spot${fulfillment.remaining === 1 ? "" : "s"} still open at ${fulfillment.eventTitle}`
                : `${fulfillment.eventTitle} is accepting vendor applications`}
            </p>
          </div>
          <Button size="sm" variant="outline" nativeButton={false} render={<Link href={findHref} />}>
            Find vendors
          </Button>
        </div>
        {categories.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {categories.slice(0, 6).map((label) => (
              <Badge key={label} variant="secondary">
                {label}
              </Badge>
            ))}
          </div>
        )}
        {action && (
          <Link href={actionHref(fulfillment)} className="text-sm font-medium hover:underline">
            {action.label} <span className="font-semibold">{action.cta} →</span>
          </Link>
        )}
        {vendors.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Recommended vendors</p>
            {vendors.map((vendor) => (
              <div key={vendor.profileId} className="flex items-center gap-3">
                {vendor.logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={vendor.logo} alt="" className="size-9 rounded-lg object-cover" />
                ) : (
                  <span className="flex size-9 items-center justify-center rounded-lg bg-muted text-xs font-semibold">
                    {vendor.businessName.slice(0, 2).toUpperCase()}
                  </span>
                )}
                <div className="min-w-0">
                  <p className="flex items-center gap-1 truncate text-sm font-semibold">
                    {vendor.businessName}
                    {vendor.verified && <BadgeCheck className="size-4" aria-label="Verified" />}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {[vendor.primaryCategory, [vendor.city, vendor.state].filter(Boolean).join(", "), vendor.reasons[0]].filter(Boolean).join(" · ")}
                  </p>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
