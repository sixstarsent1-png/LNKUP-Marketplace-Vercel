import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import {
  getMockExternalEventItems,
  handoffUrls,
  liveMeta,
  mapExternalEventRow,
  mockMeta,
} from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type WpDashboard = {
  kpis?: {
    pending_review?: number
    published?: number
    external_events?: number
  }
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
      const items = getMockExternalEventItems()
      return NextResponse.json({
        code: "ok",
        meta: mockMeta(),
        stats: { pendingReview: items.length, imported24h: 4 },
        items,
        handoffUrl: handoff.externalEventsUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const [dashboard, queue] = await Promise.all([
      wpFetchJson<WpDashboard>("/external-events/dashboard", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpQueue>("/external-events/admin/queue", { auth: wpAuth }).catch(() => null),
    ])

    const reasons: string[] = []
    if (!dashboard) reasons.push("External events dashboard unavailable.")
    if (!queue) reasons.push("External events review queue unavailable.")

    const items = (queue?.items ?? [])
      .map((row) => mapExternalEventRow(row))
      .filter((row): row is NonNullable<typeof row> => Boolean(row))

    const pendingReview = dashboard?.kpis?.pending_review ?? items.length

    return NextResponse.json({
      code: reasons.length > 0 ? "admin_external_events_partial" : "ok",
      meta: liveMeta(reasons),
      stats: {
        pendingReview,
        imported24h: 0,
      },
      items,
      handoffUrl: handoff.externalEventsUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_external_events",
      fallbackMessage: "We couldn't load external events summary.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
