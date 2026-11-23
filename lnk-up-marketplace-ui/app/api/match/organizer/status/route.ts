import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to recruit vendors.")
    }
    const data = await wpFetchJson<{ find_vendors_enabled?: boolean }>("/match/organizer/status", {
      auth: toWpAuth(session),
    })
    return NextResponse.json({ findVendorsEnabled: data.find_vendors_enabled === true })
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/status",
      fallbackMessage: "We couldn't check whether Find Vendors is available.",
      fallbackCode: "match_status_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
