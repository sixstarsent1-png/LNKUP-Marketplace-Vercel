import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { isInboxTab } from "@/lib/api/comms"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

/** Mark every unread server inbox item in one tab (or all tabs) as read for the signed-in user. */
export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to manage notifications.")
    }
    const body = (await request.json().catch(() => ({}))) as { role?: string; tab?: string }
    const role = body.role === "organizer" && session.canAccessOrganizer ? "organizer" : session.canAccessVendor ? "vendor" : "organizer"
    const tab = isInboxTab(body.tab) ? body.tab : "all"
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ok: true, marked: 0 })
    }
    const res = await wpFetchJson<{ ok?: boolean; marked?: number }>("/comms/inbox/read-all", {
      method: "POST",
      auth: toWpAuth(session),
      query: { role, tab },
    })
    return NextResponse.json({ ok: true, marked: Number(res.marked ?? 0) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/inbox/read-all",
      fallbackMessage: "Could not mark notifications read.",
      fallbackCode: "notification_read_all_failed",
    })
  }
}
