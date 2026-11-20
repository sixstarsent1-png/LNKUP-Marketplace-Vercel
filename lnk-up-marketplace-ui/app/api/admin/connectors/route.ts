import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import {
  getMockConnectorItems,
  handoffUrls,
  liveMeta,
  mapConnectorRow,
  mockMeta,
} from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type WpConnectorsPayload = {
  connectors?: Array<Record<string, unknown>>
  metrics?: {
    active?: number
    pending?: number
    referrals?: number
    revenue?: number
    rewards_pending?: number
  }
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
      const items = getMockConnectorItems()
      return NextResponse.json({
        code: "ok",
        meta: mockMeta(),
        stats: {
          pendingApprovals: items.filter((i) => i.status === "pending").length,
          activeConnectors: items.filter((i) => i.status === "active").length,
        },
        items,
        handoffUrl: handoff.connectorsUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const data = await wpFetchJson<WpConnectorsPayload>("/admin/connectors", { auth: wpAuth }).catch(
      () => null,
    )
    const reasons: string[] = []
    if (!data) reasons.push("Connector admin list unavailable.")

    const items = (data?.connectors ?? [])
      .map((row) => mapConnectorRow(row))
      .filter((row): row is NonNullable<typeof row> => Boolean(row))

    const pendingApprovals = data?.metrics?.pending ?? items.filter((i) => i.status === "pending").length
    const activeConnectors = data?.metrics?.active ?? items.filter((i) => i.status === "active").length

    return NextResponse.json({
      code: reasons.length > 0 ? "admin_connectors_partial" : "ok",
      meta: liveMeta(reasons),
      stats: { pendingApprovals, activeConnectors },
      items,
      handoffUrl: handoff.connectorsUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_connectors",
      fallbackMessage: "We couldn't load connector summary.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
