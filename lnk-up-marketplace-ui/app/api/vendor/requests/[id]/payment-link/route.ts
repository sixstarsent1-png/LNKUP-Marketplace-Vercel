import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { getPaymentReturnAppBaseUrl } from "@/lib/api/config"
import {
  buildPaymentHandoff,
  mapCheckoutSummary,
  type WpCheckoutSummary,
  type WpRedirectUrlResponse,
} from "@/lib/api/vendor-payment-handoff"
import { fetchVendorRequestById } from "@/lib/api/vendor-request-server"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

export async function POST(_request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to continue payment.")
    }

    const { id } = await context.params
    const auth = toWpAuth(session)
    const vendorRequest = await fetchVendorRequestById(auth, id)

    if (!vendorRequest) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't find that vendor request." },
        { status: 404 },
      )
    }

    let summary = null
    let redirect: WpRedirectUrlResponse | null = null

    if (vendorRequest.paymentId) {
      try {
        const rawSummary = await wpFetchJson<WpCheckoutSummary>(
          `/financial/checkout/${vendorRequest.paymentId}/summary`,
          { auth },
        )
        summary = mapCheckoutSummary(rawSummary)

        redirect = await wpFetchJson<WpRedirectUrlResponse>(
          `/financial/checkout/${vendorRequest.paymentId}/redirect-url`,
          {
            method: "POST",
            auth,
            body: (() => {
              const appBase = getPaymentReturnAppBaseUrl()
              if (!appBase) return undefined
              return {
                return_to: "app",
                app_base_url: appBase,
                application_id: Number(vendorRequest.id) || vendorRequest.id,
              }
            })(),
          },
        )
      } catch (inner) {
        if (inner instanceof WpFetchError && inner.status === 403) {
          return NextResponse.json(
            { code: "forbidden", message: "You don't have access to this payment." },
            { status: 403 },
          )
        }
        console.error("[vendor/requests/payment-link]", inner)
      }
    }

    const handoff = buildPaymentHandoff(vendorRequest, summary, redirect)
    return NextResponse.json(handoff)
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/requests/payment-link",
      fallbackMessage: "We couldn't start payment. Please try again.",
      fallbackCode: "payment_link_failed",
      forbiddenMessage: "You don't have access to this payment.",
    })
  }
}
