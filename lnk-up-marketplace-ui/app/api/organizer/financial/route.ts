import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapOrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { mockOrganizerFinancial } from "@/lib/api/organizer-mock"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

/** Phase 4 alias — delegates to enriched money snapshot shape */
export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mapOrganizerFinancialSnapshot(mockOrganizerFinancial))
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view financial details.")
    }

    const auth = toWpAuth(session)
    let data: unknown
    try {
      data = await wpFetchJson("/financial/organizer-balances", { auth })
    } catch {
      data = await wpFetchJson("/financial/dashboard", { auth, query: { role: "organizer" } })
    }

    return NextResponse.json(mapOrganizerFinancialSnapshot(data))
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/financial",
      fallbackMessage: "Financial details are managed on LNKup web.",
      fallbackCode: "organizer_financial_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
