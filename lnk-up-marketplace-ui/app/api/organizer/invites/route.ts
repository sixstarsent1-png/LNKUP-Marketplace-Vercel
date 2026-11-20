import { NextResponse } from "next/server"
import { mapOrganizerInvites } from "@/lib/api/organizer-crm"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view vendor invites.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<Record<string, unknown>>("/crm/invites/list", { auth })
    const items = mapOrganizerInvites(data)

    return NextResponse.json({
      code: "ok",
      items,
      total: items.length,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/invites",
      fallbackMessage: "We couldn't load invites. Please try again.",
      fallbackCode: "organizer_invites_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}

