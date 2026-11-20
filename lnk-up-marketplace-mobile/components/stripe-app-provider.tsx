import type { ReactNode } from "react"

/**
 * Web / default: no Stripe native import (avoids Metro resolve errors).
 */
export function StripeAppProvider({ children }: { children: ReactNode }) {
  return <>{children}</>
}
