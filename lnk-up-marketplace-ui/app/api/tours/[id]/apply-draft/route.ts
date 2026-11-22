import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse, invalidIdResponse, jsonBody, positiveId } from "@/lib/api/apply-flow-bff"
import { tourApplyBody } from "@/lib/api/tour-apply"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function PUT(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const id = positiveId((await params).id)
  if (!id) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse()
  const body = await jsonBody(request)
  try {
    const data = await wpFetchJson(`/tours/${id}/apply-draft`, {
      method: "PUT",
      auth: toWpAuth(session),
      body: { ...tourApplyBody(body), revision: typeof body.revision === "number" ? body.revision : null },
    })
    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "tours/apply-draft", "We couldn't save your progress.")
  }
}
