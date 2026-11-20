import { useEffect, useState } from "react"
import { Redirect, useLocalSearchParams, type Href } from "expo-router"

import { LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { invalidateVendorRequests } from "@/lib/api/vendor-requests"
import { consumeStripeRedirectApplicationId } from "@/lib/payments/pending-stripe-redirect"

/**
 * Return URL from hosted checkout (`lnkup://stripe-redirect?payment=…&applicationId=…`).
 * Prefers the application id in the link, then the one stashed before checkout.
 * The Pass screen re-reads payment status from the server; this route trusts nothing.
 */
export default function StripeRedirectScreen() {
  const params = useLocalSearchParams<{ payment?: string; applicationId?: string }>()
  const [href, setHref] = useState<Href | null>(null)

  useEffect(() => {
    let active = true
    invalidateVendorRequests()
    void (async () => {
      const stashed = await consumeStripeRedirectApplicationId()
      if (!active) return
      const applicationId = String(params.applicationId ?? "") || stashed
      const payment = params.payment === "cancelled" ? "cancelled" : "success"
      if (applicationId) {
        setHref(`/pass/${applicationId}?payment=${payment}` as Href)
      } else {
        setHref("/(vendor)/wallet")
      }
    })()
    return () => {
      active = false
    }
  }, [params.applicationId, params.payment])

  if (!href) {
    return (
      <Screen title="Returning…" subtitle="Finishing payment">
        <LoadingPanel label="Returning from payment…" />
      </Screen>
    )
  }

  return <Redirect href={href} />
}
