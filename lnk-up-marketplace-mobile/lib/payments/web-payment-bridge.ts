import type { WpAuth } from "@/lib/api/wp-client"

export type BoothPayResult = {
  status: "paid" | "cancelled" | "failed" | "browser"
  message?: string
}

export type WebPaymentRequest = {
  paymentId: string
  clientSecret: string
  publishableKey: string
  intentId: string
  auth: WpAuth
  title?: string
  amountLabel?: string
}

type Pending = WebPaymentRequest & {
  resolve: (result: BoothPayResult) => void
}

let pending: Pending | null = null
const listeners = new Set<() => void>()

export function getPendingWebPayment(): WebPaymentRequest | null {
  if (!pending) return null
  const { resolve: _r, ...rest } = pending
  return rest
}

export function subscribeWebPayment(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function notify() {
  listeners.forEach((listener) => listener())
}

/** Opens the in-app web Payment Element modal; resolves when closed. */
export function openWebPaymentSheet(request: WebPaymentRequest): Promise<BoothPayResult> {
  return new Promise((resolve) => {
    if (pending) {
      pending.resolve({ status: "cancelled" })
    }
    pending = { ...request, resolve }
    notify()
  })
}

export function completeWebPayment(result: BoothPayResult) {
  if (!pending) return
  const { resolve } = pending
  pending = null
  notify()
  resolve(result)
}
