import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { isCommsUnavailable, isInboxTab, type CommsInboxCount, type InboxTab } from "@/lib/api/comms"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

/** Unread counts per inbox tab from lnkup-communications (`available: false` without the plugin). */
export async function GET(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to view notifications.")
    }
    const url = new URL(request.url)
    const role = url.searchParams.get("role") === "organizer" && session.canAccessOrganizer ? "organizer" : session.canAccessVendor ? "vendor" : "organizer"
    const unavailable: CommsInboxCount = { available: false, unread: 0, actionRequired: 0, byTab: {} }
    if (!isLiveApiEnabled()) {
      return NextResponse.json(unavailable)
    }
    try {
      const res = await wpFetchJson<{ unread?: number; actionRequired?: number; byTab?: Record<string, number> }>("/comms/inbox/count", {
        auth: toWpAuth(session),
        query: { role },
      })
      const byTab: Partial<Record<InboxTab, number>> = {}
      for (const [tab, count] of Object.entries(res.byTab ?? {})) {
        if (isInboxTab(tab)) byTab[tab] = Number(count) || 0
      }
      return NextResponse.json({
        available: true,
        unread: Number(res.unread ?? 0),
        actionRequired: Number(res.actionRequired ?? 0),
        byTab,
      } satisfies CommsInboxCount)
    } catch (error) {
      if (isCommsUnavailable(error)) return NextResponse.json(unavailable)
      throw error
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/inbox/count",
      fallbackMessage: "We couldn't load notification counts.",
      fallbackCode: "notifications_inbox_count_failed",
    })
  }
}
