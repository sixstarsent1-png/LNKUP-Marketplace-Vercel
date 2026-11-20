"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorInvoices,
  mockVendorReceipts,
  type VendorInvoiceOrReceipt,
} from "@/lib/api/vendor-financial"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useVendorWalletInvoices(enabled = true) {
  const live = isLiveApiEnabled()
  const [invoices, setInvoices] = useState<VendorInvoiceOrReceipt[]>([])
  const [receipts, setReceipts] = useState<VendorInvoiceOrReceipt[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchDocuments = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setInvoices(mockVendorInvoices)
      setReceipts(mockVendorReceipts)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const [invoiceRes, receiptRes] = await Promise.all([
        fetch("/api/vendor/wallet/invoices"),
        fetch("/api/vendor/wallet/receipts"),
      ])

      const invoiceParsed = await parseApiResponse<{ items: VendorInvoiceOrReceipt[] }>(invoiceRes)
      const receiptParsed = await parseApiResponse<{ items: VendorInvoiceOrReceipt[] }>(receiptRes)

      if (invoiceParsed.error) throw new Error(invoiceParsed.error.message)
      if (receiptParsed.error) throw new Error(receiptParsed.error.message)

      setInvoices(invoiceParsed.data?.items ?? [])
      setReceipts(receiptParsed.data?.items ?? [])
    } catch (err) {
      console.error("[useVendorWalletInvoices]", err)
      setError(err instanceof Error ? err.message : "We couldn't load invoices and receipts.")
      setInvoices([])
      setReceipts([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchDocuments()
  }, [fetchDocuments])

  return { invoices, receipts, loading, error, retry: fetchDocuments, isLive: live }
}
