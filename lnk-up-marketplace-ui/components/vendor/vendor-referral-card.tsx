"use client"

import { useState } from "react"
import { Users, Copy, Check, Loader2 } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import type { VendorReferralState } from "@/lib/api/vendor-referrals"

export function VendorReferralCard({
  state,
  loading,
  onGenerate,
}: {
  state: VendorReferralState | null
  loading?: boolean
  onGenerate?: () => Promise<unknown>
}) {
  const [copied, setCopied] = useState(false)
  const [generating, setGenerating] = useState(false)

  if (loading) {
    return (
      <Card className="rounded-2xl" data-testid="vendor-referral-card">
        <CardContent className="flex gap-4 py-5">
          <Skeleton className="size-11 rounded-full" />
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-4 w-1/2" />
            <Skeleton className="h-3 w-full" />
          </div>
        </CardContent>
      </Card>
    )
  }

  if (!state) return null

  const displayMessage = state.message ?? "Help more small businesses find event opportunities."

  async function handleCopy() {
    if (!state?.shareUrl && !state?.code) return
    const text = state.shareUrl || state.code || ""
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      /* ignore */
    }
  }

  async function handleGenerate() {
    if (!onGenerate) return
    setGenerating(true)
    try {
      await onGenerate()
    } finally {
      setGenerating(false)
    }
  }

  return (
    <Card className="rounded-2xl" data-testid="vendor-referral-card">
      <CardContent className="flex flex-col gap-4 py-5 sm:flex-row sm:items-center">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent">
          <Users className="size-5 text-accent-foreground" />
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <h3 className="font-heading font-semibold">Invite another vendor</h3>
          <p className="text-sm text-muted-foreground">
            Peer vendor credits — separate from Referral Invites (recruit organizers).
          </p>
          <p className="text-sm text-muted-foreground">{displayMessage}</p>
          {state.available && state.rewardsPending && (
            <p className="text-xs text-muted-foreground">
              Referral rewards unlock after paid bookings.
            </p>
          )}
        </div>
        {state.available && (state.shareUrl || state.code) ? (
          <Button variant="outline" size="sm" onClick={handleCopy}>
            {copied ? <Check data-icon="inline-start" /> : <Copy data-icon="inline-start" />}
            {copied ? "Copied" : "Copy link"}
          </Button>
        ) : onGenerate ? (
          <Button size="sm" onClick={handleGenerate} disabled={generating}>
            {generating && <Loader2 className="animate-spin" data-icon="inline-start" />}
            Get invite link
          </Button>
        ) : null}
      </CardContent>
    </Card>
  )
}
