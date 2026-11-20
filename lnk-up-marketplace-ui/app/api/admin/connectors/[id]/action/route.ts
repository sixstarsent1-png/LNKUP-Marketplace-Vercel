import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { handoffUrls } from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type Params = { params: Promise<{ id: string }> }
type ActionBody = { action?: "approve" | "pause" | "reject" }

export async function POST(request: Request, { params }: Params) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    const { id } = await params
    const connectorId = Number(id)
    const body = (await request.json().catch(() => ({}))) as ActionBody
    const action = body.action

    if (!connectorId || !action || !["approve", "pause", "reject"].includes(action)) {
      return NextResponse.json(
        { code: "admin_action_failed", message: "Provide action: approve, pause, or reject." },
        { status: 400 },
      )
    }

    const handoff = handoffUrls()

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        code: "ok",
        mocked: true,
        success: true,
        action,
        connectorId,
        message: `Mock ${action} succeeded.`,
      })
    }

    const wpAuth = toWpAuth(access.session)
    await wpFetchJson(`/admin/connectors/${connectorId}/${action}`, {
      method: "POST",
      auth: wpAuth,
      body: {},
    })

    return NextResponse.json({
      code: "ok",
      success: true,
      action,
      connectorId,
      handoffUrl: handoff.connectorsUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_connector_action",
      fallbackMessage: "We couldn't update this connector.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
