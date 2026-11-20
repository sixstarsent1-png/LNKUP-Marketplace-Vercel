import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import type { OrganizerReviewAction } from "@/lib/api/organizer-requests"
import { fetchOrganizerRequestById } from "@/lib/api/organizer-request-server"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function POST(request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to review vendor requests.")
    }

    const { id } = await context.params
    const body = (await request.json()) as OrganizerReviewAction
    const action = body.action

    if (!action || !["approve", "waitlist", "reject"].includes(action)) {
      return NextResponse.json(
        { code: "validation_error", message: "Invalid review action." },
        { status: 400 },
      )
    }

    if (action === "reject" && !body.reason?.trim()) {
      return NextResponse.json(
        { code: "validation_error", message: "Please provide a reason when rejecting." },
        { status: 400 },
      )
    }

    const auth = toWpAuth(session)
    const vendorRequest = await fetchOrganizerRequestById(auth, id)

    if (!vendorRequest) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that vendor request." },
        { status: 404 },
      )
    }

    if (!vendorRequest.eventId) {
      return NextResponse.json(
        { code: "wordpress_unavailable", message: "Review actions are available in the LNKup web dashboard." },
        { status: 503 },
      )
    }

    const payload: Record<string, unknown> = {
      application_ids: [Number(id)],
      action,
    }
    if (body.reason) {
      payload.rejection_reason = body.reason
    }
    if (body.internalNote) {
      payload.internal_note = body.internalNote
    }

    await wpFetchJson<{ success?: boolean }>(
      `/crm/events/${vendorRequest.eventId}/roster/bulk`,
      { method: "POST", auth, body: payload },
    )

    return NextResponse.json({
      code: "review_updated",
      success: true,
      applicationId: id,
      status: action,
    })
  } catch (error) {
    if (error instanceof WpFetchError && (error.status === 503 || error.code === "unavailable")) {
      return NextResponse.json(
        { code: "wordpress_unavailable", message: "Review actions are available in the LNKup web dashboard." },
        { status: 503 },
      )
    }
    return toErrorResponse(error, {
      context: "organizer/requests/review",
      fallbackMessage: "We couldn't update this request. Please try again.",
      fallbackCode: "review_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
