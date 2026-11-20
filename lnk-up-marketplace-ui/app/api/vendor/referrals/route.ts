import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  mapVendorReferrals,
  mockVendorReferrals,
  mockVendorReferralsUnavailable,
} from "@/lib/api/vendor-referrals"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { isLiveApiEnabled } from "@/lib/api/config"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockVendorReferrals)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view referrals.")
    }

    try {
      const data = await wpFetchJson("/financial/referrals", { auth: toWpAuth(session) })
      return NextResponse.json(mapVendorReferrals(data as never))
    } catch {
      return NextResponse.json(mockVendorReferralsUnavailable)
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/referrals",
      fallbackMessage: "We couldn't load referral details.",
      fallbackCode: "referrals_failed",
    })
  }
}
