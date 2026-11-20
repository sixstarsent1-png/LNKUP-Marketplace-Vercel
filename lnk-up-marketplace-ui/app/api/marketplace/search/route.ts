import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  filtersToWpQuery,
  mapWpOpportunityList,
  opportunityToEventItem,
  searchParamsToFilters,
} from "@/lib/api/marketplace-discovery"
import { mockSearchResults } from "@/lib/api/marketplace-discovery-mock"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpSearchResponse } from "@/lib/api/types"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url)
    const filters = searchParamsToFilters(searchParams)

    if (!isLiveApiEnabled()) {
      const { items, total } = mockSearchResults({
        query: filters.query,
        city: filters.city,
        category: filters.category,
      })
      return NextResponse.json({
        items: items.map(opportunityToEventItem),
        opportunities: items,
        total,
        page: filters.page ?? 1,
        pages: 1,
        empty_state: total === 0,
        active_filters: [],
        has_external: false,
        mocked: true,
      })
    }

    const session = await getSession()
    const wpQuery = filtersToWpQuery(filters)

    const data = await wpFetchJson<WpSearchResponse>("/marketplace/search", {
      query: wpQuery,
      auth: session ? toWpAuth(session) : undefined,
    })

    const opportunities = mapWpOpportunityList(data.items)

    return NextResponse.json({
      items: opportunities.map(opportunityToEventItem),
      opportunities,
      total: data.total ?? 0,
      page: data.page ?? 1,
      pages: data.pages ?? 1,
      empty_state: data.empty_state ?? (data.total === 0),
      active_filters: data.active_filters ?? [],
      has_external: data.has_external ?? false,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/search",
      fallbackMessage: "We couldn't search events. Please try again.",
      fallbackCode: "search_failed",
      exposeMessage: true,
    })
  }
}
