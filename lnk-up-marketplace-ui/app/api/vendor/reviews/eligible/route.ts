import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapWpCompletedEvent,
  mapWpEligibilityFromEvent,
  mockReviewEligibility,
} from "@/lib/api/vendor-reputation"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockReviewEligibility })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view review status.")
    }

    const auth = toWpAuth(session)
    const dashboard = await wpFetchJson<{ events?: unknown[] }>("/reputation/vendor-dashboard", {
      auth,
    })

    const events = (dashboard.events ?? []).map((e) =>
      mapWpCompletedEvent(e as Record<string, unknown>),
    )

    const items = events
      .filter((e) => e.status === "review_pending" || e.status === "reviewed" || e.status === "completed")
      .map(mapWpEligibilityFromEvent)

    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/reviews/eligible",
      fallbackMessage: "We couldn't load review eligibility. Please try again.",
      fallbackCode: "review_eligibility_failed",
    })
  }
}
