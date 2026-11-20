import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapOrganizerFinancialSnapshot,
  type OrganizerMoneySummary,
} from "@/lib/api/organizer-financial"
import { mockOrganizerFinancial } from "@/lib/api/organizer-mock"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ summary: mockOrganizerFinancial satisfies OrganizerMoneySummary })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view financial details.")
    }

    const auth = toWpAuth(session)
    let balances: unknown = {}
    let dashboard: unknown = {}

    try {
      balances = await wpFetchJson("/financial/organizer-balances", { auth })
    } catch {
      balances = {}
    }

    try {
      dashboard = await wpFetchJson("/financial/dashboard", { auth, query: { role: "organizer" } })
    } catch {
      dashboard = {}
    }

    const merged = {
      ...(balances && typeof balances === "object" ? balances : {}),
      ...(dashboard && typeof dashboard === "object" ? dashboard : {}),
    }

    return NextResponse.json({ summary: mapOrganizerFinancialSnapshot(merged) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/money",
      fallbackMessage: "Financial details are managed on LNKup web.",
      fallbackCode: "organizer_money_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
