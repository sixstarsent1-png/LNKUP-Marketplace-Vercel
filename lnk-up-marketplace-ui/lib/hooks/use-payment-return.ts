"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import {
  buildPaymentReturnDurationBucket,
  parsePaymentReturnFromSearchParams,
  type PaymentReturnOutcome,
  type PaymentReturnRoute,
  type PaymentReturnState,
} from "@/lib/payments/payment-return"

const POLL_INITIAL_INTERVAL_MS = 2000
const POLL_MAX_INTERVAL_MS = 8000
const POLL_MAX_MS = 30000
const RETRY_DEBOUNCE_MS = 1500

export function usePaymentReturn(options: {
  onRefetch?: () => void | Promise<void>
  requests?: VendorRequest[]
  source: PaymentReturnRoute
}) {
  const { onRefetch, requests = [], source } = options
  const searchParams = useSearchParams()
  const router = useRouter()
  const trackedRef = useRef<string | null>(null)
  const live = isLiveApiEnabled()
  const [processing, setProcessing] = useState(false)
  const [confirmedPaid, setConfirmedPaid] = useState(false)
  const [timedOut, setTimedOut] = useState(false)
  const [lastCheckedAt, setLastCheckedAt] = useState<number | null>(null)
  const [nextRetryAt, setNextRetryAt] = useState<number | null>(null)
  const [reconcileOutcome, setReconcileOutcome] = useState<PaymentReturnOutcome | null>(null)
  const [capturedContract, setCapturedContract] = useState<{
    state: PaymentReturnState
    applicationId: string | null
    paymentId: string | null
    sourceRoute: PaymentReturnRoute
    timestamp: number
    isTerminal: boolean
  }>({
    state: null,
    applicationId: null,
    paymentId: null,
    sourceRoute: source,
    timestamp: 0,
    isTerminal: false,
  })
  const retryingRef = useRef(false)

  const returnContract = useMemo(
    () => parsePaymentReturnFromSearchParams(searchParams, source),
    [searchParams, source],
  )
  const activeContract = capturedContract.state ? capturedContract : returnContract
  const activePaymentReturn = activeContract.state
  const activeApplicationId = activeContract.applicationId

  const matchedRequest = useMemo(() => {
    if (!activeApplicationId) return null
    return requests.find((item) => item.id === activeApplicationId) ?? null
  }, [activeApplicationId, requests])

  useEffect(() => {
    if (!returnContract.state) return
    setCapturedContract((current) =>
      current.state ? current : returnContract,
    )
  }, [returnContract])

  const clearReturnParams = useCallback(() => {
    if (!activePaymentReturn) return
    const next = new URLSearchParams(searchParams.toString())
    next.delete("payment")
    next.delete("pay")
    next.delete("applicationId")
    next.delete("paymentId")
    const qs = next.toString()
    setCapturedContract((current) => ({ ...current, state: null, applicationId: null, paymentId: null }))
    setProcessing(false)
    setTimedOut(false)
    router.replace(qs ? `?${qs}` : "?", { scroll: false })
  }, [activePaymentReturn, router, searchParams])

  const pollPaymentStatus = useCallback(async () => {
    if (!activeApplicationId) return false
    try {
      const response = await fetch(`/api/vendor/requests/${activeApplicationId}/payment`)
      const { data } = await parseApiResponse<{
        detail?: { status?: string }
        summary?: { status?: string }
        request?: { status?: string }
      }>(response)
      const status = data?.detail?.status ?? data?.summary?.status ?? data?.request?.status
      return status === "paid" || status === "completed"
    } catch {
      return false
    }
  }, [activeApplicationId])

  const reconcile = useCallback(
    async (manualRetry = false) => {
      if (!activeApplicationId || retryingRef.current) return
      retryingRef.current = true
      setTimedOut(false)
      setReconcileOutcome(null)
      const started = Date.now()
      let interval = POLL_INITIAL_INTERVAL_MS

      trackLnkupEvent({
        event: "payment_return_reconcile_started",
        source,
        requestId: activeApplicationId,
        metadata: { manual_retry: manualRetry },
      })

      while (Date.now() - started < POLL_MAX_MS) {
        setLastCheckedAt(Date.now())
        const paid = await pollPaymentStatus()
        if (paid) {
          setConfirmedPaid(true)
          setProcessing(false)
          setTimedOut(false)
          setReconcileOutcome("confirmed")
          trackLnkupEvent({
            event: "payment_return_confirmed",
            source,
            requestId: activeApplicationId,
            metadata: {
              duration_bucket: buildPaymentReturnDurationBucket(Date.now() - started),
            },
          })
          await onRefetch?.()
          retryingRef.current = false
          return
        }

        const now = Date.now()
        const sleepFor = Math.min(interval, Math.max(POLL_MAX_MS - (now - started), 0))
        setNextRetryAt(now + sleepFor)
        await new Promise((resolve) => setTimeout(resolve, sleepFor))
        interval = Math.min(Math.round(interval * 1.8), POLL_MAX_INTERVAL_MS)
      }

      setProcessing(true)
      setTimedOut(true)
      setReconcileOutcome("still_processing")
      trackLnkupEvent({
        event: "payment_return_timeout",
        source,
        requestId: activeApplicationId,
        metadata: {
          duration_bucket: buildPaymentReturnDurationBucket(Date.now() - started),
        },
      })
      retryingRef.current = false
    },
    [activeApplicationId, onRefetch, pollPaymentStatus, source],
  )

  const retryReconcile = useCallback(async () => {
    if (!activePaymentReturn || activePaymentReturn === "cancelled") return
    if (retryingRef.current) return
    trackLnkupEvent({
      event: "payment_return_manual_retry",
      source,
      requestId: activeApplicationId ?? undefined,
    })
    await new Promise((resolve) => setTimeout(resolve, RETRY_DEBOUNCE_MS))
    await onRefetch?.()
    await reconcile(true)
  }, [activePaymentReturn, source, activeApplicationId, onRefetch, reconcile])

  useEffect(() => {
    if (!activePaymentReturn) return
    const key = `${source}:${activeApplicationId ?? "unknown"}:${activePaymentReturn}`
    if (trackedRef.current === key) return
    trackedRef.current = key
    trackLnkupEvent({
      event: "payment_return_landed",
      source,
      requestId: activeApplicationId ?? undefined,
      metadata: { payment: activePaymentReturn },
    })
  }, [activePaymentReturn, activeApplicationId, source])

  useEffect(() => {
    if (activePaymentReturn !== "success" && activePaymentReturn !== "processing") return
    void onRefetch?.()
  }, [activePaymentReturn, onRefetch])

  useEffect(() => {
    if (!live && activePaymentReturn === "success") {
      setConfirmedPaid(true)
      setProcessing(false)
      if (activeApplicationId) {
        trackLnkupEvent({
          event: "payment_return_confirmed",
          source,
          requestId: activeApplicationId,
        })
      }
    }
  }, [live, activePaymentReturn, activeApplicationId, source])

  useEffect(() => {
    if ((activePaymentReturn !== "success" && activePaymentReturn !== "processing") || !activeApplicationId) return
    if (!live) return

    const requestPaid =
      matchedRequest?.status === "paid" || matchedRequest?.status === "completed"
    if (requestPaid) {
      setConfirmedPaid(true)
      setProcessing(false)
      trackLnkupEvent({
        event: "payment_return_confirmed",
        source,
        requestId: activeApplicationId,
      })
      return
    }

    setProcessing(true)
    void reconcile(false)
  }, [
    activePaymentReturn,
    activeApplicationId,
    matchedRequest?.status,
    source,
    live,
    reconcile,
  ])

  const showSuccess =
    activePaymentReturn === "success" && (confirmedPaid || matchedRequest?.status === "paid" || matchedRequest?.status === "completed")
  const showProcessing =
    (activePaymentReturn === "success" || activePaymentReturn === "processing") && processing && !showSuccess
  const showCancelled = activePaymentReturn === "cancelled"

  return {
    applicationId: activeApplicationId,
    paymentReturn: activePaymentReturn,
    returnContract: activeContract,
    reconcileOutcome,
    matchedRequest,
    showSuccess,
    showProcessing,
    showCancelled,
    timedOut,
    lastCheckedAt,
    nextRetryAt,
    clearReturnParams,
    retryReconcile,
  }
}
