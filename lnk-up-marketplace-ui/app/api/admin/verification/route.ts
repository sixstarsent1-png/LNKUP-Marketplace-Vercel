import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import {
  getMockVerificationItems,
  handoffUrls,
  liveMeta,
  mapVerificationRow,
  mockMeta,
} from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type WpSummary = {
  queue_count?: number
  approved_24h?: number
}

type WpQueue = {
  items?: Array<Record<string, unknown>>
  total?: number
}

export async function GET() {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    const handoff = handoffUrls()

    if (!isLiveApiEnabled()) {
      const items = getMockVerificationItems()
      return NextResponse.json({
        code: "ok",
        meta: mockMeta(),
        stats: { queueCount: items.length, approved24h: 3 },
        items,
        handoffUrl: handoff.verificationUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const [summary, queue] = await Promise.all([
      wpFetchJson<WpSummary>("/verification/admin/summary", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpQueue>("/verification/admin/queue", { auth: wpAuth }).catch(() => null),
    ])

    const reasons: string[] = []
    if (!summary) reasons.push("Verification summary unavailable.")
    if (!queue) reasons.push("Verification queue unavailable.")

    const items = (queue?.items ?? [])
      .map((row) => mapVerificationRow(row))
      .filter((row): row is NonNullable<typeof row> => Boolean(row))

    return NextResponse.json({
      code: reasons.length > 0 ? "admin_verification_partial" : "ok",
      meta: liveMeta(reasons),
      stats: {
        queueCount: summary?.queue_count ?? items.length,
        approved24h: summary?.approved_24h ?? 0,
      },
      items,
      handoffUrl: handoff.verificationUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_verification",
      fallbackMessage: "We couldn't load verification summary.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
