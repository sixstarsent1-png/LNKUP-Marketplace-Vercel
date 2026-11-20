import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpApplyResult } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to apply.")
    }

    const body = await request.json()
    const opportunityId = parseInt(String(body.opportunity_id ?? ""), 10)
    if (!opportunityId) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid opportunity ID" },
        { status: 400 },
      )
    }

    const data = await wpFetchJson<WpApplyResult>("/applications/apply-with-profile", {
      method: "POST",
      auth: toWpAuth(session),
      body: {
        opportunity_id: opportunityId,
        selected_category: body.selected_category ?? "",
        application_message: body.application_message ?? body.message ?? "",
        setup_notes: body.setup_notes ?? "",
        accepted_terms: Boolean(body.accepted_terms),
      },
    })

    return NextResponse.json(data)
  } catch (error) {
    if (error instanceof WpFetchError) {
      const status =
        error.code === "duplicate"
          ? 409
          : error.code === "marketplace_closed"
            ? 403
            : error.status || 502
      return NextResponse.json(
        {
          code: error.code === "marketplace_closed" ? "marketplace_closed" : error.code,
          message: error.message,
          data: error.data,
        },
        { status },
      )
    }
    return toErrorResponse(error, {
      context: "applications/apply",
      fallbackMessage: "Application failed. Please try again.",
      fallbackCode: "apply_failed",
    })
  }
}
