import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse, invalidIdResponse, positiveId } from "@/lib/api/apply-flow-bff"
import { mapTourApplyContext, type WpTourApplyContext } from "@/lib/api/tour-apply"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const id = positiveId((await params).id)
  if (!id) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse("Please sign in to apply.")
  try {
    const data = await wpFetchJson<WpTourApplyContext>(`/tours/${id}/apply-context`, { auth: toWpAuth(session) })
    return NextResponse.json(mapTourApplyContext(data))
  } catch (error) {
    return applyFlowErrorResponse(error, "tours/apply-context", "We couldn't load this tour's application.")
  }
}
