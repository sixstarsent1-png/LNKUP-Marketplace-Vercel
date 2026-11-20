import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import {
  getMockExternalEventItems,
  handoffUrls,
  mapExternalEventRow,
} from "@/lib/admin/queues"
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
    const eventId = Number(id)
    if (!eventId) {
      return NextResponse.json({ code: "not_found", message: "Event not found" }, { status: 404 })
    }

    const handoff = handoffUrls()

    if (!isLiveApiEnabled()) {
      const item = getMockExternalEventItems().find((row) => row.id === eventId)
      if (!item) {
        return NextResponse.json({ code: "not_found", message: "Event not found" }, { status: 404 })
      }
      return NextResponse.json({
        code: "ok",
        item,
        actions: ["publish", "reject"],
        handoffUrl: handoff.externalEventsUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const data = await wpFetchJson<{ item?: Record<string, unknown> }>(
      `/external-events/admin/${eventId}`,
      { auth: wpAuth },
    ).catch(() => null)

    if (!data?.item) {
      return NextResponse.json(
        {
          code: "handoff_required",
          message: "Open WordPress External Events for this record.",
          handoffUrl: handoff.externalEventsUrl,
        },
        { status: 404 },
      )
    }

    const item = mapExternalEventRow(data.item)
    return NextResponse.json({
      code: "ok",
      item,
      actions: ["publish", "reject"],
      handoffUrl: handoff.externalEventsUrl,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_external_event_detail",
      fallbackMessage: "We couldn't load this external event.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
