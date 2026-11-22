import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import {
  isVendorMatchSection,
  mapVendorRecommendations,
  type WpVendorRecommendationsResponse,
} from "@/lib/api/match"

export async function GET(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to see your matches.")
    }
    const { searchParams } = new URL(request.url)
    const sectionParam = searchParams.get("section") ?? "recommended"
    if (!isVendorMatchSection(sectionParam)) {
      return NextResponse.json({ code: "invalid_section", message: "Unknown section." }, { status: 400 })
    }
    const perPage = Math.min(24, Math.max(1, Number(searchParams.get("per_page") ?? 8) || 8))

    const data = await wpFetchJson<WpVendorRecommendationsResponse>("/match/vendor/recommendations", {
      query: { section: sectionParam, per_page: String(perPage) },
      auth: toWpAuth(session),
    })

    return NextResponse.json(mapVendorRecommendations(sectionParam, data))
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/vendor/recommendations",
      fallbackMessage: "We couldn't load your matches.",
      fallbackCode: "match_recommendations_failed",
      forbiddenMessage: "Recommendations are for vendor accounts.",
    })
  }
}
