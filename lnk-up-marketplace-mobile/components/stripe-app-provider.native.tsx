import type { ReactNode } from "react"

/**
 * Payments run through WordPress-hosted Stripe Checkout in the in-app browser
 * (lib/payments/stripe-checkout.native.ts); the app never initialises the Stripe native SDK or
 * holds a publishable key. The `@stripe/stripe-react-native` package and its config plugin are
 * removed in the next native build (they cannot be dropped over the air).
 */
export function StripeAppProvider({ children }: { children: ReactNode }) {
  return <>{children}</>
}
