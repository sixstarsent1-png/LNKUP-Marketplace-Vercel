import { NextResponse } from "next/server"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
import { fetchLiveSummary, parseOpsRange } from "@/lib/ops/wp-adapter"
import { getMockSummary } from "@/lib/ops/mock-analytics"

export async function GET(request: Request) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const { searchParams } = new URL(request.url)
  const range = parseOpsRange(searchParams.get("range"))
  const city = searchParams.get("city") ?? undefined
  const category = searchParams.get("category") ?? undefined
  const status = searchParams.get("status") ?? undefined

  try {
    const data = await fetchLiveSummary(range, { city, category, status })
    return NextResponse.json({ code: "ok", ...data })
  } catch {
    const fallback = getMockSummary(range)
    return NextResponse.json(
      {
        code: "intelligence_partial",
        message: "Summary diagnostics are temporarily using fallback data.",
        ...fallback,
        meta: {
          ...fallback.meta,
          partial: true,
          unavailableReasons: ["LNKup intelligence upstream unavailable."],
        },
      },
      { status: 200 },
    )
  }
}
