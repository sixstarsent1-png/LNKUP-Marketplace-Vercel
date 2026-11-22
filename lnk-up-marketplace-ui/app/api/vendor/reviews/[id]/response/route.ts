import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

/**
 * Vendor posts (or edits, within the window) one public response to an organizer review.
 * WordPress enforces ownership, the feature flag and the edit window; the original review is never changed.
 */
export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  try {
    const { id } = await context.params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "validation_error", message: "Invalid review." }, { status: 400 })
    }
    const body = (await request.json().catch(() => ({}))) as { text?: string }
    const text = (body.text ?? "").trim()
    if (text.length < 2 || text.length > 2000) {
      return NextResponse.json(
        { code: "validation_error", message: "Responses must be between 2 and 2,000 characters." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ success: true, review: { id: Number(id), vendor_response: { text } } })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to respond to reviews.")
    }

    const result = await wpFetchJson<Record<string, unknown>>(`/reputation/reviews/${id}/response`, {
      method: "POST",
      body: { text },
      auth: toWpAuth(session),
    })
    return NextResponse.json(result)
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/reviews/response",
      fallbackMessage: "We couldn't save your response. Please try again.",
      fallbackCode: "review_response_failed",
      forbiddenMessage: "You can only respond to reviews about your business.",
    })
  }
}
