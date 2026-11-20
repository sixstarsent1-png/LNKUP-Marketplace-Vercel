import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSavedSearch } from "@/lib/api/saved-searches"
import type { WpSavedSearchItem } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function PATCH(request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to manage saved searches.")
    }

    const { id } = await context.params
    const body = await request.json().catch(() => ({}))
    const frequency = typeof body.frequency === "string" ? body.frequency : undefined
    const paused = body.paused !== undefined ? Boolean(body.paused) : undefined
    const name = typeof body.name === "string" ? body.name : undefined

    const wpBody: Record<string, unknown> = {}
    if (name) wpBody.name = name

    if (frequency === "none") {
      wpBody.alert_enabled = false
    } else if (frequency) {
      wpBody.frequency = frequency
      wpBody.alert_enabled = true
    }

    if (paused === true) {
      wpBody.status = "paused"
    } else if (paused === false) {
      wpBody.status = "active"
    }

    if (body.alert_enabled !== undefined) {
      wpBody.alert_enabled = Boolean(body.alert_enabled)
    }

    const updated = await wpFetchJson<WpSavedSearchItem>(
      `/marketplace/saved-searches/${encodeURIComponent(id)}`,
      {
        method: "PATCH",
        auth: toWpAuth(session),
        body: wpBody,
      },
    )

    return NextResponse.json(mapSavedSearch(updated))
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/saved-searches",
      fallbackMessage: "Could not update saved search.",
      fallbackCode: "saved_searches_failed",
    })
  }
}

export async function DELETE(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to manage saved searches.")
    }

    const { id } = await context.params
    await wpFetchJson(`/marketplace/saved-searches/${encodeURIComponent(id)}`, {
      method: "DELETE",
      auth: toWpAuth(session),
    })

    return NextResponse.json({ ok: true })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/saved-searches",
      fallbackMessage: "Could not delete saved search.",
      fallbackCode: "saved_searches_failed",
    })
  }
}
