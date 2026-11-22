import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse, invalidIdResponse, jsonBody, positiveId } from "@/lib/api/apply-flow-bff"
import { getSession, toWpAuth } from "@/lib/auth/session"

type Params = { params: Promise<{ opp: string }> }

export async function GET(_request: Request, { params }: Params) {
  const opp = positiveId((await params).opp)
  if (!opp) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse()
  try {
    return NextResponse.json(await wpFetchJson(`/applications/drafts/${opp}`, { auth: toWpAuth(session) }))
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/drafts/get", "We couldn't load your saved application.")
  }
}

export async function PUT(request: Request, { params }: Params) {
  const opp = positiveId((await params).opp)
  if (!opp) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse()
  const body = await jsonBody(request)
  try {
    const data = await wpFetchJson(`/applications/drafts/${opp}`, {
      method: "PUT",
      auth: toWpAuth(session),
      body: {
        step: body.step,
        fields: body.fields,
        answers: body.answers,
        document_ids: body.document_ids,
        invite_token: body.invite_token,
        revision: body.revision,
        client: "web",
      },
    })
    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/drafts/save", "We couldn't save your progress.")
  }
}

export async function DELETE(_request: Request, { params }: Params) {
  const opp = positiveId((await params).opp)
  if (!opp) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse()
  try {
    return NextResponse.json(await wpFetchJson(`/applications/drafts/${opp}`, { method: "DELETE", auth: toWpAuth(session) }))
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/drafts/delete", "We couldn't discard this draft.")
  }
}
