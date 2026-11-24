import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { mapEventVendorMatches, type WpEventVendorsResponse } from "@/lib/api/match"

const FORWARDED = ["opportunity_id", "category", "city", "state", "verified", "radius", "exclude_applied", "stage", "page", "section", "per_page"] as const

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to find vendors.")
    }
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "invalid_event", message: "Choose an event." }, { status: 400 })
    }
    const url = new URL(request.url)
    const query: Record<string, string> = { per_page: "24" }
    for (const key of FORWARDED) {
      const value = url.searchParams.get(key)
      if (value) query[key] = value
    }

    const data = await wpFetchJson<WpEventVendorsResponse>(`/match/organizer/events/${id}/vendors`, {
      query,
      auth: toWpAuth(session),
    })
    return NextResponse.json(mapEventVendorMatches(data))
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/event-vendors",
      fallbackMessage: "We couldn't load matching vendors.",
      fallbackCode: "match_event_vendors_failed",
      forbiddenMessage: "You can only recruit vendors for your own events.",
    })
  }
}
