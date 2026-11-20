import { getVendorPaymentsUrl, isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import type { VendorRequest } from "@/lib/api/vendor-requests"

export type PaymentHandoff = {
  checkoutUrl?: string
  wordpressUrl: string
  status: "ready" | "fallback" | "already_paid"
  message: string
}

export type EmbeddedCheckoutPrepare = {
  mode: "embedded"
  clientSecret: string
  intentId: string
  publishableKey: string
}

type WpRedirectUrlResponse = {
  status?: string
  checkout_url?: string
  redirect_url?: string
  url?: string
  message?: string
}

type WpPrepareResponse = {
  mode?: string
  client_secret?: string
  intent_id?: string
  publishable_key?: string
}

type WpConfirmResponse = {
  status?: string
  message?: string
}

/**
 * Ask WordPress for a hosted checkout URL.
 * `native` returns through lnkup://stripe-redirect; `app` returns to the web app.
 */
export async function fetchPaymentHandoff(
  request: VendorRequest,
  auth?: WpAuth,
  returnTo: "native" | "app" = "app",
): Promise<PaymentHandoff> {
  const fallbackUrl = getVendorPaymentsUrl()

  if (!isLiveApiEnabled()) {
    return {
      status: "fallback",
      wordpressUrl: fallbackUrl,
      message: "Mock mode — open LNKup payments on the web to complete checkout.",
      checkoutUrl: fallbackUrl,
    }
  }

  if (!auth || !request.paymentId) {
    return {
      status: "fallback",
      wordpressUrl: fallbackUrl,
      message: "Continue payment on LNKup.",
    }
  }

  try {
    const redirect = await wpFetchJson<WpRedirectUrlResponse>(
      `/financial/checkout/${request.paymentId}/redirect-url`,
      {
        method: "POST",
        auth,
        body: {
          return_to: returnTo,
          application_id: Number(request.id) || request.id,
        },
      },
    )

    if (redirect.status === "already_paid") {
      return {
        status: "already_paid",
        wordpressUrl: fallbackUrl,
        message: "This booking is already confirmed.",
      }
    }

    if (redirect.status === "unavailable" && redirect.message) {
      return {
        status: "fallback",
        wordpressUrl: "",
        message: redirect.message,
      }
    }

    const checkoutUrl =
      redirect.checkout_url || redirect.redirect_url || redirect.url || undefined

    if (checkoutUrl) {
      return {
        status: "ready",
        checkoutUrl,
        wordpressUrl: fallbackUrl,
        message: "Opening secure checkout…",
      }
    }
  } catch {
    // fall through to WordPress payments page
  }

  return {
    status: "fallback",
    wordpressUrl: fallbackUrl,
    checkoutUrl: fallbackUrl,
    message: "Open LNKup to finish payment in the browser.",
  }
}

/**
 * Prepare embedded Stripe PaymentIntent for Payment Sheet.
 * Returns null when the server cannot provide a client_secret.
 */
export async function prepareCheckout(
  paymentId: string,
  auth: WpAuth,
): Promise<EmbeddedCheckoutPrepare | null> {
  const data = await wpFetchJson<WpPrepareResponse>(
    `/financial/checkout/${paymentId}/prepare`,
    {
      method: "POST",
      auth,
      body: {},
    },
  )

  const clientSecret = data.client_secret
  const intentId = data.intent_id
  const publishableKey = data.publishable_key

  if (!clientSecret || !intentId || !publishableKey) {
    return null
  }

  return {
    mode: "embedded",
    clientSecret,
    intentId,
    publishableKey,
  }
}

/** Finalize payment after Payment Sheet succeeds. */
export async function confirmCheckout(
  paymentId: string,
  intentId: string,
  auth: WpAuth,
): Promise<WpConfirmResponse> {
  return wpFetchJson<WpConfirmResponse>(`/financial/checkout/${paymentId}/confirm`, {
    method: "POST",
    auth,
    body: { intent_id: intentId },
  })
}
