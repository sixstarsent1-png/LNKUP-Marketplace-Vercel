import { NextResponse } from "next/server"
import { mapOrganizerLists } from "@/lib/api/organizer-crm"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view vendor lists.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<Record<string, unknown>>("/crm/lists", { auth })
    const items = mapOrganizerLists(data)

    return NextResponse.json({
      code: "ok",
      items,
      total: items.length,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/lists",
      fallbackMessage: "We couldn't load vendor lists. Please try again.",
      fallbackCode: "organizer_lists_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}

