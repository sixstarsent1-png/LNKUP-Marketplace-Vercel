import type { VendorDocumentRecord } from "@/lib/api/vendor-documents"
import { canUploadDocument, documentExpiry, requiredDocumentProgress } from "@/lib/vendor/document-status"

const today = new Date(2026, 10, 19)

describe("documentExpiry", () => {
  it("flags expired, soon and fine dates using local calendar days", () => {
    expect(documentExpiry("2026-11-18", today)).toBe("expired")
    expect(documentExpiry("2026-11-19", today)).toBe("soon")
    expect(documentExpiry("2026-12-19", today)).toBe("soon")
    expect(documentExpiry("2027-01-30", today)).toBe("ok")
    expect(documentExpiry(undefined, today)).toBeNull()
    expect(documentExpiry("not a date", today)).toBeNull()
  })
})

describe("requiredDocumentProgress", () => {
  const doc = (over: Partial<VendorDocumentRecord>): VendorDocumentRecord => ({
    id: "1",
    type: "coi",
    label: "Insurance",
    status: "missing",
    ...over,
  })

  it("counts approved required documents and the ones that need the vendor", () => {
    const items = [
      doc({ id: "a", required: true, status: "approved" }),
      doc({ id: "b", required: true, status: "pending" }),
      doc({ id: "c", required: true, status: "missing" }),
      doc({ id: "d", required: false, status: "rejected" }),
    ]
    expect(requiredDocumentProgress(items)).toEqual({ approved: 1, required: 3, attention: 2 })
  })

  it("only offers upload when the vendor can act", () => {
    expect(canUploadDocument(doc({ status: "approved" }))).toBe(false)
    expect(canUploadDocument(doc({ status: "expired" }))).toBe(true)
  })
})
