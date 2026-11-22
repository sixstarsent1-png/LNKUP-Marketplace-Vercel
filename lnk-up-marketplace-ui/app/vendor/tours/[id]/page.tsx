"use client"

import { use, useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { MapPin, Route } from "lucide-react"
import type { TourDetail } from "@/lib/api/match"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { PageHeader } from "@/components/marketplace/page-header"
import { EmptyState, SkeletonList } from "@/components/marketplace/states"
import { TourMultiApply } from "@/components/marketplace/tour-multi-apply"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export default function VendorTourPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params)
  const [tour, setTour] = useState<TourDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const response = await fetch(`/api/tours/${encodeURIComponent(id)}`)
      const { data, error: apiError } = await parseApiResponse<{ tour: TourDetail }>(response)
      if (apiError || !data) throw new Error(apiError?.message ?? "We couldn't load this tour.")
      setTour(data.tour)
    } catch (err) {
      console.error("[VendorTourPage]", err)
      setError(err instanceof Error ? err.message : "We couldn't load this tour.")
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  if (loading) return <SkeletonList count={3} />
  if (error || !tour) {
    return (
      <EmptyState
        icon={Route}
        title="Tour unavailable"
        description={error ?? "This tour is not available."}
        action={
          <Button size="sm" variant="outline" onClick={() => void load()}>
            Try again
          </Button>
        }
      />
    )
  }

  return (
    <div className="flex flex-col gap-4" data-testid="vendor-tour-page">
      <PageHeader
        title={tour.title}
        subtitle={`${tour.type === "series" ? "Recurring market" : "Tour"}${tour.organizerName ? ` · by ${tour.organizerName}` : ""}`}
      />
      {tour.matchingStops > 0 && (
        <p className="text-sm font-medium">
          {tour.matchingStops} stop{tour.matchingStops === 1 ? "" : "s"} match your markets
        </p>
      )}
      {tour.description && <p className="text-sm text-muted-foreground">{tour.description}</p>}
      <p className="text-xs text-muted-foreground">
        Each stop has its own application, booth inventory and payment. Applying to one stop doesn&apos;t apply you to the
        others.
      </p>
      <TourMultiApply tourId={id} onSubmitted={() => void load()} />
      <div className="grid gap-3 md:grid-cols-2">
        {tour.stops.map((stop) => (
          <Card key={stop.eventId} className={`rounded-2xl ${stop.matchesMe ? "border-primary" : ""}`} data-testid="tour-stop">
            <CardContent className="flex flex-col gap-2 py-4">
              <p className="flex items-center gap-1 text-sm font-semibold">
                <MapPin className="size-4" />
                {stop.place || stop.title}
              </p>
              <p className="text-xs text-muted-foreground">
                {stop.date || "Date TBA"}
                {stop.feeLabel ? ` · ${stop.feeLabel}` : ""}
              </p>
              <div className="flex flex-wrap gap-2">
                <Badge variant={stop.accepting ? "default" : "outline"}>
                  {stop.accepting ? "Accepting vendors" : "Not accepting"}
                </Badge>
                {stop.matchesMe && <Badge variant="outline">Matches you</Badge>}
              </div>
              {stop.applicationStatus ? (
                <p className="text-sm">Application: {stop.applicationStatus.replace(/_/g, " ")}</p>
              ) : stop.openOpportunityId ? (
                <Button
                  size="sm"
                  nativeButton={false}
                  render={<Link href={`/vendor/find?opportunityId=${stop.openOpportunityId}&apply=1`} />}
                >
                  Apply to this stop
                </Button>
              ) : null}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
