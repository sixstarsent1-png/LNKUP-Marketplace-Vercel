import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mockOpportunitiesAsEvents } from "@/lib/api/marketplace-discovery-mock"

export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const limit = parseInt(searchParams.get("limit") ?? "8", 10) || 8

  if (!isLiveApiEnabled()) {
    const items = mockOpportunitiesAsEvents().slice(0, limit)
    return NextResponse.json({ section: "most_saved", items, total: items.length, mocked: true })
  }

  return NextResponse.json({
    section: "most_saved",
    items: [],
    total: 0,
    unavailable: true,
  })
}
