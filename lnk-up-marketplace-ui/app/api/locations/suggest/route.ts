import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

/** City/state autocomplete from the WordPress Location Service (public reference data). */
export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const q = (searchParams.get("q") ?? "").slice(0, 80)
  const limit = Math.min(15, Math.max(1, Number(searchParams.get("limit") ?? 8) || 8))
  if (q.trim().length < 2) {
    return NextResponse.json({ results: [] })
  }
  try {
    const data = await wpFetchJson<{ results?: unknown[] }>(
      `/locations/suggest?q=${encodeURIComponent(q)}&limit=${limit}`,
    )
    return NextResponse.json(
      { results: Array.isArray(data?.results) ? data.results : [] },
      { headers: { "Cache-Control": "public, max-age=3600" } },
    )
  } catch (error) {
    return toErrorResponse(error, {
      context: "locations/suggest",
      fallbackMessage: "Location lookup is unavailable right now.",
      fallbackCode: "location_lookup_failed",
    })
  }
}
