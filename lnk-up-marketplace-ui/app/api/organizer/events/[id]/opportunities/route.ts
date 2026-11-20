import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

type OpportunityRowBody = {
  categories?: string[]
  vendor_category?: string
  category?: string
  vendor_fee?: number
  spots_total?: number
  opportunity_title?: string
}

type AddOpportunitiesBody = {
  opportunities?: OpportunityRowBody[]
  categories?: string[]
  vendor_fee?: number
  spots_total?: number
  opportunity_title?: string
  vendor_category?: string
  application_deadline?: string
  organizer_notes?: string
  document_mode?: string
}

function normalizeRows(body: AddOpportunitiesBody): OpportunityRowBody[] {
  if (Array.isArray(body.opportunities) && body.opportunities.length > 0) {
    return body.opportunities
  }
  const category =
    body.vendor_category ??
    body.opportunity_title ??
    (Array.isArray(body.categories) ? body.categories[0] : undefined)
  if (!category && body.vendor_fee == null && body.spots_total == null) {
    return []
  }
  return [
    {
      categories: body.categories ?? (category ? [category] : []),
      vendor_category: category,
      vendor_fee: body.vendor_fee,
      spots_total: body.spots_total,
      opportunity_title: body.opportunity_title ?? category,
    },
  ]
}

export async function POST(request: Request, context: RouteContext) {
  try {
    const { id } = await context.params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json(
        { code: "not_found", message: "This event could not be loaded." },
        { status: 404 },
      )
    }

    const body = (await request.json().catch(() => ({}))) as AddOpportunitiesBody
    const rows = normalizeRows(body)
    if (rows.length === 0) {
      return NextResponse.json(
        { code: "missing_fee_rows", message: "Add at least one vendor type with a booth price." },
        { status: 400 },
      )
    }

    const opportunities = rows.map((row) => {
      const category =
        row.vendor_category ??
        row.category ??
        (Array.isArray(row.categories) ? row.categories[0] : "") ??
        ""
      const categories =
        Array.isArray(row.categories) && row.categories.length > 0
          ? row.categories
          : category
            ? [category]
            : []
      return {
        categories,
        vendor_category: category || categories[0] || "",
        vendor_fee: Number(row.vendor_fee ?? 0),
        spots_total: Number(row.spots_total ?? 1),
        opportunity_title: row.opportunity_title || category || categories[0] || "Vendor opportunity",
      }
    })

    for (const row of opportunities) {
      if (!row.categories.length) {
        return NextResponse.json(
          { code: "missing_fee_rows", message: "Each opportunity needs a vendor category." },
          { status: 400 },
        )
      }
      if (!Number.isFinite(row.vendor_fee) || row.vendor_fee < 0) {
        return NextResponse.json(
          { code: "invalid_fee", message: "Vendor fee cannot be negative." },
          { status: 400 },
        )
      }
      if (!Number.isFinite(row.spots_total) || row.spots_total < 1) {
        return NextResponse.json(
          { code: "invalid_spots", message: "At least one vendor spot is required." },
          { status: 400 },
        )
      }
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        message: "Opportunity added (demo).",
        event_id: Number(id),
        opportunity_id: 9001,
        opportunity_ids: opportunities.map((_, index) => 9001 + index),
        opportunities: opportunities.map((row, index) => ({
          opportunity_id: 9001 + index,
          vendor_fee: row.vendor_fee,
          spots_total: row.spots_total,
          title: row.opportunity_title,
        })),
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in as an organizer to continue.")
    }

    const auth = toWpAuth(session)
    const payload: Record<string, unknown> = {
      opportunities,
    }
    if (body.application_deadline) payload.application_deadline = body.application_deadline
    if (body.organizer_notes) payload.organizer_notes = body.organizer_notes
    if (body.document_mode) payload.document_mode = body.document_mode

    const result = await wpFetchJson<Record<string, unknown>>(`/organizer/events/${id}/opportunities`, {
      method: "POST",
      auth,
      body: payload,
    })

    return NextResponse.json(result)
  } catch (error) {
    if (error instanceof WpFetchError) {
      return toErrorResponse(error, {
        context: "organizer/events/opportunities",
        fallbackMessage: "Could not add vendor opportunities.",
        fallbackCode: "add_opportunities_failed",
        forbiddenMessage: "You don't have access to this event.",
      })
    }
    return toErrorResponse(error, {
      context: "organizer/events/opportunities",
      fallbackMessage: "Could not add vendor opportunities.",
      fallbackCode: "add_opportunities_failed",
    })
  }
}
