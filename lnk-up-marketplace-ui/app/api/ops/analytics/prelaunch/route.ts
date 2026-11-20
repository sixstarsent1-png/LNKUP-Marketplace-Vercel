import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
import { getMockPrelaunch } from "@/lib/ops/mock-analytics"
import { probePrelaunchGate } from "@/lib/ops/wp-adapter"
import type { PrelaunchStatus } from "@/lib/ops/types"

function launchLabel(date: string): string {
  try {
    return new Date(date).toLocaleDateString("en-US", {
      month: "long",
      day: "numeric",
      year: "numeric",
    })
  } catch {
    return date
  }
}

async function probeSearch(): Promise<boolean> {
  const origin = getOrigin()
  try {
    const res = await fetch(`${origin}/api/marketplace/search`, { cache: "no-store" })
    return res.status === 200
  } catch {
    return false
  }
}

function getOrigin(): string {
  if (process.env.PLAYWRIGHT_APP_URL) return process.env.PLAYWRIGHT_APP_URL.replace(/\/$/, "")
  if (process.env.VERCEL_URL) return `https://${process.env.VERCEL_URL}`
  return "http://127.0.0.1:3000"
}

export async function GET() {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const launchDate =
    process.env.NEXT_PUBLIC_LNKUP_LAUNCH_DATE?.trim() || "2026-08-01"

  if (!isLiveApiEnabled()) {
    return NextResponse.json(getMockPrelaunch())
  }

  const gate = await probePrelaunchGate()
  const canSearch = await probeSearch()

  const status: PrelaunchStatus = {
    launchDate,
    launchLabel: launchLabel(launchDate),
    isPrelaunchMode: gate.isPrelaunchMode,
    canSearch,
    canApplySubmit: gate.canApplySubmit,
    isApplyContextGated: gate.isApplyContextGated,
    recommendedAction: gate.isPrelaunchMode
      ? `Search is live, but apply submit may be gated until ${launchLabel(launchDate)}. Use LNKup staging or admin preview to test full apply E2E.`
      : "Marketplace apply flow is open. Run full vendor apply E2E in production or staging.",
  }

  return NextResponse.json(status)
}
