import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { applyFlowErrorResponse } from "@/lib/api/apply-flow-bff"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  const session = await getSession()
  if (!session) return authRequiredResponse()
  try {
    return NextResponse.json(await wpFetchJson("/applications/drafts", { auth: toWpAuth(session) }))
  } catch (error) {
    return applyFlowErrorResponse(error, "applications/drafts/list", "We couldn't load your saved applications.")
  }
}
