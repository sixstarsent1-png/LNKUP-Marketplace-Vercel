import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapVendorRequestList } from "@/lib/api/vendor-requests"
import type { WpVendorApplicationsResponse } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your vendor requests.")
    }

    const data = await wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", {
      auth: toWpAuth(session),
    })

    const items = mapVendorRequestList(data)
    return NextResponse.json({
      items,
      total: data.total ?? items.length,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/requests",
      fallbackMessage: "We couldn't load your vendor requests. Please try again.",
      fallbackCode: "requests_failed",
    })
  }
}
