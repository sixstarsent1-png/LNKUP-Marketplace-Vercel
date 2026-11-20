import { NextResponse } from "next/server"
import { wpFetchMultipart } from "@/lib/api/wp-fetch"
import { MAX_DOCUMENT_UPLOAD_BYTES } from "@/lib/api/vendor-documents"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to upload documents.")
    }

    const incoming = await request.formData()
    const file = incoming.get("file")
    const documentType = incoming.get("document_type")
    const expirationDate = incoming.get("expiration_date")

    if (!(file instanceof File)) {
      return NextResponse.json(
        { code: "validation_error", message: "Please choose a file to upload." },
        { status: 400 },
      )
    }

    if (file.size > MAX_DOCUMENT_UPLOAD_BYTES) {
      return NextResponse.json(
        { code: "validation_error", message: "File must be 10MB or smaller." },
        { status: 400 },
      )
    }

    if (!documentType || typeof documentType !== "string") {
      return NextResponse.json(
        { code: "validation_error", message: "Please select a document type." },
        { status: 400 },
      )
    }

    const wpForm = new FormData()
    wpForm.append("file", file, file.name)
    wpForm.append("document_type", documentType)
    if (expirationDate && typeof expirationDate === "string") {
      wpForm.append("expiration_date", expirationDate)
    }

    const result = await wpFetchMultipart<{ success?: boolean; document_id?: number; message?: string }>(
      "/documents/upload",
      wpForm,
      { auth: toWpAuth(session) },
    )

    return NextResponse.json({
      success: true,
      documentId: result.document_id,
      message: result.message || "Document uploaded.",
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/documents/upload",
      fallbackMessage: "Upload failed. Please try again.",
      fallbackCode: "upload_failed",
      exposeMessage: true,
    })
  }
}
