import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapFavoritesResponse } from "@/lib/api/favorites"
import type { WpFavoritesResponse } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view saved opportunities.")
    }

    const data = await wpFetchJson<WpFavoritesResponse>("/marketplace/favorites", {
      auth: toWpAuth(session),
    })
    const mapped = mapFavoritesResponse(data)
    return NextResponse.json(mapped)
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/favorites",
      fallbackMessage: "We couldn't load your saved opportunities.",
      fallbackCode: "favorites_failed",
    })
  }
}

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to save opportunities.")
    }

    const body = await request.json().catch(() => ({}))
    const id = Number(body.id)
    const type = typeof body.type === "string" ? body.type : "opportunities"

    if (!id) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid opportunity." },
        { status: 400 },
      )
    }

    const data = await wpFetchJson<{ favorited?: boolean }>("/marketplace/favorites", {
      method: "POST",
      auth: toWpAuth(session),
      body: { type, id },
    })

    return NextResponse.json({ favorited: Boolean(data.favorited), id })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/favorites",
      fallbackMessage: "Could not update saved opportunity.",
      fallbackCode: "favorites_failed",
    })
  }
}
