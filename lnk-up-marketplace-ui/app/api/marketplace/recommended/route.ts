import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSectionItems, type WpSectionResponse } from "@/lib/api/marketplace-sections"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url)
    const limit = searchParams.get("limit") ?? "8"
    const session = await getSession()

    const data = await wpFetchJson<WpSectionResponse>("/marketplace/sections/recommended", {
      query: { limit },
      auth: session ? toWpAuth(session) : undefined,
    })

    const items = mapSectionItems(data)
    return NextResponse.json({ items, total: items.length })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/recommended",
      fallbackMessage: "We couldn't load recommendations.",
      fallbackCode: "recommended_failed",
    })
  }
}
