import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mapVendorWalletSummary,
  mockVendorWallet,
} from "@/lib/api/vendor-financial"
import { filterPaymentRequired, mapVendorRequestList } from "@/lib/api/vendor-requests"
import type { WpVendorApplicationsResponse } from "@/lib/api/types"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockVendorWallet())
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your wallet.")
    }

    const auth = toWpAuth(session)

    const [summaryData, dashboardData, applicationsData] = await Promise.all([
      wpFetchJson("/financial/summary", { auth, query: { role: "vendor" } }).catch(() => ({})),
      wpFetchJson("/financial/dashboard", { auth, query: { role: "vendor" } }).catch(() => ({})),
      wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", { auth }),
    ])

    const requests = mapVendorRequestList(applicationsData)
    const paymentRequired = filterPaymentRequired(requests)
    const summary = mapVendorWalletSummary(summaryData, dashboardData, requests)

    return NextResponse.json({ summary, paymentRequired })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet",
      fallbackMessage: "We couldn't load your wallet. Please try again.",
      fallbackCode: "vendor_wallet_failed",
      forbiddenMessage: "You don't have access to wallet data.",
    })
  }
}
