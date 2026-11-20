import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapWpReview, mockOrganizerReviews } from "@/lib/api/vendor-reputation"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockOrganizerReviews })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your reviews.")
    }

    const auth = toWpAuth(session)
    const dashboard = await wpFetchJson<{ reviews?: unknown[] }>("/reputation/vendor-dashboard", {
      auth,
    })

    const items = (dashboard.reviews ?? []).map((item) =>
      mapWpReview(item as Record<string, unknown>),
    )

    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/reviews",
      fallbackMessage: "We couldn't load your reviews. Please try again.",
      fallbackCode: "reviews_failed",
      forbiddenMessage: "You don't have access to these reviews.",
    })
  }
}
