"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { MailOpen } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { VendorInvite } from "@/lib/api/vendor-invites"
import type { PanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { EmptyState, PanelContent } from "@/components/marketplace/states"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"

function whereAndWhen(invite: VendorInvite): string {
  const date = invite.eventDate
    ? new Date(`${invite.eventDate}T12:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })
    : ""
  return [date, [invite.city, invite.state].filter(Boolean).join(", "), invite.opportunityTitle].filter(Boolean).join(" · ")
}

function expiresLabel(invite: VendorInvite): string {
  const at = invite.expiresAt ? Date.parse(`${invite.expiresAt.replace(" ", "T")}Z`) : Number.NaN
  return Number.isNaN(at) ? "" : `Expires ${new Date(at).toLocaleDateString(undefined, { month: "short", day: "numeric" })}`
}

export default function VendorInvitationsPage() {
  const live = isLiveApiEnabled()
  const router = useRouter()
  const [items, setItems] = useState<VendorInvite[]>([])
  const [state, setState] = useState<PanelState>(live ? "loading" : "empty")
  const [busyId, setBusyId] = useState<number | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!live) return
    setState("loading")
    try {
      const response = await fetch("/api/vendor/invites")
      const { data, error } = await parseApiResponse<{ items: VendorInvite[] }>(response)
      if (error) throw new Error(error.message)
      const next = data?.items ?? []
      setItems(next)
      setState(next.length ? "content" : "empty")
    } catch (err) {
      console.error("[VendorInvitations]", err)
      setState("error")
    }
  }, [live])

  useEffect(() => {
    void load()
  }, [load])

  async function respond(invite: VendorInvite, accept: boolean) {
    if (busyId) return
    if (!accept && !window.confirm(`Decline the invitation to ${invite.eventTitle || "this event"}?`)) return
    setBusyId(invite.id)
    setMessage(null)
    try {
      const response = await fetch(`/api/vendor/invites/${invite.id}/respond`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accept }),
      })
      const { data, error } = await parseApiResponse<{ ok: boolean; opportunityId: number | null }>(response)
      if (error) throw new Error(error.message)
      setItems((prev) => {
        const next = prev.filter((i) => i.id !== invite.id)
        if (!next.length) setState("empty")
        return next
      })
      const target = data?.opportunityId ?? invite.opportunityId
      if (accept && target) {
        router.push(`/vendor/find?opportunityId=${target}&apply=1`)
        return
      }
      setMessage(accept ? "Invitation accepted." : "Invitation declined.")
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "We couldn't update this invitation.")
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Invitations"
        subtitle="Organizers who want you at their events."
        action={
          <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/notifications" />}>
            Notifications
          </Button>
        }
      />
      {message ? (
        <p role="status" className="text-sm font-medium">
          {message}
        </p>
      ) : null}
      <PanelContent
        state={state}
        retry={() => void load()}
        empty={
          <EmptyState
            icon={MailOpen}
            title="No open invitations"
            description="Organizers can invite you when your profile matches their events. Keep your categories and markets up to date."
            action={
              <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/vendor/find" />}>
                Find events
              </Button>
            }
          />
        }
      >
        <div className="grid gap-4 md:grid-cols-2" data-testid="vendor-invitations">
          {items.map((invite) => {
            const expires = expiresLabel(invite)
            return (
              <article key={invite.id} className="flex flex-col gap-2 rounded-2xl border bg-card p-4" data-testid="vendor-invitation">
                <div className="flex items-start justify-between gap-2">
                  <h2 className="font-semibold">{invite.eventTitle || "Event invitation"}</h2>
                  {expires ? <Badge variant="outline">{expires}</Badge> : null}
                </div>
                <p className="text-sm text-muted-foreground">{whereAndWhen(invite)}</p>
                {invite.organizerName ? <p className="text-sm text-muted-foreground">Invited by {invite.organizerName}</p> : null}
                {invite.message ? <p className="text-sm">{invite.message}</p> : null}
                {invite.canRespond ? (
                  <div className="mt-2 flex gap-2">
                    <Button size="sm" disabled={busyId !== null} onClick={() => void respond(invite, true)}>
                      Accept &amp; apply
                    </Button>
                    <Button size="sm" variant="outline" disabled={busyId !== null} onClick={() => void respond(invite, false)}>
                      Decline
                    </Button>
                  </div>
                ) : null}
              </article>
            )
          })}
        </div>
      </PanelContent>
    </div>
  )
}
