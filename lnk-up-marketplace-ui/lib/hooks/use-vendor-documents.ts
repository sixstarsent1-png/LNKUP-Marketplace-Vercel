"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorDocumentsNormalized,
  type VendorDocumentRecord,
} from "@/lib/api/vendor-documents"

export function useVendorDocuments(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<VendorDocumentRecord[]>(live ? [] : mockVendorDocumentsNormalized)
  const [loading, setLoading] = useState(live && enabled)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchDocuments = useCallback(async () => {
    if (!live) {
      setItems(mockVendorDocumentsNormalized)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/documents")
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setItems([])
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't load your documents.")
      }

      setItems((data.items ?? []) as VendorDocumentRecord[])
    } catch (err) {
      console.error("[useVendorDocuments]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your documents.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [live])

  const uploadDocument = useCallback(
    async (file: File, documentType: string, expirationDate?: string) => {
      if (!live) {
        setUploading(true)
        await new Promise((r) => setTimeout(r, 600))
        setUploading(false)
        await fetchDocuments()
        return { success: true }
      }

      setUploading(true)
      setUploadError(null)

      try {
        const form = new FormData()
        form.append("file", file)
        form.append("document_type", documentType)
        if (expirationDate) form.append("expiration_date", expirationDate)

        const response = await fetch("/api/vendor/documents/upload", {
          method: "POST",
          body: form,
        })
        const data = await response.json()

        if (response.status === 401) {
          setUploadError("Please sign in to upload documents.")
          return { success: false }
        }

        if (!response.ok) {
          throw new Error(data.message || "Upload failed. Please try again.")
        }

        await fetchDocuments()
        return { success: true }
      } catch (err) {
        const message = err instanceof Error ? err.message : "Upload failed. Please try again."
        setUploadError(message)
        return { success: false }
      } finally {
        setUploading(false)
      }
    },
    [live, fetchDocuments],
  )

  useEffect(() => {
    if (!enabled) return
    fetchDocuments()
  }, [enabled, fetchDocuments])

  return {
    items,
    loading,
    uploading,
    error,
    uploadError,
    unauthenticated,
    retry: fetchDocuments,
    uploadDocument,
    isLive: live,
  }
}
