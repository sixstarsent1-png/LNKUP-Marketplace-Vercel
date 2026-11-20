import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

type PricingBody = {
  vendor_fee?: number
  spots_total?: number
}

export async function POST(request: Request, context: RouteContext) {
  try {
    const { id } = await context.params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json(
        { code: "invalid_opportunity", message: "Invalid opportunity." },
        { status: 400 },
      )
    }

    const body = (await request.json().catch(() => ({}))) as PricingBody
    const vendorFee = Number(body.vendor_fee)
    const spotsTotal = Number(body.spots_total)

    if (!Number.isFinite(vendorFee) || vendorFee < 0) {
      return NextResponse.json(
        { code: "invalid_fee", message: "Vendor fee cannot be negative." },
        { status: 400 },
      )
    }
    if (!Number.isFinite(spotsTotal) || spotsTotal < 1) {
      return NextResponse.json(
        { code: "invalid_spots", message: "At least one vendor spot is required." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        opportunity_id: Number(id),
        vendor_fee: vendorFee,
        spots_total: spotsTotal,
        spots_remaining: spotsTotal,
        message: "Vendor fee and spots updated (demo).",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const result = await wpFetchJson<{
      opportunity_id?: number
      vendor_fee?: number
      spots_total?: number
      spots_remaining?: number
      message?: string
    }>(`/organizer/opportunities/${id}/pricing`, {
      auth,
      method: "POST",
      body: {
        vendor_fee: vendorFee,
        spots_total: spotsTotal,
      },
    })

    return NextResponse.json({
      opportunity_id: Number(result.opportunity_id ?? id),
      vendor_fee: Number(result.vendor_fee ?? vendorFee),
      spots_total: Number(result.spots_total ?? spotsTotal),
      spots_remaining: Number(result.spots_remaining ?? spotsTotal),
      message: result.message ?? "Vendor fee and spots updated.",
    })
  } catch (error) {
    if (error instanceof WpFetchError) {
      return toErrorResponse(error, {
        context: "organizer/opportunities/pricing",
        fallbackMessage: "Could not update fee and spots.",
        fallbackCode: "pricing_failed",
      })
    }
    return toErrorResponse(error, {
      context: "organizer/opportunities/pricing",
      fallbackMessage: "Could not update fee and spots.",
      fallbackCode: "pricing_failed",
    })
  }
}

export async function PATCH(request: Request, context: RouteContext) {
  return POST(request, context)
}
