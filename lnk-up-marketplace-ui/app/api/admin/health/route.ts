import { NextResponse } from "next/server"
import { getPublicWpSiteUrl, isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { getMockAdminHealth } from "@/lib/admin/phase15"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

async function probe(
  id: string,
  label: string,
  path: string,
  auth: ReturnType<typeof toWpAuth>,
  checkedAt: string,
) {
  try {
    await wpFetchJson(path, { auth })
    return { id, label, status: "pass" as const, checkedAt }
  } catch {
    return {
      id,
      label,
      status: "warning" as const,
      message: "Unavailable or forbidden.",
      checkedAt,
    }
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
      return NextResponse.json({ code: "ok", ...getMockAdminHealth() })
    }

    const checkedAt = new Date().toISOString()
    const wpAuth = toWpAuth(access.session)

    const checks = await Promise.all([
      probe("marketplace_analytics", "Marketplace analytics", "/marketplace/analytics", wpAuth, checkedAt),
      probe("admin_connectors", "Connector admin", "/admin/connectors", wpAuth, checkedAt),
      probe(
        "external_events_dashboard",
        "External events dashboard",
        "/external-events/dashboard",
        wpAuth,
        checkedAt,
      ),
      probe(
        "verification_summary",
        "Verification summary",
        "/verification/admin/summary",
        wpAuth,
        checkedAt,
      ),
      (async () => {
        if (!getPublicWpSiteUrl()) {
          return {
            id: "wp_site_url",
            label: "WordPress site URL",
            status: "not_configured" as const,
            message: "Set NEXT_PUBLIC_WORDPRESS_SITE_URL.",
            checkedAt,
          }
        }
        return { id: "wp_site_url", label: "WordPress site URL", status: "pass" as const, checkedAt }
      })(),
    ])

    const degraded = checks.some((c) => c.status === "warning" || c.status === "not_configured")

    return NextResponse.json({
      code: degraded ? "admin_health_degraded" : "ok",
      meta: {
        live: true,
        mocked: false,
        partial: checks.some((c) => c.status !== "pass"),
        stale: false,
        source: "wordpress",
        updatedAt: checkedAt,
        lastUpdatedAt: checkedAt,
        unavailableReasons: checks
          .filter((c) => c.status !== "pass")
          .map((c) => c.message ?? `${c.label} not healthy`),
      },
      checks,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_health",
      fallbackMessage: "We couldn't load admin health checks.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
