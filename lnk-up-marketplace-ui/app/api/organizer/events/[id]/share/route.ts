import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import {
  buildMockEventWorkspace,
  buildShareLink,
  mapOpsToWorkspace,
} from "@/lib/api/organizer-event-workspace"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

async function loadWorkspace(eventId: string, auth: ReturnType<typeof toWpAuth>) {
  const ops = await wpFetchJson<Record<string, unknown>>(`/operations/events/${eventId}`, { auth })
  let roster = null
  try {
    roster = await wpFetchJson<Record<string, unknown>>(`/crm/events/${eventId}/roster`, { auth })
  } catch {
    roster = null
  }
  return mapOpsToWorkspace(eventId, ops as never, roster as never)
}

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { id } = await context.params

    if (!isLiveApiEnabled()) {
      const mock = buildMockEventWorkspace(id)
      if (!mock) {
        return NextResponse.json({
          eventId: id,
          title: "Event",
          status: "unavailable",
        })
      }
      return NextResponse.json(buildShareLink(mock))
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    if (!/^\d+$/.test(id)) {
      return NextResponse.json({
        eventId: id,
        title: "Event",
        status: "unavailable",
      })
    }

    try {
      const workspace = await loadWorkspace(id, toWpAuth(session))
      return NextResponse.json(buildShareLink(workspace))
    } catch (error) {
      if (error instanceof WpFetchError && (error.status === 404 || error.status === 403)) {
        return NextResponse.json({
          eventId: id,
          title: "Event",
          status: "unavailable",
        })
      }
      throw error
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/events/share",
      fallbackMessage: "Share link is not available yet.",
      fallbackCode: "share_failed",
    })
  }
}
