import { useCallback, useEffect, useRef, useState } from "react"
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  View,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { confirmCheckout } from "@/lib/api/vendor-payment"
import {
  completeWebPayment,
  getPendingWebPayment,
  subscribeWebPayment,
  type BoothPayResult,
  type WebPaymentRequest,
} from "@/lib/payments/web-payment-bridge"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

type StripeInstance = {
  elements: (opts: { clientSecret: string; appearance?: object }) => StripeElements
  confirmPayment: (opts: {
    elements: StripeElements
    confirmParams: { return_url: string }
    redirect: "if_required"
  }) => Promise<{
    error?: { message?: string; code?: string }
    paymentIntent?: { status?: string }
  }>
}

type StripeElements = {
  create: (type: string, opts?: object) => StripeElement
  submit: () => Promise<{ error?: { message?: string } }>
}

type StripeElement = {
  mount: (el: HTMLElement) => void
  unmount: () => void
  destroy?: () => void
}

declare global {
  interface Window {
    Stripe?: (key: string) => StripeInstance
  }
}

function loadStripeJs(): Promise<void> {
  if (typeof window === "undefined") return Promise.reject(new Error("No window"))
  if (window.Stripe) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>('script[data-lnkup-stripe="1"]')
    if (existing) {
      existing.addEventListener("load", () => resolve())
      existing.addEventListener("error", () => reject(new Error("Stripe.js failed to load")))
      return
    }
    const script = document.createElement("script")
    script.src = "https://js.stripe.com/v3/"
    script.async = true
    script.dataset.lnkupStripe = "1"
    script.onload = () => resolve()
    script.onerror = () => reject(new Error("Stripe.js failed to load"))
    document.head.appendChild(script)
  })
}

/**
 * Web-only host: in-app Stripe Payment Element via CDN (avoids Metro resolving
 * @stripe/react-stripe-js / .mjs exports).
 */
export function WebPaymentHost() {
  const [request, setRequest] = useState<WebPaymentRequest | null>(null)

  useEffect(() => {
    return subscribeWebPayment(() => {
      setRequest(getPendingWebPayment())
    })
  }, [])

  if (!request) return null

  return (
    <Modal
      visible
      animationType="slide"
      transparent
      onRequestClose={() => completeWebPayment({ status: "cancelled" })}
    >
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <Text style={styles.headerTitle}>Pay securely</Text>
            <Pressable onPress={() => completeWebPayment({ status: "cancelled" })} hitSlop={8}>
              <Text style={styles.close}>Close</Text>
            </Pressable>
          </View>
          {request.title ? <Text style={styles.event}>{request.title}</Text> : null}
          {request.amountLabel ? <Text style={styles.amount}>{request.amountLabel}</Text> : null}
          <CdnCheckoutForm request={request} />
        </View>
      </View>
    </Modal>
  )
}

