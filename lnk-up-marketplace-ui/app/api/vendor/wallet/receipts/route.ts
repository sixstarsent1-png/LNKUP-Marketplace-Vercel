import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorReceiptsList, mockVendorReceipts } from "@/lib/api/vendor-financial"
import { wpFetchAllPages } from "@/lib/api/wp-paged"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockVendorReceipts })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view receipts.")
    }

    const auth = toWpAuth(session)
    const { items, truncated } = await wpFetchAllPages<Record<string, unknown>>("/financial/receipts", {
      auth,
      query: { role: "vendor" },
    })

    return NextResponse.json({ items: mapVendorReceiptsList(items), truncated })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet/receipts",
      fallbackMessage: "We couldn't load receipts. Please try again.",
      fallbackCode: "vendor_wallet_receipts_failed",
      forbiddenMessage: "You don't have access to receipts.",
    })
  }
}
