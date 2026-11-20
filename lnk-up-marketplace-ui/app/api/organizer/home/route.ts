import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapOrganizerHomeSummary } from "@/lib/api/organizer-home"
import { mapOrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your organizer dashboard.")
    }

    const auth = toWpAuth(session)

    const [intelResult, widgetsResult, checklistResult, financialResult] = await Promise.allSettled([
      wpFetchJson<Record<string, unknown>>("/intelligence/organizer/summary", { auth }),
      wpFetchJson<Record<string, unknown>>("/crm/dashboard/widgets", { auth }),
      wpFetchJson<unknown>("/crm/launch-checklist", { auth }),
      wpFetchJson<unknown>("/financial/organizer-balances", { auth }),
    ])

    if (intelResult.status !== "fulfilled" || widgetsResult.status !== "fulfilled") {
      throw new Error("organizer_home_upstream_failed")
    }

    const unavailableReasons: string[] = []
    const checklist = checklistResult.status === "fulfilled" ? checklistResult.value : []
    const financial = financialResult.status === "fulfilled" ? financialResult.value : null
    if (checklistResult.status !== "fulfilled") {
      unavailableReasons.push("Organizer launch checklist unavailable.")
    }
    if (financialResult.status !== "fulfilled") {
      unavailableReasons.push("Organizer financial snapshot unavailable.")
    }

    const summary = mapOrganizerHomeSummary(
      intelResult.value,
      widgetsResult.value,
      checklist as never,
      financial,
    )
    if (financial) {
      summary.financial = mapOrganizerFinancialSnapshot(financial)
    }

    return NextResponse.json({
      code: unavailableReasons.length > 0 ? "organizer_home_partial" : "ok",
      meta: {
        partial: unavailableReasons.length > 0,
        unavailableReasons,
      },
      ...summary,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/home",
      fallbackMessage: "We couldn't load your organizer summary. Please try again.",
      fallbackCode: "organizer_home_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
