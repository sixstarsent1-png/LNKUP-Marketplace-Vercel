import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorActivityList, mockVendorActivity } from "@/lib/api/vendor-financial"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockVendorActivity })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view wallet activity.")
    }

    const auth = toWpAuth(session)
    let data: unknown
    try {
      data = await wpFetchJson("/financial/activity", { auth, query: { role: "vendor" } })
    } catch {
      data = await wpFetchJson("/financial/transactions", { auth, query: { role: "vendor" } })
    }

    return NextResponse.json({ items: mapVendorActivityList(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet/activity",
      fallbackMessage: "We couldn't load wallet activity. Please try again.",
      fallbackCode: "vendor_wallet_activity_failed",
      forbiddenMessage: "You don't have access to wallet activity.",
    })
  }
}
