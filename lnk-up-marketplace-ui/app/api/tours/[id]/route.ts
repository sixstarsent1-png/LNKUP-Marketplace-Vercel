import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { mapTourDetail, type WpTourDetail } from "@/lib/api/match"

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "not_found", message: "Tour not found." }, { status: 404 })
    }
    const session = await getSession()
    const data = await wpFetchJson<WpTourDetail>(`/tours/${id}`, {
      auth: session ? toWpAuth(session) : undefined,
    })
    return NextResponse.json({ tour: mapTourDetail(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "tours/detail",
      fallbackMessage: "We couldn't load this tour.",
      fallbackCode: "tour_failed",
    })
  }
}
