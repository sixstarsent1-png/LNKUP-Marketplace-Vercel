"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { FileClock } from "lucide-react"
import { applyRequest } from "@/lib/api/apply-client"
import { type DraftCard, type WpDraftCard, mapDraftCard } from "@/lib/api/application-drafts"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"

type LoadState = { kind: "loading" } | { kind: "error"; message: string } | { kind: "ready"; drafts: DraftCard[] }

/**
 * Applications the vendor started on any device and has not submitted. Hidden when there are none.
 */
export function DraftResumeList() {
  const [state, setState] = useState<LoadState>({ kind: "loading" })
  const [discarding, setDiscarding] = useState<number | null>(null)

  const load = useCallback(async () => {
    setState({ kind: "loading" })
    const result = await applyRequest<{ items?: WpDraftCard[] }>("/api/applications/drafts")
    if (!result.ok) {
      setState({ kind: "error", message: result.message })
      return
    }
    setState({ kind: "ready", drafts: (result.data?.items ?? []).map(mapDraftCard) })
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const handleDiscard = useCallback(
    async (draft: DraftCard) => {
      setDiscarding(draft.id)
      const result = await applyRequest(`/api/applications/drafts/${draft.opportunityId}`, { method: "DELETE" })
      setDiscarding(null)
      if (!result.ok) {
        setState({ kind: "error", message: result.message })
        return
      }
      void load()
    },
    [load],
  )

  if (state.kind === "loading") {
    return <Skeleton className="h-20 w-full rounded-2xl" aria-label="Loading saved applications" />
  }

  if (state.kind === "error") {
    return (
      <Card className="flex flex-row items-center justify-between gap-3 rounded-2xl p-4">
        <p className="text-sm text-muted-foreground">Saved applications couldn&apos;t load. {state.message}</p>
        <Button variant="outline" size="sm" onClick={() => void load()}>
          Try again
        </Button>
      </Card>
    )
  }

  if (state.drafts.length === 0) return null

  return (
    <section aria-labelledby="draft-resume-heading" className="flex flex-col gap-3">
      <h2 id="draft-resume-heading" className="font-display text-lg font-semibold">
        Finish your applications
      </h2>
      {state.drafts.map((draft) => (
        <DraftResumeCard
          key={draft.id}
          draft={draft}
          isDiscarding={discarding === draft.id}
          onDiscard={handleDiscard}
        />
      ))}
    </section>
  )
}

function DraftResumeCard({
  draft,
  isDiscarding,
  onDiscard,
}: {
  draft: DraftCard
  isDiscarding: boolean
  onDiscard: (draft: DraftCard) => void
}) {
  const details = [draft.boothName, draft.eventDate, draft.location].filter(Boolean).join(" · ")
  return (
    <Card className="flex flex-col gap-3 rounded-2xl p-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-0 items-start gap-3">
        <FileClock className="mt-0.5 size-5 shrink-0 text-muted-foreground" aria-hidden />
        <div className="min-w-0">
          <p className="truncate font-medium">{draft.eventName}</p>
          {details ? <p className="truncate text-sm text-muted-foreground">{details}</p> : null}
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground">{draft.progress}% done</span>
            {draft.deadline ? <span className="text-xs text-muted-foreground">Apply by {draft.deadline}</span> : null}
            {draft.priceChanged ? <Badge variant="outline">Price changed</Badge> : null}
            {draft.blockedLabel ? <Badge variant="secondary">{draft.blockedLabel}</Badge> : null}
          </div>
        </div>
      </div>
      <div className="flex shrink-0 gap-2">
        <Button variant="ghost" size="sm" disabled={isDiscarding} onClick={() => onDiscard(draft)}>
          {isDiscarding ? "Discarding…" : "Discard"}
        </Button>
        {draft.canSubmit ? (
          <Button size="sm" nativeButton={false} render={<Link href={draft.resumeHref} />}>
            Resume
          </Button>
        ) : null}
      </div>
    </Card>
  )
}
