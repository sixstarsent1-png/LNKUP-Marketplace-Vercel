import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
import { getOpsWpAuth } from "@/lib/ops/ops-auth"
import type { BffHealthCheck } from "@/lib/ops/types"

type ProbeSpec = {
  id: string
  label: string
  path: string
  method?: string
  body?: string
  authRequired?: boolean
  acceptStatuses?: number[]
}

function getOrigin(): string {
  if (process.env.PLAYWRIGHT_APP_URL) return process.env.PLAYWRIGHT_APP_URL.replace(/\/$/, "")
  if (process.env.VERCEL_URL) return `https://${process.env.VERCEL_URL}`
  return "http://127.0.0.1:3000"
}

async function probeBff(spec: ProbeSpec): Promise<BffHealthCheck> {
  const start = Date.now()
  const checkedAt = new Date().toISOString()
  const accept = spec.acceptStatuses ?? (spec.authRequired ? [401] : [200])

  try {
    const res = await fetch(`${getOrigin()}${spec.path}`, {
      method: spec.method ?? "GET",
      headers: spec.body ? { "Content-Type": "application/json" } : undefined,
      body: spec.body,
      cache: "no-store",
    })
    const latencyMs = Date.now() - start

    if (spec.authRequired && res.status === 401) {
      return {
        id: spec.id,
        label: spec.label,
        status: "auth_required",
        latencyMs,
        message: "Auth required (expected)",
        checkedAt,
      }
    }

    if (accept.includes(res.status)) {
      return {
        id: spec.id,
        label: spec.label,
        status: "pass",
        latencyMs,
        message: `HTTP ${res.status}`,
        checkedAt,
      }
    }

    if (res.status >= 500) {
      return {
        id: spec.id,
        label: spec.label,
        status: "fail",
        latencyMs,
        message: `HTTP ${res.status}`,
        checkedAt,
      }
    }

    return {
      id: spec.id,
      label: spec.label,
      status: "warning",
      latencyMs,
      message: `HTTP ${res.status}`,
      checkedAt,
    }
  } catch (error) {
    return {
      id: spec.id,
      label: spec.label,
      status: "fail",
      latencyMs: Date.now() - start,
      message: error instanceof Error ? error.message : "fetch failed",
      checkedAt,
    }
  }
}

async function probeWpAnalytics(): Promise<BffHealthCheck> {
  const checkedAt = new Date().toISOString()
  const auth = getOpsWpAuth()
  if (!auth) {
    return {
      id: "wp_analytics",
      label: "LNKup analytics",
      status: "not_configured",
      message: "OPS_WP credentials not set",
      checkedAt,
    }
  }
  if (!isLiveApiEnabled()) {
    return {
      id: "wp_analytics",
      label: "LNKup analytics",
      status: "not_configured",
      message: "Live API disabled",
      checkedAt,
    }
  }

  const start = Date.now()
  try {
    await wpFetchJson("/marketplace/analytics", { auth })
    return {
      id: "wp_analytics",
      label: "LNKup analytics",
      status: "pass",
      latencyMs: Date.now() - start,
      checkedAt,
    }
  } catch {
    return {
      id: "wp_analytics",
      label: "LNKup analytics",
      status: "warning",
      latencyMs: Date.now() - start,
      message: "Endpoint unreachable or forbidden",
      checkedAt,
    }
  }
}

const BFF_PROBES: ProbeSpec[] = [
  { id: "marketplace_search", label: "Marketplace search", path: "/api/marketplace/search" },
  {
    id: "apply_context",
    label: "Apply context",
    path: "/api/applications/apply-context/1",
    acceptStatuses: [200, 400, 403, 502],
  },
  { id: "vendor_requests", label: "Vendor requests", path: "/api/vendor/requests", authRequired: true },
  { id: "vendor_documents", label: "Vendor documents", path: "/api/vendor/documents", authRequired: true },
  { id: "favorites", label: "Favorites", path: "/api/marketplace/favorites", authRequired: true },
  {
    id: "saved_searches",
    label: "Saved searches",
    path: "/api/marketplace/saved-searches",
    authRequired: true,
  },
  {
    id: "payment_handoff",
    label: "Payment handoff",
    path: "/api/vendor/requests/1/payment",
    authRequired: true,
  },
  {
    id: "packet_preview",
    label: "Packet preview",
    path: "/api/vendor/requests/1/packet",
    authRequired: true,
  },
  { id: "organizer_home", label: "Organizer home", path: "/api/organizer/home", authRequired: true },
  { id: "organizer_events", label: "Organizer events", path: "/api/organizer/events", authRequired: true },
  {
    id: "organizer_requests",
    label: "Organizer vendor requests",
    path: "/api/organizer/requests",
    authRequired: true,
  },
  {
    id: "analytics_track",
    label: "Analytics track",
    path: "/api/analytics/track",
    method: "POST",
    body: "{}",
    acceptStatuses: [400],
  },
]

export async function GET() {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const bffChecks = await Promise.all(BFF_PROBES.map(probeBff))
  const wpCheck = await probeWpAnalytics()

  return NextResponse.json({
    checks: [...bffChecks, wpCheck],
    updatedAt: new Date().toISOString(),
  })
}
