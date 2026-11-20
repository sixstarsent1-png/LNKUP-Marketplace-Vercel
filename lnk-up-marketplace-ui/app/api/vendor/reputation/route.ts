import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapWpVendorDashboard,
  mockVendorReputation,
} from "@/lib/api/vendor-reputation"
import type { WpVerificationVendor } from "@/lib/api/types"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockVendorReputation())
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your reputation.")
    }

    const auth = toWpAuth(session)

    const [dashboard, verification] = await Promise.all([
      wpFetchJson<Record<string, unknown>>("/reputation/vendor-dashboard", { auth }),
      wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth }).catch(() => null),
    ])

    const profileId = verification?.profile_id ? String(verification.profile_id) : undefined
    const result = mapWpVendorDashboard(
      dashboard as Parameters<typeof mapWpVendorDashboard>[0],
      profileId,
      verification?.badges,
    )

    return NextResponse.json(result)
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/reputation",
      fallbackMessage: "We couldn't load your reputation. Please try again.",
      fallbackCode: "reputation_failed",
      forbiddenMessage: "You don't have access to reputation data.",
    })
  }
}
