import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapVendorReferrals } from "@/lib/api/vendor-referrals"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { isLiveApiEnabled } from "@/lib/api/config"

export async function POST() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        available: true,
        code: "DEMO2026",
        shareUrl: "https://thelnkup.com/?ref=DEMO2026",
        mocked: true,
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to get your referral link.")
    }

    const data = await wpFetchJson("/financial/referrals/code", {
      method: "POST",
      auth: toWpAuth(session),
    })

    return NextResponse.json(mapVendorReferrals({ code: data as never }))
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/referrals/invite",
      fallbackMessage: "We couldn't generate a referral link.",
      fallbackCode: "referrals_invite_failed",
    })
  }
}
