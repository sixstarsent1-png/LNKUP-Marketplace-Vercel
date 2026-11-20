import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSavedSearch, toWpFilters } from "@/lib/api/saved-searches"
import type { SavedSearchFilters } from "@/lib/api/saved-searches"
import type { WpSavedSearchItem, WpSavedSearchesResponse } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view saved searches.")
    }

    const data = await wpFetchJson<WpSavedSearchesResponse>("/marketplace/saved-searches", {
      auth: toWpAuth(session),
    })
    const searches = (data.searches ?? []).map(mapSavedSearch)
    return NextResponse.json({ searches, limit: data.limit })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/saved-searches",
      fallbackMessage: "We couldn't load your saved searches.",
      fallbackCode: "saved_searches_failed",
    })
  }
}

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to save searches.")
    }

    const body = await request.json().catch(() => ({}))
    const name = typeof body.name === "string" ? body.name : "Saved search"
    const filters = toWpFilters((body.filters ?? {}) as SavedSearchFilters)
    const frequency = typeof body.frequency === "string" ? body.frequency : "weekly"

    const created = await wpFetchJson<WpSavedSearchItem>("/marketplace/saved-searches", {
      method: "POST",
      auth: toWpAuth(session),
      body: { name, filters, frequency, alert_enabled: true },
    })

    return NextResponse.json(mapSavedSearch(created))
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/saved-searches",
      fallbackMessage: "Could not save search.",
      fallbackCode: "saved_searches_failed",
      exposeMessage: true,
    })
  }
}
