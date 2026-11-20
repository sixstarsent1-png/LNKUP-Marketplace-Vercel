import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSectionItems, type WpSectionResponse } from "@/lib/api/marketplace-sections"
import { opportunityToEventItem } from "@/lib/api/marketplace-discovery"
import { mapWpOpportunityList } from "@/lib/api/marketplace-discovery"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockOpportunitiesAsEvents } from "@/lib/api/marketplace-discovery-mock"

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url)
    const limit = searchParams.get("limit") ?? "8"

    if (!isLiveApiEnabled()) {
      const items = mockOpportunitiesAsEvents().slice(0, parseInt(limit, 10) || 8)
      return NextResponse.json({ section: "closing_soon", items, total: items.length, mocked: true })
    }

    const data = await wpFetchJson<WpSectionResponse>("/marketplace/sections/trending", {
      query: { section: "closing_soon", limit },
    })
    const opps = mapWpOpportunityList(data.items)
    const items = opps.map(opportunityToEventItem)
    return NextResponse.json({ section: "closing_soon", items, total: items.length })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/sections/closing-soon",
      fallbackMessage: "Could not load closing soon opportunities.",
      fallbackCode: "section_failed",
    })
  }
}
