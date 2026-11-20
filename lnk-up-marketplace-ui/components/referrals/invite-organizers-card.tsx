"use client"

import { useState } from "react"
import { Gift, Copy, Check, Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Surface } from "@/components/vendor/surface"
import type { OrganizerRecruitState } from "@/lib/api/organizer-recruit-referrals"
import { cn } from "@/lib/utils"

function money(n: number) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: "USD",
    }).format(n || 0)
  } catch {
    return `$${(n || 0).toFixed(2)}`
  }
}

/**
 * Referral Invites card (12-month LNKup fee share).
 * Distinct from peer vendor Referral Credits and CRM vendor invites.
 */
export function InviteOrganizersCard({
  state,
  loading,
  onEnsureCode,
}: {
  state: OrganizerRecruitState | null
  loading?: boolean
  onEnsureCode?: () => Promise<unknown>
}) {
  const [copied, setCopied] = useState<"organizer" | "vendor" | null>(null)
  const [busy, setBusy] = useState(false)

  if (loading) {
    return (
      <Surface
        variant="primary"
        className="flex gap-4"
        data-testid="invite-organizers-card"
      >
        <Skeleton className="size-11 shrink-0 rounded-full" />
        <div className="flex flex-1 flex-col gap-2">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-3 w-full" />
          <Skeleton className="h-9 w-40" />
        </div>
      </Surface>
    )
  }

  if (!state) return null

  async function handleCopy(kind: "organizer" | "vendor") {
    const text =
      kind === "vendor"
        ? state?.shareUrlVendor || ""
        : state?.shareUrlOrganizer || state?.shareUrl || ""
    if (!text) return
    try {
      await navigator.clipboard.writeText(text)
      setCopied(kind)
      setTimeout(() => setCopied(null), 2000)
    } catch {
      /* ignore */
    }
  }

  async function handleEnsure() {
    if (!onEnsureCode) return
    setBusy(true)
    try {
      await onEnsureCode()
    } finally {
      setBusy(false)
    }
  }

  const hasLinks = Boolean(state.shareUrlOrganizer || state.shareUrlVendor || state.shareUrl)
  const title =
    state.headline?.split("—")[0]?.trim() || "Invite organizers or vendors"

  return (
    <Surface
      variant="primary"
      className={cn(
        "flex flex-col gap-4 border-zinc-900 bg-zinc-950 text-white shadow-none",
        "ring-0",
      )}
      data-testid="invite-organizers-card"
    >
      <div className="flex gap-3 sm:items-start">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-lime-400/20">
          <Gift className="size-5 text-lime-300" aria-hidden />
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <p className="text-xs font-bold uppercase tracking-wider text-lime-300">
            Referral Invites
          </p>
          <h3 className="font-heading text-base font-semibold leading-snug text-white">
            {title}
          </h3>
          <p className="line-clamp-2 text-sm text-zinc-300">
            {state.message ||
              "Earn 20% of LNKup fees for 12 months when they book or collect booth payments."}
          </p>
          {state.available ? (
            <p className="text-xs text-zinc-400">
              {money(state.earningsTotal)} earned · {state.activeCount} active
              {state.code ? ` · Code ${state.code}` : ""}
            </p>
          ) : null}
        </div>
      </div>

      {state.available && hasLinks ? (
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            className="bg-lime-400 text-zinc-950 hover:bg-lime-300"
            onClick={() => void handleCopy("organizer")}
            data-testid="invite-organizers-copy-organizer"
          >
            {copied === "organizer" ? (
              <Check data-icon="inline-start" />
            ) : (
              <Copy data-icon="inline-start" />
            )}
            {copied === "organizer" ? "Copied" : "Copy organizer link"}
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="border-zinc-600 bg-transparent text-white hover:bg-zinc-800 hover:text-white"
            onClick={() => void handleCopy("vendor")}
            data-testid="invite-organizers-copy-vendor"
          >
            {copied === "vendor" ? (
              <Check data-icon="inline-start" />
            ) : (
              <Copy data-icon="inline-start" />
            )}
            {copied === "vendor" ? "Copied" : "Copy vendor link"}
          </Button>
        </div>
      ) : onEnsureCode ? (
        <Button
          size="sm"
          className="w-fit bg-lime-400 text-zinc-950 hover:bg-lime-300"
          onClick={handleEnsure}
          disabled={busy}
          data-testid="invite-organizers-generate"
        >
          {busy && <Loader2 className="animate-spin" data-icon="inline-start" />}
          Get invite links
        </Button>
      ) : null}
    </Surface>
  )
}
