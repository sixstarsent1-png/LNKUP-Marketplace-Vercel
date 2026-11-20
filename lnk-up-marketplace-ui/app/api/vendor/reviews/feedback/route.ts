import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { buildFeedbackHandoff } from "@/lib/api/vendor-reputation"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as {
      applicationId?: string
      message?: string
    }

    if (!body.applicationId?.trim()) {
      return NextResponse.json(
        { code: "validation_error", message: "Application ID is required." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        status: "submitted",
        applicationId: body.applicationId,
        message: "Thanks — your feedback was recorded in demo mode.",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to submit feedback.")
    }

    return NextResponse.json(buildFeedbackHandoff())
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/reviews/feedback",
      fallbackMessage: "We couldn't submit your feedback. Please try again.",
      fallbackCode: "feedback_failed",
    })
  }
}
