import * as WebBrowser from "expo-web-browser"

import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { fetchPaymentHandoff } from "@/lib/api/vendor-payment"
import { invalidateVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { stashStripeRedirectApplicationId } from "@/lib/payments/pending-stripe-redirect"

import type { BoothPayResult } from "@/lib/payments/web-payment-bridge"

export type { BoothPayResult }

const RETURN_URL = "lnkup://stripe-redirect"

/**
 * Native Phase 26: Financial OS handoff only.
 * Opens WP/Stripe hosted checkout via redirect-url — no Payment Sheet / Stripe SDK as SOR.
 * Never uses Apple IAP.
 *
 * Stripe returns to an https bounce on WordPress that redirects to lnkup://stripe-redirect,
 * which closes the auth session. The redirect only says the vendor finished checkout; the
 * caller must re-read payment status from the server before showing the booking as paid.
 */
export async function payBoothFee(
  request: VendorRequest,
  auth?: WpAuth,
): Promise<BoothPayResult> {
  if (!request.paymentId) {
    return {
      status: "failed",
      message: "Payment isn’t ready yet. Open the event pass and try again.",
    }
  }

  if (!isLiveApiEnabled()) {
    await new Promise((resolve) => setTimeout(resolve, 400))
    return { status: "paid", message: "Mock payment complete." }
  }

  if (!auth) {
    return { status: "failed", message: "Sign in to pay." }
  }

  return openBrowserHandoff(request, auth)
}

function returnState(url: string): string | null {
  const query = url.split("?")[1] ?? ""
  for (const pair of query.split("&")) {
    const [key, value] = pair.split("=")
    if (key === "payment") return decodeURIComponent(value ?? "")
  }
  return null
}

async function openBrowserHandoff(
  request: VendorRequest,
  auth?: WpAuth,
): Promise<BoothPayResult> {
  try {
    await stashStripeRedirectApplicationId(request.id)
    const handoff = await fetchPaymentHandoff(request, auth, "native")
    if (handoff.status === "already_paid") {
      return { status: "paid", message: handoff.message }
    }
    const url = handoff.checkoutUrl || handoff.wordpressUrl
    if (!url) {
      return {
        status: "failed",
        message: handoff.message || "Could not open checkout.",
      }
    }

    const result = await WebBrowser.openAuthSessionAsync(url, RETURN_URL)
    invalidateVendorRequests()

    if (result.type === "success") {
      if (returnState(result.url) === "cancelled") {
        return { status: "cancelled" }
      }
      return {
        status: "browser",
        message: "Confirming your payment…",
      }
    }

    if (result.type === "cancel" || result.type === "dismiss") {
      return {
        status: "browser",
        message: "If you finished paying, your booking updates in a moment.",
      }
    }

    return {
      status: "browser",
      message:
        handoff.message ||
        "Finish payment in the browser. Bank transfers may show as processing until funds clear.",
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : "Could not open checkout."
    return { status: "failed", message }
  }
}
