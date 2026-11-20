import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { mapSectionItems, type WpSectionResponse } from "@/lib/api/marketplace-sections"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url)
    const section = searchParams.get("section") ?? "trending"
    const limit = searchParams.get("limit") ?? "8"

    const data = await wpFetchJson<WpSectionResponse>("/marketplace/sections/trending", {
      query: { section, limit },
    })

    const items = mapSectionItems(data)
    return NextResponse.json({ section, items, total: items.length })
  } catch (error) {
    return toErrorResponse(error, {
      context: "marketplace/trending",
      fallbackMessage: "We couldn't load trending opportunities.",
      fallbackCode: "trending_failed",
    })
  }
}
