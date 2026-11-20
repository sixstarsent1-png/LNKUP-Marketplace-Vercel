import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  buildVendorPaymentDetailFromCheckout,
  mapPaymentTimeline,
} from "@/lib/api/vendor-financial"
import {
  mapCheckoutSummary,
  mockPaymentSummary,
  type WpCheckoutSummary,
} from "@/lib/api/vendor-payment-handoff"
import { fetchVendorRequestById } from "@/lib/api/vendor-request-server"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

function findMockRequest(id: string) {
  const item = mockVendorRequests.find((r) => r.id === id)
  if (!item) return null
  return {
    id: item.id,
    eventId: item.eventId,
    eventName: item.eventName,
    eventImage: item.eventImage,
    eventDate: item.date,
    city: item.city,
    status: mockStatusToNormalized(item.status),
    vendorFee: item.vendorFee,
    amountDue: item.vendorFee,
    currency: "USD",
    paymentId: item.paymentId,
    paymentDueAt: item.paymentDueAt,
  }
}

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { id } = await context.params

    if (!isLiveApiEnabled()) {
      const request = findMockRequest(id)
      if (!request) {
        return NextResponse.json(
          { code: "not_found", message: "We couldn't find that vendor request." },
          { status: 404 },
        )
      }
      const summary = request.status === "paid" ? null : mockPaymentSummary
      const detail = buildVendorPaymentDetailFromCheckout(request, summary ?? mockPaymentSummary, [
        { id: "approved", label: "Approved", status: "complete" },
        { id: "payment", label: "Payment required", status: "current" },
      ])
      if (request.status === "paid") {
        detail.status = "paid"
        detail.receiptUrl = "/vendor/wallet"
      }
      return NextResponse.json({
        summary,
        detail,
        request: {
          id: request.id,
          paymentId: request.paymentId,
          status: request.status,
          amountDue: request.amountDue,
          paymentDueAt: request.paymentDueAt,
          eventName: request.eventName,
        },
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view payment details.")
    }

    const auth = toWpAuth(session)
    const vendorRequest = await fetchVendorRequestById(auth, id)

    if (!vendorRequest) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that vendor request." },
        { status: 404 },
      )
    }

    if (!vendorRequest.paymentId) {
      return NextResponse.json(
        {
          code: "payment_processing",
          summary: null,
          detail: null,
          request: {
            id: vendorRequest.id,
            status: vendorRequest.status,
            amountDue: vendorRequest.amountDue,
            paymentDueAt: vendorRequest.paymentDueAt,
            eventName: vendorRequest.eventName,
          },
          message: "Your approval is recorded, but payment details are not ready yet.",
        },
        { status: 202 },
      )
    }

    const summaryRaw = await wpFetchJson<WpCheckoutSummary>(
      `/financial/checkout/${vendorRequest.paymentId}/summary`,
      { auth },
    )
    const summary = mapCheckoutSummary(summaryRaw)

    let timelineData: unknown = null
    let receiptUrl: string | undefined
    let invoiceUrl: string | undefined

    try {
      timelineData = await wpFetchJson(`/financial/payments/${vendorRequest.paymentId}/timeline`, { auth })
    } catch {
      timelineData = null
    }

    const timeline = timelineData ? mapPaymentTimeline(timelineData) : undefined
    const timelineRecord = (timelineData && typeof timelineData === "object" ? timelineData : {}) as Record<string, unknown>
    receiptUrl = timelineRecord.receipt_url ? String(timelineRecord.receipt_url) : undefined
    invoiceUrl = timelineRecord.invoice_url ? String(timelineRecord.invoice_url) : undefined

    const detail = buildVendorPaymentDetailFromCheckout(vendorRequest, summaryRaw, timelineData ?? undefined)
    if (receiptUrl) detail.receiptUrl = receiptUrl
    if (invoiceUrl) detail.invoiceUrl = invoiceUrl
    if (timeline) detail.timeline = timeline

    return NextResponse.json({
      code:
        detail.status === "paid"
          ? "payment_confirmed"
          : detail.status === "pending" || detail.status === "payment_required"
            ? "payment_processing"
            : "payment_state_unknown",
      summary,
      detail,
      request: {
        id: vendorRequest.id,
        paymentId: vendorRequest.paymentId,
        status: vendorRequest.status,
        amountDue: vendorRequest.amountDue,
        paymentDueAt: vendorRequest.paymentDueAt,
        eventName: vendorRequest.eventName,
      },
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/requests/payment",
      fallbackMessage: "We couldn't load payment details. Please try again.",
      fallbackCode: "payment_failed",
      forbiddenMessage: "You don't have access to this payment.",
    })
  }
}
