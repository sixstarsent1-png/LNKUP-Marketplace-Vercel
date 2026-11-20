import type { VendorRequest } from "@/lib/api/vendor-requests"
import { getVendorDashboardPaymentsUrl } from "@/lib/api/config"
import {
  mapPaymentTimeline,
  mapVendorPaymentDetail,
  type VendorPaymentDetail,
  type VendorPaymentTimelineStep,
} from "@/lib/api/vendor-financial"

export type { VendorPaymentDetail, VendorPaymentTimelineStep }
export { mapPaymentTimeline, mapVendorPaymentDetail }

export type VendorPaymentHandoffStatus =
  | "ready"
  | "already_paid"
  | "not_required"
  | "unavailable"
  | "auth_required"
  | "error"

export type VendorPaymentSummary = {
  paymentId?: string
  status?: string
  statusLabel?: string
  vendorFee?: number
  lnkupFee?: number
  processingFee?: number
  tax?: number
  total?: number
  currency?: string
  eventName?: string
  dueAt?: string
  raw?: unknown
}

export type VendorPaymentHandoff = {
  requestId: string
  applicationId?: string
  paymentId?: string
  status: VendorPaymentHandoffStatus
  checkoutUrl?: string
  amountDue?: number
  currency?: string
  dueAt?: string
  message?: string
  summary?: VendorPaymentSummary
  raw?: unknown
}

export type WpCheckoutSummary = {
  payment_id?: number
  status?: string
  status_label?: string
  event?: { title?: string }
  breakdown?: {
    vendor_fee?: number
    vendor_checkout_fee?: number
    processing_fee?: number
    tax?: number
    total?: number
  }
}

export type WpRedirectUrlResponse = {
  status?: string
  checkout_url?: string
  payment_id?: number
  message?: string
}

export function mapCheckoutSummary(data: WpCheckoutSummary): VendorPaymentSummary {
  const breakdown = data.breakdown ?? {}
  return {
    paymentId: data.payment_id ? String(data.payment_id) : undefined,
    status: data.status,
    statusLabel: data.status_label,
    vendorFee: breakdown.vendor_fee,
    lnkupFee: breakdown.vendor_checkout_fee,
    processingFee: breakdown.processing_fee,
    tax: breakdown.tax,
    total: breakdown.total,
    currency: "USD",
    eventName: data.event?.title,
  }
}

export function buildPaymentHandoff(
  request: VendorRequest,
  summary?: VendorPaymentSummary | null,
  redirect?: WpRedirectUrlResponse | null,
): VendorPaymentHandoff {
  const paymentId = request.paymentId ?? summary?.paymentId
  const amountDue = request.amountDue ?? summary?.total
  const dueAt = request.paymentDueAt

  if (!paymentId && (request.status === "approved" || request.status === "payment_required")) {
    return {
      requestId: request.id,
      applicationId: request.id,
      status: "unavailable",
      amountDue,
      currency: request.currency ?? "USD",
      dueAt,
      message: "Your approval is recorded, but payment details are not ready yet.",
      summary: summary ?? undefined,
    }
  }

  if (request.status === "paid" || request.status === "completed" || redirect?.status === "already_paid") {
    return {
      requestId: request.id,
      applicationId: request.id,
      paymentId,
      status: "already_paid",
      amountDue,
      currency: request.currency ?? "USD",
      dueAt,
      message: "Payment is complete. Your event pass is ready.",
      summary: summary ?? undefined,
    }
  }

  if (redirect?.status === "ready" && redirect.checkout_url) {
    return {
      requestId: request.id,
      applicationId: request.id,
      paymentId,
      status: "ready",
      checkoutUrl: redirect.checkout_url,
      amountDue,
      currency: request.currency ?? "USD",
      dueAt,
      summary: summary ?? undefined,
    }
  }

  const fallbackUrl = getVendorDashboardPaymentsUrl()
  return {
    requestId: request.id,
    applicationId: request.id,
    paymentId,
    status: fallbackUrl ? "ready" : "unavailable",
    checkoutUrl: fallbackUrl || undefined,
    amountDue,
    currency: request.currency ?? "USD",
    dueAt,
    message: fallbackUrl
      ? "Continue payment on LNKup to secure your spot."
      : "We couldn't load payment details. Try again or continue from your LNKup dashboard.",
    summary: summary ?? undefined,
    raw: redirect,
  }
}

export const mockPaymentSummary: VendorPaymentSummary = {
  paymentId: "pay-mock-2",
  status: "pending",
  statusLabel: "Payment required",
  vendorFee: 320,
  lnkupFee: 25,
  total: 345,
  currency: "USD",
  eventName: "Atlanta Food Fest",
}

export const mockPaymentHandoffReady: VendorPaymentHandoff = {
  requestId: "req-2",
  applicationId: "req-2",
  paymentId: "pay-mock-2",
  status: "ready",
  checkoutUrl: "https://checkout.stripe.com/c/pay/mock",
  amountDue: 345,
  currency: "USD",
  dueAt: "Aug 1, 2026",
  summary: mockPaymentSummary,
}
