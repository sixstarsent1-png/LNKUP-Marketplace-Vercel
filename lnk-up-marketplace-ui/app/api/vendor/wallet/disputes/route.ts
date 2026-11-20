import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapVendorDisputesList, mockVendorDisputes } from "@/lib/api/vendor-financial"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockVendorDisputes })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view disputes.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson("/financial/disputes", { auth, query: { role: "vendor" } })

    return NextResponse.json({ items: mapVendorDisputesList(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet/disputes",
      fallbackMessage: "We couldn't load disputes. Please try again.",
      fallbackCode: "vendor_wallet_disputes_failed",
      forbiddenMessage: "You don't have access to disputes.",
    })
  }
}

export async function POST(request: Request) {
  try {
    const body = (await request.json().catch(() => ({}))) as {
      paymentId?: string | number
      payment_id?: string | number
      reason?: string
    }
    const paymentId = Number(body.paymentId ?? body.payment_id ?? 0)
    const reason = String(body.reason ?? "").trim()

    if (!paymentId || reason.length < 10) {
      return NextResponse.json(
        {
          error: {
            code: "invalid_dispute",
            message: "Payment and a reason (at least 10 characters) are required.",
          },
        },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      const created = {
        id: `dsp-mock-${Date.now()}`,
        paymentId: String(paymentId),
        status: "open",
        isOpen: true,
        reason,
        amount: 0,
        currency: "USD",
        source: "vendor",
        createdAt: new Date().toISOString().slice(0, 10),
      }
      return NextResponse.json({ id: created.id, dispute: created }, { status: 201 })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to open a dispute.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<{ id?: number; dispute?: Record<string, unknown> }>(
      "/financial/disputes",
      {
        auth,
        method: "POST",
        body: { payment_id: paymentId, reason },
      },
    )

    const dispute = data?.dispute
      ? mapVendorDisputesList([data.dispute])[0]
      : mapVendorDisputesList([{ ...(data as object), id: data?.id, payment_id: paymentId, reason, status: "open" }])[0]

    return NextResponse.json({ id: dispute?.id ?? data?.id, dispute }, { status: 201 })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/wallet/disputes",
      fallbackMessage: "We couldn't open this dispute. Please try again.",
      fallbackCode: "vendor_wallet_dispute_create_failed",
      forbiddenMessage: "You don't have permission to dispute this payment.",
    })
  }
}
