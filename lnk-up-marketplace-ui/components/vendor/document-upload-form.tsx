"use client"

import { useCallback, useState } from "react"
import { Upload, Loader2, FileUp } from "lucide-react"
import {
  ALLOWED_DOCUMENT_MIMES,
  DOCUMENT_TYPE_OPTIONS,
  MAX_DOCUMENT_UPLOAD_BYTES,
} from "@/lib/api/vendor-documents"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"

type DocumentUploadFormProps = {
  uploading: boolean
  error?: string | null
  onUpload: (file: File, documentType: string, expirationDate?: string) => Promise<{ success: boolean }>
}

export function DocumentUploadForm({ uploading, error, onUpload }: DocumentUploadFormProps) {
  const [documentType, setDocumentType] = useState("")
  const [expirationDate, setExpirationDate] = useState("")
  const [file, setFile] = useState<File | null>(null)
  const [validationError, setValidationError] = useState<string | null>(null)
  const [dragOver, setDragOver] = useState(false)

  const validateFile = useCallback((candidate: File): string | null => {
    if (!ALLOWED_DOCUMENT_MIMES.includes(candidate.type)) {
      return "Please upload a PDF, JPG, or PNG file."
    }
    if (candidate.size > MAX_DOCUMENT_UPLOAD_BYTES) {
      return "File must be 10MB or smaller."
    }
    return null
  }, [])

  function handleFileSelect(candidate: File | null) {
    if (!candidate) return
    const err = validateFile(candidate)
    setValidationError(err)
    setFile(err ? null : candidate)
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setValidationError(null)

    if (!documentType) {
      setValidationError("Please select a document type.")
      return
    }
    if (!file) {
      setValidationError("Please choose a file to upload.")
      return
    }

    const err = validateFile(file)
    if (err) {
      setValidationError(err)
      return
    }

    const result = await onUpload(file, documentType, expirationDate || undefined)
    if (result.success) {
      setFile(null)
      setExpirationDate("")
      setDocumentType("")
    }
  }

  const displayError = validationError || error

  return (
    <form
      onSubmit={handleSubmit}
      className="flex flex-col gap-4 rounded-2xl border bg-card p-4"
      data-testid="document-upload-form"
    >
      <div>
        <h3 className="font-heading text-sm font-semibold">Upload document</h3>
        <p className="text-xs text-muted-foreground">Upload once. Reuse across events.</p>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="document-type">Document type</Label>
        <Select value={documentType} onValueChange={setDocumentType}>
          <SelectTrigger id="document-type" className="w-full">
            <SelectValue placeholder="Select type" />
          </SelectTrigger>
          <SelectContent>
            {DOCUMENT_TYPE_OPTIONS.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div
        className={cn(
          "flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-8 text-center transition-colors",
          dragOver && "border-primary bg-muted/50",
        )}
        onDragOver={(e) => {
          e.preventDefault()
          setDragOver(true)
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault()
          setDragOver(false)
          const dropped = e.dataTransfer.files?.[0]
          if (dropped) handleFileSelect(dropped)
        }}
      >
        <FileUp className="size-8 text-muted-foreground" />
        <p className="text-sm font-medium">
          {file ? file.name : "Drag & drop or choose a file"}
        </p>
        <p className="text-xs text-muted-foreground">PDF, JPG, or PNG · Max 10MB</p>
        <label htmlFor="document-file" className="cursor-pointer">
          <span className="inline-flex h-8 items-center justify-center rounded-lg border bg-background px-3 text-sm font-medium hover:bg-muted">
            Browse files
          </span>
          <Input
            id="document-file"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png"
            className="sr-only"
            onChange={(e) => handleFileSelect(e.target.files?.[0] ?? null)}
          />
        </label>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="expiration-date">Expiration date (optional)</Label>
        <Input
          id="expiration-date"
          type="date"
          value={expirationDate}
          onChange={(e) => setExpirationDate(e.target.value)}
        />
      </div>

      {displayError && (
        <p className="text-sm text-destructive" role="alert" data-testid="document-upload-error">
          {displayError}
        </p>
      )}

      <Button type="submit" disabled={uploading} data-testid="document-upload-submit">
        {uploading ? (
          <>
            <Loader2 className="animate-spin" data-icon="inline-start" />
            Uploading…
          </>
        ) : (
          <>
            <Upload data-icon="inline-start" />
            Upload document
          </>
        )}
      </Button>
    </form>
  )
}
