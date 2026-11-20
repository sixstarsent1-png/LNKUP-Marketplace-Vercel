"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { Copy, Check, ExternalLink, Share2 } from "lucide-react"
import type { OrganizerEventWorkspace } from "@/lib/api/organizer-event-workspace"
import { buildShareLink } from "@/lib/api/organizer-event-workspace"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerShareLink } from "@/lib/api/organizer-event-workspace"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"

function absoluteShareUrl(share: OrganizerShareLink): string {
  const link = share.publicUrl || share.applyUrl || share.applicationUrl || share.opportunityUrl || ""
  if (!link) return ""
  if (link.startsWith("http")) return link
  if (typeof window !== "undefined") return `${window.location.origin}${link}`
  return link
}

export function ShareApplicationPanel({ workspace }: { workspace: OrganizerEventWorkspace }) {
  const [share, setShare] = useState<OrganizerShareLink | null>(null)
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    async function load() {
      if (!isLiveApiEnabled()) {
        setShare(buildShareLink(workspace))
        return
      }
      const response = await fetch(`/api/organizer/events/${workspace.eventId}/share`)
      const { data } = await parseApiResponse<OrganizerShareLink>(response)
      setShare(data ?? buildShareLink(workspace))
    }
    load()
  }, [workspace])

  async function handleCopy() {
    const url = share ? absoluteShareUrl(share) : ""
    if (!url || !share) return
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      setCopied(false)
    } finally {
      trackLnkupEvent({
        event: "share_link_copied",
        source: "event_workspace",
        eventId: workspace.eventId,
        opportunityId: share.opportunityId,
      })
      trackLnkupEvent({
        event: "application_link_copied",
        source: "event_workspace",
        eventId: workspace.eventId,
        opportunityId: share.opportunityId,
      })
    }
  }

  async function handleShare() {
    const url = share ? absoluteShareUrl(share) : ""
    if (!url || !share) return
    const title = share.title || workspace.title
    if (typeof navigator !== "undefined" && typeof navigator.share === "function") {
      try {
        await navigator.share({
          title,
          text: `Apply to ${title} on LNKup`,
          url,
        })
        trackLnkupEvent({
          event: "share_link_copied",
          source: "event_workspace_share",
          eventId: workspace.eventId,
          opportunityId: share.opportunityId,
        })
        return
      } catch {
        // fall through to copy
      }
    }
    await handleCopy()
  }

  if (!share) return null

  if (share.status === "missing_opportunity") {
    return (
      <Card className="rounded-2xl" data-testid="share-missing-opportunity">
        <CardContent className="py-6 text-sm text-muted-foreground">
          Create an opportunity first to get a share link.
        </CardContent>
      </Card>
    )
  }

  if (share.status === "unavailable") {
    return (
      <Card className="rounded-2xl">
        <CardContent className="py-6 text-sm text-muted-foreground">
          Application link is not available yet.
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="rounded-2xl" data-testid="share-application-panel">
      <CardHeader>
        <CardTitle className="text-base">Share event</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3 text-sm">
        <p className="font-medium">{share.title}</p>
        {workspace.opportunities[0] && (
          <p className="text-muted-foreground">
            {workspace.opportunities[0].categories?.join(", ")} · $
            {workspace.opportunities[0].vendorFee} · {workspace.city}, {workspace.state}
          </p>
        )}
        <p className="break-all text-xs text-muted-foreground">{absoluteShareUrl(share)}</p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" onClick={() => void handleShare()} data-testid="share-application-link">
            <Share2 data-icon="inline-start" />
            Share
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => void handleCopy()}
            data-testid="copy-application-link"
          >
            {copied ? <Check data-icon="inline-start" /> : <Copy data-icon="inline-start" />}
            {copied ? "Copied" : "Copy link"}
          </Button>
          {share.previewUrl && (
            <Button
              size="sm"
              variant="outline"
              nativeButton={false}
              render={<Link href={share.opportunityUrl ?? share.previewUrl} target="_blank" />}
              onClick={() =>
                trackLnkupEvent({
                  event: "opportunity_preview_clicked",
                  source: "event_workspace",
                  eventId: workspace.eventId,
                })
              }
            >
              Preview as vendor
              <ExternalLink data-icon="inline-end" />
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
