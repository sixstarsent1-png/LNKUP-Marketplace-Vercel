import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapVendorDocumentsResponse } from "@/lib/api/vendor-documents"
import type { WpVendorDocumentsResponse } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your documents.")
    }

    const data = await wpFetchJson<WpVendorDocumentsResponse>("/documents/vendor", {
      auth: toWpAuth(session),
    })

    const items = mapVendorDocumentsResponse(data)
    return NextResponse.json({
      items,
      total: items.length,
      compliance: data.compliance,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/documents",
      fallbackMessage: "We couldn't load your documents. Please try again.",
      fallbackCode: "documents_failed",
    })
  }
}
