import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapOpportunityList } from "@/lib/api/map-opportunity"
import type { WpSearchResponse } from "@/lib/api/types"
import { getMarketBySlug } from "@/lib/api/markets"
import { buildMockMarketPage } from "@/lib/api/market-page"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { isLiveApiEnabled } from "@/lib/api/config"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ slug: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { slug } = await context.params
    const market = getMarketBySlug(slug)

    if (!market) {
      return NextResponse.json(
        { code: "not_found", message: "Market not found." },
        { status: 404 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json(buildMockMarketPage(market))
    }

    const session = await getSession()
    const data = await wpFetchJson<WpSearchResponse>("/marketplace/search", {
      query: { city: market.city, per_page: "6", page: "1" },
      auth: session ? toWpAuth(session) : undefined,
    })

    const opportunities = mapOpportunityList(data.items)
    const categories = Array.from(
      new Set(opportunities.flatMap((e) => e.categoriesNeeded)),
    ).slice(0, 10)

    return NextResponse.json({
      market,
      opportunities,
      total: data.total ?? opportunities.length,
      categories,
      prelaunchMessage:
        data.empty_state && opportunities.length === 0
          ? "Browse opportunities now. Apply when the marketplace opens."
          : undefined,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/markets",
      fallbackMessage: "We couldn't load this market.",
      fallbackCode: "market_failed",
    })
  }
}
