"use client"

import { Suspense, use } from "react"
import Link from "next/link"
import { notFound } from "next/navigation"
import { ArrowLeft } from "lucide-react"
import { events, vendorRequests } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { EventPassCard } from "@/components/marketplace/event-pass-card"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"

function EventPassContent({ eventId }: { eventId: string }) {
  const { state, retry } = usePanelState()
  const event = events.find((e) => e.id === eventId)
  if (!event) notFound()

  const request = vendorRequests.find((r) => r.eventId === eventId)
  const locked = request ? request.status !== "paid" : true

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-4">
        <Button
          variant="ghost"
          size="sm"
          className="w-fit -ml-2"
          nativeButton={false}
          render={<Link href="/vendor/requests" />}
        >
          <ArrowLeft data-icon="inline-start" />
          Back to requests
        </Button>
        <h1 className="font-heading text-2xl font-bold tracking-tight">Event Pass</h1>
        <EventPassCard event={event} locked={locked} />
      </div>
    </PanelContent>
  )
}

export default function EventPassPage({
  params,
}: {
  params: Promise<{ eventId: string }>
}) {
  const { eventId } = use(params)

  return (
    <Suspense fallback={<SkeletonList count={1} />}>
      <EventPassContent eventId={eventId} />
    </Suspense>
  )
}