function CdnCheckoutForm({ request }: { request: WebPaymentRequest }) {
  const mountRef = useRef<View>(null)
  const stripeRef = useRef<StripeInstance | null>(null)
  const elementsRef = useRef<StripeElements | null>(null)
  const paymentElRef = useRef<StripeElement | null>(null)
  const [ready, setReady] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    async function setup() {
      try {
        await loadStripeJs()
        if (cancelled || !window.Stripe) {
          throw new Error("Stripe.js unavailable")
        }
        const stripe = window.Stripe(request.publishableKey)
        stripeRef.current = stripe
        const elements = stripe.elements({
          clientSecret: request.clientSecret,
          appearance: {
            theme: "stripe",
            variables: {
              colorPrimary: "#0A0A0A",
              borderRadius: "12px",
            },
          },
        })
        elementsRef.current = elements
        const paymentElement = elements.create("payment", { layout: "tabs" })
        paymentElRef.current = paymentElement

        // RN Web: find the DOM node for our mount View.
        const node = mountRef.current as unknown as { _nativeNode?: HTMLElement } | HTMLElement | null
        const el =
          node && typeof (node as HTMLElement).appendChild === "function"
            ? (node as HTMLElement)
            : (node as { _nativeNode?: HTMLElement } | null)?._nativeNode

        // Prefer query by data attribute after a tick (more reliable on RN web).
        await new Promise((r) => setTimeout(r, 0))
        const host =
          el ||
          document.querySelector<HTMLElement>("[data-lnkup-stripe-mount='1']")
        if (!host) {
          throw new Error("Could not mount payment form.")
        }
        // Clear any previous children
        host.innerHTML = ""
        paymentElement.mount(host)
        if (!cancelled) setReady(true)
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Could not load checkout.")
        }
      }
    }

    void setup()

    return () => {
      cancelled = true
      try {
        paymentElRef.current?.unmount()
        paymentElRef.current?.destroy?.()
      } catch {
        // ignore
      }
      paymentElRef.current = null
      elementsRef.current = null
      stripeRef.current = null
    }
  }, [request.clientSecret, request.publishableKey])

  const handlePay = useCallback(async () => {
    const stripe = stripeRef.current
    const elements = elementsRef.current
    if (!stripe || !elements) return

    setBusy(true)
    setError(null)
    try {
      const { error: submitError } = await elements.submit()
      if (submitError) {
        setError(submitError.message || "Check your payment details.")
        setBusy(false)
        return
      }

      const returnUrl =
        typeof window !== "undefined"
          ? `${window.location.origin}/(vendor)/wallet`
          : "https://thelnkup.com"

      const { error: confirmError, paymentIntent } = await stripe.confirmPayment({
        elements,
        confirmParams: { return_url: returnUrl },
        redirect: "if_required",
      })

      if (confirmError) {
        setError(confirmError.message || "Payment failed.")
        setBusy(false)
        return
      }

      const status = paymentIntent?.status
      if (status && status !== "succeeded" && status !== "processing") {
        setError("Payment was not completed.")
        setBusy(false)
        return
      }

      try {
        await confirmCheckout(request.paymentId, request.intentId, request.auth)
      } catch (err) {
        setError(err instanceof Error ? err.message : "Could not finalize payment.")
        setBusy(false)
        return
      }

      completeWebPayment({ status: "paid" } satisfies BoothPayResult)
    } catch (err) {
      setError(err instanceof Error ? err.message : "Payment unavailable.")
      setBusy(false)
    }
  }, [request])

  return (
    <View style={styles.form}>
      <View
        ref={mountRef}
        // RN-web: expose a stable DOM hook for Stripe Element mount
        {...({
          dataSet: { lnkupStripeMount: "1" },
          "data-lnkup-stripe-mount": "1",
        } as object)}
        nativeID="lnkup-stripe-mount"
        style={styles.elementWrap}
      />
      {!ready && !error ? <ActivityIndicator color={colors.black} /> : null}
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Pressable
        style={[styles.payBtn, (!ready || busy) && styles.disabled]}
        disabled={!ready || busy}
        onPress={() => void handlePay()}
      >
        {busy ? (
          <ActivityIndicator color={colors.white} />
        ) : (
          <Text style={styles.payText}>
            {request.amountLabel ? `Pay ${request.amountLabel}` : "Pay now"}
          </Text>
        )}
      </Pressable>
    </View>
  )
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.45)",
    justifyContent: "flex-end",
  },
  sheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: radii.xl,
    borderTopRightRadius: radii.xl,
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
    maxHeight: "92%",
    gap: spacing.md,
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  headerTitle: {
    fontSize: typeScale.headline,
    fontWeight: "800",
    color: colors.black,
  },
  close: {
    fontSize: typeScale.body,
    fontWeight: "600",
    color: colors.gray700,
  },
  event: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "700",
    color: colors.black,
  },
  amount: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.black,
    marginTop: -spacing.sm,
  },
  form: {
    gap: spacing.md,
  },
  elementWrap: {
    minHeight: 200,
    width: "100%",
  },
  error: {
    fontSize: typeScale.footnote,
    color: colors.red600,
  },
  payBtn: {
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  payText: {
    color: colors.white,
    fontWeight: "700",
    fontSize: typeScale.callout,
  },
  disabled: {
    opacity: 0.6,
  },
})
