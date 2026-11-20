import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        success: true,
        url: "https://connect.stripe.com/express/demo",
        message: "Stripe Express is ready (demo).",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const result = await wpFetchJson<{
      success?: boolean
      url?: string
      message?: string
    }>("/financial/connect/login-link", {
      method: "POST",
      auth,
      body: {},
    })

    if (!result?.url) {
      return NextResponse.json(
        {
          code: "login_link_unavailable",
          message: result?.message ?? "Could not open payout account. Finish payout setup first.",
        },
        { status: 400 },
      )
    }

    return NextResponse.json({
      success: true,
      url: result.url,
      message: result.message ?? "Stripe Express is ready.",
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/financial/login-link",
      fallbackMessage: "Could not open your payout account.",
      fallbackCode: "connect_login_link_failed",
      forbiddenMessage: "This action is for organizers.",
    })
  }
}
