import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSavedSearch } from "@/lib/api/saved-searches"
import type { WpSavedSearchItem } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function POST(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to run saved searches.")
    }

    const { id } = await context.params
    const auth = toWpAuth(session)

    const result = await wpFetchJson<WpSavedSearchItem>(
      `/marketplace/saved-searches/${encodeURIComponent(id)}/run`,
      { method: "POST", auth },
    )

    return NextResponse.json(mapSavedSearch(result))
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/saved-searches/run",
      fallbackMessage: "Could not run saved search.",
      fallbackCode: "saved_search_run_failed",
    })
  }
}
