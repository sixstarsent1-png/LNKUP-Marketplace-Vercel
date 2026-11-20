import { NextResponse } from "next/server"
import { mapPublicVendorReputation } from "@/lib/api/vendor-reputation"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

type RouteContext = { params: Promise<{ slug: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { slug } = await context.params
    const data = await wpFetchJson<Record<string, unknown>>(`/reputation/vendors/${slug}/public`)

    const reputation = mapPublicVendorReputation(data)
    return NextResponse.json(reputation)
  } catch (error) {
    return toErrorResponse(error, {
      context: "public/vendor/reputation",
      fallbackMessage: "This vendor profile is not available.",
      fallbackCode: "public_reputation_failed",
    })
  }
}
