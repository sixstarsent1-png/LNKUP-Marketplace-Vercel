import { NextResponse } from "next/server"
import { mapOrganizerVendorDirectory } from "@/lib/api/organizer-crm"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view vendors.")
    }

    const auth = toWpAuth(session)
    const url = new URL(request.url)
    const query: Record<string, string> = { html: "0" }
    const q = url.searchParams.get("q")
    const category = url.searchParams.get("category")
    const city = url.searchParams.get("city")
    const sort = url.searchParams.get("sort")
    const verified = url.searchParams.get("verified")
    if (q) query.q = q
    if (category) query.category = category
    if (city) query.city = city
    if (sort) query.sort = sort
    if (verified === "1" || verified === "true") query.verified = "1"

    const data = await wpFetchJson<Record<string, unknown>>("/crm/vendors", { auth, query })
    const items = mapOrganizerVendorDirectory(data)
    return NextResponse.json({
      code: "ok",
      items,
      total: items.length,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "organizer/vendors/directory",
      fallbackMessage: "We couldn't load the vendor directory. Please try again.",
      fallbackCode: "organizer_vendor_directory_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}

