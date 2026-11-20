import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import type { WpApplyContext } from "@/lib/api/types"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
) {
  try {
    const { id } = await context.params
    const opportunityId = parseInt(id, 10)
    if (!opportunityId) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid opportunity ID" },
        { status: 400 },
      )
    }

    const session = await getSession()
    const data = await wpFetchJson<WpApplyContext>(`/applications/apply-context/${opportunityId}`, {
      auth: session ? toWpAuth(session) : undefined,
    })

    return NextResponse.json(data)
  } catch (error) {
    if (error instanceof WpFetchError) {
      return NextResponse.json(
        { code: error.code, message: error.message, data: error.data },
        { status: error.status || 502 },
      )
    }
    return toErrorResponse(error, {
      context: "applications/apply-context",
      fallbackMessage: "Failed to load apply context.",
      fallbackCode: "apply_context_failed",
    })
  }
}
