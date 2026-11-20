import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapOrganizerActivityList,
  mockOrganizerActivity,
} from "@/lib/api/organizer-financial"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockOrganizerActivity })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view financial activity.")
    }

    const auth = toWpAuth(session)
    let data: unknown
    try {
      data = await wpFetchJson("/financial/activity", { auth, query: { role: "organizer" } })
    } catch {
      data = await wpFetchJson("/financial/organizer-wallet/entries", { auth })
    }

    return NextResponse.json({ items: mapOrganizerActivityList(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/money/activity",
      fallbackMessage: "We couldn't load financial activity.",
      fallbackCode: "organizer_money_activity_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
