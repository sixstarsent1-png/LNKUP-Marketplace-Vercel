import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpApplyResult } from "@/lib/api/types"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse, idempotencyHeaders, jsonBody } from "@/lib/api/apply-flow-bff"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to apply.")
    }

    const body = await jsonBody(request)
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
      headers: idempotencyHeaders(request),
      body: {
        opportunity_id: opportunityId,
        selected_category: body.selected_category ?? "",
        application_message: body.application_message ?? body.message ?? "",
        setup_notes: body.setup_notes ?? "",
        accepted_terms: Boolean(body.accepted_terms),
        invite_token: typeof body.invite_token === "string" ? body.invite_token : "",
        answers: body.answers && typeof body.answers === "object" ? body.answers : {},
        document_ids: Array.isArray(body.document_ids) ? body.document_ids.map(Number).filter((id) => id > 0) : [],
        client: "web",
        source: "next",
      },
    })

    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/apply", "Application failed. Please try again.")
  }
}
