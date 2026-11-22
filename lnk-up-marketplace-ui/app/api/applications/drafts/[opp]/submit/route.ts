import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpApplyResult } from "@/lib/api/types"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import {
  applyFlowErrorResponse,
  idempotencyHeaders,
  invalidIdResponse,
  jsonBody,
  positiveId,
} from "@/lib/api/apply-flow-bff"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request, { params }: { params: Promise<{ opp: string }> }) {
  const opp = positiveId((await params).opp)
  if (!opp) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse("Please sign in to apply.")
  const headers = idempotencyHeaders(request)
  if (!headers["Idempotency-Key"]) {
    return NextResponse.json(
      { code: "lnkup_idempotency_key_required", message: "Missing request key. Refresh and try again." },
      { status: 400 },
    )
  }
  const body = await jsonBody(request)
  try {
    const data = await wpFetchJson<WpApplyResult>(`/applications/drafts/${opp}/submit`, {
      method: "POST",
      auth: toWpAuth(session),
      headers,
      body: { accepted_terms: Boolean(body.accepted_terms), accept_current_price: Boolean(body.accept_current_price) },
    })
    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/drafts/submit", "Application failed. Please try again.")
  }
}
