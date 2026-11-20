import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { getMockConnectorItems, handoffUrls, mapConnectorRow } from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type Params = { params: Promise<{ id: string }> }

export async function GET(_request: Request, { params }: Params) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    const { id } = await params
    const connectorId = Number(id)
    if (!connectorId) {
      return NextResponse.json({ code: "not_found", message: "Connector not found" }, { status: 404 })
    }

    const handoff = handoffUrls()

    if (!isLiveApiEnabled()) {
      const item = getMockConnectorItems().find((row) => row.id === connectorId)
      if (!item) {
        return NextResponse.json({ code: "not_found", message: "Connector not found" }, { status: 404 })
      }
      return NextResponse.json({
        code: "ok",
        item,
        actions: ["approve", "pause", "reject"],
        handoffUrl: handoff.connectorsUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const data = await wpFetchJson<{ connectors?: Array<Record<string, unknown>> }>("/admin/connectors", {
      auth: wpAuth,
    })
    const item = (data.connectors ?? [])
      .map((row) => mapConnectorRow(row))
      .find((row) => row?.id === connectorId)

    if (!item) {
      return NextResponse.json(
        {
          code: "handoff_required",
          message: "Connector detail not found in list. Open WordPress Connectors.",
          handoffUrl: handoff.connectorsUrl,
        },
        { status: 404 },
      )
    }

    return NextResponse.json({
      code: "ok",
      item,
      actions: ["approve", "pause", "reject"],
      handoffUrl: handoff.connectorsUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_connector_detail",
      fallbackMessage: "We couldn't load this connector.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
