import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

const EMPTY_QUEUE = { items: [], hidden: 0, counts: { critical: 0, high: 0, medium: 0, low: 0 } }

export async function GET(request: Request) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }
  const access = await requireAdminSession()
  if (!access.ok) return access.response
  if (!isLiveApiEnabled()) {
    return NextResponse.json({ ...EMPTY_QUEUE, mocked: true })
  }
  try {
    const includeHidden = new URL(request.url).searchParams.get("hidden") === "1"
    const data = await wpFetchJson("/admin/ops/queue", {
      auth: toWpAuth(access.session),
      query: includeHidden ? { include_hidden: 1 } : undefined,
    })
    return NextResponse.json(data)
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_ops_queue",
      fallbackMessage: "We couldn't load the operations queue.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}

export async function POST(request: Request) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }
  const access = await requireAdminSession()
  if (!access.ok) return access.response
  const body = (await request.json().catch(() => ({}))) as { key?: string; state?: string; hours?: number; note?: string }
  if (!body.key || !["dismissed", "snoozed", "clear"].includes(String(body.state))) {
    return NextResponse.json({ code: "validation_error", message: "Choose dismiss, snooze or restore." }, { status: 400 })
  }
  try {
    const data = await wpFetchJson("/admin/ops/queue/state", {
      method: "POST",
      auth: toWpAuth(access.session),
      body: { key: body.key, state: body.state, hours: body.hours ?? 24, note: body.note ?? "" },
    })
    return NextResponse.json(data)
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_ops_state",
      fallbackMessage: "We couldn't update that item.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
