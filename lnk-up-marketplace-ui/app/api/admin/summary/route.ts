import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import {
  buildWpAdminHandoffUrls,
  getMockAdminSummary,
  type AdminSummaryResponse,
} from "@/lib/admin/phase15"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type WpMarketplaceAnalytics = {
  searches_30d?: number
  card_clicks_30d?: number
}

type WpGrowthAdminSummary = {
  active_vendors?: number
}

type WpConnectorsPayload = {
  metrics?: { pending?: number; active?: number }
}

type WpVerificationSummary = {
  queue_count?: number
}

type WpExternalEventsDashboard = {
  kpis?: {
    pending_review?: number
  }
}

function emptyLiveKpis() {
  return {
    activeVendors: 0,
    activeOrganizers: 0,
    liveEvents: 0,
    applications: 0,
    paidBookings: 0,
    verificationQueue: 0,
    openDisputes: 0,
  }
}

export async function GET() {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ code: "ok", ...getMockAdminSummary() })
    }

    const wpAuth = toWpAuth(access.session)
    const timestamp = new Date().toISOString()
    const [analytics, growth, connectors, verification, externalEvents] = await Promise.all([
      wpFetchJson<WpMarketplaceAnalytics>("/marketplace/analytics", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpGrowthAdminSummary>("/growth/admin/summary", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpConnectorsPayload>("/admin/connectors", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpVerificationSummary>("/verification/admin/summary", { auth: wpAuth }).catch(() => null),
      wpFetchJson<WpExternalEventsDashboard>("/external-events/dashboard", { auth: wpAuth }).catch(() => null),
    ])

    const reasons: string[] = []
    if (!analytics) reasons.push("Marketplace analytics unavailable.")
    if (!growth) reasons.push("Growth admin summary unavailable.")
    if (!connectors) reasons.push("Connector queue unavailable.")
    if (!verification) reasons.push("Verification queue unavailable.")
    if (!externalEvents) reasons.push("External events queue unavailable.")

    const verificationPending = verification?.queue_count ?? null
    const connectorsPending = connectors?.metrics?.pending ?? null
    const externalPending = externalEvents?.kpis?.pending_review ?? null

    const payload: AdminSummaryResponse & { code: string } = {
      code: reasons.length > 0 ? "admin_summary_partial" : "ok",
      meta: {
        live: true,
        mocked: false,
        partial: reasons.length > 0,
        stale: !analytics,
        source: reasons.length > 0 ? "mixed" : "wordpress",
        updatedAt: timestamp,
        lastUpdatedAt: timestamp,
        unavailableReasons: reasons.length > 0 ? reasons : undefined,
      },
      kpis: {
        ...emptyLiveKpis(),
        activeVendors: growth?.active_vendors ?? 0,
        applications: analytics?.card_clicks_30d ?? 0,
        verificationQueue: verificationPending ?? 0,
      },
      queue: {
        externalEventsPendingReview: externalPending ?? 0,
        verificationPending: verificationPending ?? 0,
        connectorsPendingApproval: connectorsPending ?? 0,
      },
      handoff: buildWpAdminHandoffUrls(),
    }

    return NextResponse.json(payload)
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_summary",
      fallbackMessage: "We couldn't load admin summary.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
