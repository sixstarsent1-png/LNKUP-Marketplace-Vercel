import { NextResponse } from "next/server"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
import { fetchLiveFunnel, parseOpsRange } from "@/lib/ops/wp-adapter"
import { getMockFunnel } from "@/lib/ops/mock-analytics"

export async function GET(request: Request) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const { searchParams } = new URL(request.url)
  const range = parseOpsRange(searchParams.get("range"))
  try {
    const data = await fetchLiveFunnel(range)
    return NextResponse.json({ code: "ok", ...data })
  } catch {
    const fallback = getMockFunnel(range)
    return NextResponse.json(
      {
        code: "intelligence_partial",
        message: "Funnel diagnostics are temporarily using fallback data.",
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
