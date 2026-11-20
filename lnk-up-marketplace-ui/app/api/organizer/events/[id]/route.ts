import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  buildMockEventWorkspace,
  mapOpsToWorkspace,
} from "@/lib/api/organizer-event-workspace"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

type EventPatchBody = {
  title?: string
  event_name?: string
  event_date?: string
  city?: string
  state?: string
  venue_name?: string
  venue_address?: string
  description?: string
  event_type?: string
  expected_attendance?: number
  start_time?: string
  end_time?: string
  ticketing_url?: string
}

function toWpEventPatch(body: EventPatchBody): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  const title = body.event_name ?? body.title
  if (title != null) payload.event_name = title
  if (body.event_date != null) payload.event_date = body.event_date
  if (body.city != null) payload.city = body.city
  if (body.state != null) payload.state = body.state
  if (body.venue_name != null) payload.venue_name = body.venue_name
  if (body.venue_address != null) payload.venue_address = body.venue_address
  if (body.description != null) payload.description = body.description
  if (body.event_type != null) payload.event_type = body.event_type
  if (body.expected_attendance != null) payload.expected_attendance = body.expected_attendance
  if (body.start_time != null) payload.start_time = body.start_time
  if (body.end_time != null) payload.end_time = body.end_time
  if (body.ticketing_url != null) payload.ticketing_url = body.ticketing_url
  return payload
}

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { id } = await context.params

    if (!isLiveApiEnabled()) {
      const mock = buildMockEventWorkspace(id)
      if (!mock) {
        return NextResponse.json(
          { code: "not_found", message: "This event could not be loaded." },
          { status: 404 },
        )
      }
      return NextResponse.json(mock)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const numericId = /^\d+$/.test(id) ? id : null

    if (!numericId) {
      return NextResponse.json(
        { code: "not_found", message: "This event could not be loaded." },
        { status: 404 },
      )
    }

    let ops = null
    let roster = null

    try {
      ops = await wpFetchJson<Record<string, unknown>>(`/operations/events/${numericId}`, { auth })
    } catch (error) {
      if (error instanceof WpFetchError && error.status === 404) {
        return NextResponse.json(
          { code: "not_found", message: "This event could not be loaded." },
          { status: 404 },
        )
      }
      if (error instanceof WpFetchError && error.status === 403) {
        return NextResponse.json(
          { code: "forbidden", message: "You don't have access to this event." },
          { status: 403 },
        )
      }
      throw error
    }

    try {
      roster = await wpFetchJson<Record<string, unknown>>(`/crm/events/${numericId}/roster`, { auth })
    } catch {
      roster = null
    }

    const workspace = mapOpsToWorkspace(numericId, ops as never, roster as never)
    return NextResponse.json(workspace)
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/events/detail",
      fallbackMessage: "This event could not be loaded.",
      fallbackCode: "event_workspace_failed",
      forbiddenMessage: "You don't have access to this event.",
    })
  }
}

export async function PATCH(request: Request, context: RouteContext) {
  try {
    const { id } = await context.params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json(
        { code: "not_found", message: "This event could not be loaded." },
        { status: 404 },
      )
    }

    const body = (await request.json().catch(() => ({}))) as EventPatchBody
    const payload = toWpEventPatch(body)
    if (Object.keys(payload).length === 0) {
      return NextResponse.json(
        { code: "invalid_body", message: "No event fields to update." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        message: "Event updated (demo).",
        event_id: Number(id),
        event_title: String(payload.event_name ?? "Event"),
        event_date: payload.event_date ?? "",
        event_city: payload.city ?? "",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const result = await wpFetchJson<Record<string, unknown>>(`/organizer/events/${id}`, {
      method: "PATCH",
      auth,
      body: payload,
    })

    return NextResponse.json(result)
  } catch (error) {
    if (error instanceof WpFetchError) {
      return toErrorResponse(error, {
        context: "organizer/events/patch",
        fallbackMessage: "Could not update this event.",
        fallbackCode: "event_update_failed",
        forbiddenMessage: "You don't have access to this event.",
      })
    }
    return toErrorResponse(error, {
      context: "organizer/events/patch",
      fallbackMessage: "Could not update this event.",
      fallbackCode: "event_update_failed",
    })
  }
}

export async function PUT(request: Request, context: RouteContext) {
  return PATCH(request, context)
}
