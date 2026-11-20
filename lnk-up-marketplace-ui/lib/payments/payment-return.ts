"use client"

export type PaymentReturnRoute = "vendor_requests" | "vendor_wallet" | "vendor_pass"
export type PaymentReturnState = "success" | "cancelled" | "processing" | null
export type PaymentReturnOutcome = "confirmed" | "still_processing" | "cancelled" | "failed_refresh"

export type PaymentReturnContract = {
  state: PaymentReturnState
  applicationId: string | null
  paymentId: string | null
  sourceRoute: PaymentReturnRoute
  timestamp: number
  isTerminal: boolean
}

export function parsePaymentReturnFromSearchParams(
  searchParams: URLSearchParams,
  sourceRoute: PaymentReturnRoute,
): PaymentReturnContract {
  const payment = searchParams.get("payment")
  const normalizedState: PaymentReturnState =
    payment === "success" || payment === "cancelled" || payment === "processing" ? payment : null

  return {
    state: normalizedState,
    applicationId: searchParams.get("applicationId") ?? searchParams.get("pay"),
    paymentId: searchParams.get("paymentId"),
    sourceRoute,
    timestamp: Date.now(),
    isTerminal: normalizedState === "success" || normalizedState === "cancelled",
  }
}

export function buildPaymentReturnDurationBucket(durationMs: number): string {
  if (durationMs < 5_000) return "lt_5s"
  if (durationMs < 15_000) return "5s_15s"
  if (durationMs < 30_000) return "15s_30s"
  return "gte_30s"
}

