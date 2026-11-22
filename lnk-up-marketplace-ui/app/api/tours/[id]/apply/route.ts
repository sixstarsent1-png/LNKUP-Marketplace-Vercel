import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import {
  applyFlowErrorResponse,
  idempotencyHeaders,
  invalidIdResponse,
  jsonBody,
  positiveId,
} from "@/lib/api/apply-flow-bff"
import { tourApplyBody, type TourApplyResult } from "@/lib/api/tour-apply"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const id = positiveId((await params).id)
  if (!id) return invalidIdResponse()
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
    const data = await wpFetchJson<TourApplyResult>(`/tours/${id}/apply`, {
      method: "POST",
      auth: toWpAuth(session),
      headers,
      body: { ...tourApplyBody(body), accepted_terms: Boolean(body.accepted_terms) },
    })
    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "tours/apply", "We couldn't submit your applications.")
  }
}
