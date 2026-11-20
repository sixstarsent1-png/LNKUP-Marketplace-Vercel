import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  isAllowedAnalyticsEvent,
  sanitizeAnalyticsMetadata,
  toWpEventType,
  type LnkupAnalyticsEvent,
} from "@/lib/analytics/lnkup-events"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as LnkupAnalyticsEvent
    if (!body?.event || !isAllowedAnalyticsEvent(body.event)) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid analytics event." },
        { status: 400 },
      )
    }

    const session = await getSession()
    const opportunityId = body.opportunityId
      ? parseInt(String(body.opportunityId).replace(/\D/g, ""), 10)
      : undefined

    const data = sanitizeAnalyticsMetadata({
      ...body.metadata,
      source: body.source ?? null,
      event_id: body.eventId ?? null,
      request_id: body.requestId ?? null,
    })

    if (process.env.NEXT_PUBLIC_LNKUP_LIVE_API !== "true") {
      return NextResponse.json({ success: true, mocked: true })
    }

    await wpFetchJson("/marketplace/track/event", {
      method: "POST",
      auth: session ? toWpAuth(session) : undefined,
      body: {
        event_type: toWpEventType(body.event),
        data,
        opportunity_id: opportunityId || undefined,
      },
    })

    return NextResponse.json({ success: true })
  } catch (error) {
    console.error("[analytics/track]", error)
    return toErrorResponse(error, {
      context: "analytics/track",
      fallbackMessage: "Tracking failed.",
      fallbackCode: "track_failed",
    })
  }
}
