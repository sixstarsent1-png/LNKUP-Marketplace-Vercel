import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse, invalidIdResponse, positiveId } from "@/lib/api/apply-flow-bff"
import { getSession, toWpAuth } from "@/lib/auth/session"

type WpWithdrawResult = { application_id: number; status: string; status_label: string }

export async function POST(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const id = positiveId((await params).id)
  if (!id) return invalidIdResponse()
  const session = await getSession()
  if (!session) return authRequiredResponse()
  try {
    const data = await wpFetchJson<WpWithdrawResult>(`/applications/${id}/withdraw`, {
      method: "POST",
      auth: toWpAuth(session),
      body: {},
    })
    return NextResponse.json(data)
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/withdraw", "We couldn't withdraw this application.")
  }
}
