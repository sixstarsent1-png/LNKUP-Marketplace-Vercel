import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import {
  mapGrowthDashboard,
  mockVendorGrowth,
  type WpGrowthDashboard,
} from "@/lib/api/vendor-growth"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockVendorGrowth())
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view growth.")
    }

    const payload = await wpFetchJson<WpGrowthDashboard>("/growth/dashboard", {
      auth: toWpAuth(session),
    })

    return NextResponse.json(mapGrowthDashboard(payload))
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/growth",
      fallbackMessage: "We couldn't load your growth metrics.",
      fallbackCode: "vendor_growth_failed",
    })
  }
}
