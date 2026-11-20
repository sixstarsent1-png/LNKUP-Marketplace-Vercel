import * as WebBrowser from "expo-web-browser"

import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { fetchPaymentHandoff } from "@/lib/api/vendor-payment"
import { invalidateVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { stashStripeRedirectApplicationId } from "@/lib/payments/pending-stripe-redirect"
import type { BoothPayResult } from "@/lib/payments/web-payment-bridge"

export type { BoothPayResult }

/**
 * Expo web Phase 26: Financial OS handoff only (no embedded Payment Element as SOR).
 * Never uses Apple IAP.
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

  try {
    await stashStripeRedirectApplicationId(request.id)
    const handoff = await fetchPaymentHandoff(request, auth)
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

    const result = await WebBrowser.openBrowserAsync(url)
    if (result.type === "cancel" || result.type === "dismiss") {
      return { status: "cancelled" }
    }

    return {
      status: "browser",
      message:
        handoff.message ||
        "Finish payment on LNKup. Bank transfers may stay processing until funds clear.",
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : "Could not open checkout."
    return { status: "failed", message }
  } finally {
    // The booking may have changed on LNKup while checkout was open; never serve the pre-payment copy.
    invalidateVendorRequests()
  }
}
