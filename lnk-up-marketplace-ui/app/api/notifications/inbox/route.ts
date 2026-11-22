import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { isCommsUnavailable, mapCommsItem, type CommsInboxResponse, type InboxTab } from "@/lib/api/comms"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

const TABS = new Set<InboxTab>(["all", "applications", "payments", "events", "opportunities", "operations"])

/**
 * Categorized, paginated inbox from lnkup-communications. Responds `available: false` when the
 * plugin is not installed so the page keeps using the composed feed.
 */
export async function GET(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to view notifications.")
    }
    const url = new URL(request.url)
    const role = url.searchParams.get("role") === "organizer" && session.canAccessOrganizer ? "organizer" : session.canAccessVendor ? "vendor" : "organizer"
    const tabParam = url.searchParams.get("tab") as InboxTab | null
    const tab: InboxTab = tabParam && TABS.has(tabParam) ? tabParam : "all"
    const page = Math.max(1, Math.min(200, Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1))

    const unavailable: CommsInboxResponse = { available: false, items: [], page: 1, hasMore: false, tabs: [], role }
    if (!isLiveApiEnabled()) {
      return NextResponse.json(unavailable)
    }

    try {
      const res = await wpFetchJson<{ items?: unknown[]; page?: number; hasMore?: boolean; tabs?: InboxTab[] }>("/comms/inbox", {
        auth: toWpAuth(session),
        query: { role, tab, page, per_page: 20 },
      })
      const items = (res.items ?? [])
        .map((item) => mapCommsItem(item as Parameters<typeof mapCommsItem>[0]))
        .filter((item): item is NonNullable<typeof item> => item !== null)
      return NextResponse.json({
        available: true,
        items,
        page: res.page ?? page,
        hasMore: res.hasMore === true,
        tabs: (res.tabs ?? []).filter((t) => TABS.has(t)),
        role,
      } satisfies CommsInboxResponse)
    } catch (error) {
      if (isCommsUnavailable(error)) return NextResponse.json(unavailable)
      throw error
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/inbox",
      fallbackMessage: "We couldn't load notifications.",
      fallbackCode: "notifications_inbox_failed",
    })
  }
}
