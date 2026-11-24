import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

/** Validate one city/state pair (verified, normalized, suggested, ambiguous, unresolved). */
export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const params = new URLSearchParams({
    city: (searchParams.get("city") ?? "").slice(0, 120),
    state: (searchParams.get("state") ?? "").slice(0, 60),
    country: (searchParams.get("country") ?? "").slice(0, 60),
  })
  try {
    const data = await wpFetchJson<Record<string, unknown>>(`/locations/resolve?${params.toString()}`)
    return NextResponse.json(data, { headers: { "Cache-Control": "public, max-age=3600" } })
  } catch (error) {
    return toErrorResponse(error, {
      context: "locations/resolve",
      fallbackMessage: "Location lookup is unavailable right now.",
      fallbackCode: "location_lookup_failed",
    })
  }
}
