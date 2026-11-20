import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to manage notifications.")
    }

    const body = (await request.json().catch(() => ({}))) as { ids?: string[]; role?: string }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ok: true })
    }

    await wpFetchJson<{ ok?: boolean }>("/notifications/inbox/read-all", {
      method: "POST",
      auth: toWpAuth(session),
      body: {
        ids: Array.isArray(body.ids) ? body.ids : [],
        role: body.role ?? "",
      },
    })

    return NextResponse.json({ ok: true })
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/read-all",
      fallbackMessage: "Could not mark notifications read.",
      fallbackCode: "notification_read_all_failed",
    })
  }
}
