import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function POST(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to manage notifications.")
    }

    const { id } = await context.params
    if (!id || id.length > 120) {
      return NextResponse.json({ code: "invalid_id", message: "Invalid notification." }, { status: 400 })
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ok: true, id })
    }

    await wpFetchJson<{ ok?: boolean; id?: string }>(
      `/notifications/inbox/${encodeURIComponent(id)}/read`,
      {
        method: "POST",
        auth: toWpAuth(session),
        body: {},
      },
    )

    return NextResponse.json({ ok: true, id })
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/read",
      fallbackMessage: "Could not mark this notification read.",
      fallbackCode: "notification_read_failed",
    })
  }
}
