import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type CashoutBody = {
  amount?: number
  type?: string
}

export async function POST(request: Request) {
  try {
    const body = (await request.json().catch(() => ({}))) as CashoutBody
    const amount = Number(body.amount ?? 0)
    const type = body.type === "instant" ? "instant" : "standard"

    if (!Number.isFinite(amount) || amount <= 0) {
      return NextResponse.json(
        { code: "invalid_amount", message: "Enter a valid cashout amount." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        success: true,
        message: "Cashout requested (demo).",
        amount,
        type,
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const result = await wpFetchJson<Record<string, unknown>>("/financial/organizer-wallet/cashout", {
      method: "POST",
      auth,
      body: { amount, type },
    })

    return NextResponse.json(result)
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/financial/cashout",
      fallbackMessage: "Cashout could not be completed. Please try again.",
      fallbackCode: "organizer_cashout_failed",
      forbiddenMessage: "This action is for organizers.",
    })
  }
}
