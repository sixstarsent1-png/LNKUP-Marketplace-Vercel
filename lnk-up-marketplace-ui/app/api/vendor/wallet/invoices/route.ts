import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorInvoicesList, mockVendorInvoices } from "@/lib/api/vendor-financial"
import { wpFetchAllPages } from "@/lib/api/wp-paged"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockVendorInvoices })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view invoices.")
    }

    const auth = toWpAuth(session)
    const { items, truncated } = await wpFetchAllPages<Record<string, unknown>>("/financial/invoices", {
      auth,
      query: { role: "vendor" },
    })

    return NextResponse.json({ items: mapVendorInvoicesList(items), truncated })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet/invoices",
      fallbackMessage: "We couldn't load invoices. Please try again.",
      fallbackCode: "vendor_wallet_invoices_failed",
      forbiddenMessage: "You don't have access to invoices.",
    })
  }
}
