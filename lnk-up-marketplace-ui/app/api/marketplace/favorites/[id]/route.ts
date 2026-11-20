import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function DELETE(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to manage saved opportunities.")
    }

    const { id } = await context.params
    const opportunityId = Number(id)
    if (!opportunityId) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid opportunity." },
        { status: 400 },
      )
    }

    const auth = toWpAuth(session)
    const current = await wpFetchJson<{ favorites?: { opportunities?: number[] } }>(
      "/marketplace/favorites",
      { auth },
    )
    const favorited = (current.favorites?.opportunities ?? []).includes(opportunityId)

    if (favorited) {
      await wpFetchJson<{ favorited?: boolean }>("/marketplace/favorites", {
        method: "POST",
        auth,
        body: { type: "opportunities", id: opportunityId },
      })
    }

    return NextResponse.json({ removed: true, id: opportunityId })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/favorites",
      fallbackMessage: "Could not remove saved opportunity.",
      fallbackCode: "favorites_failed",
    })
  }
}
