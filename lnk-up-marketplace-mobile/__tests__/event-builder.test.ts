import {
  blockingChecks,
  classifyPublish,
  isPortfolioMethod,
  mergePatch,
  nativeStepFor,
  patchFor,
  type BuilderReport,
} from "@/lib/api/event-builder"
import { importBlocked, keepableFields, providerLabel, type ImportPreview } from "@/lib/api/event-import"
import { WpFetchError } from "@/lib/api/errors"

describe("event builder patches", () => {
  it("builds nested patches from dotted paths", () => {
    expect(patchFor("event.name", "Night Market")).toEqual({ event: { name: "Night Market" } })
    expect(patchFor("portfolio.recurrence.count", 6)).toEqual({ portfolio: { recurrence: { count: 6 } } })
  })

  it("merges objects key by key and replaces lists", () => {
    const merged = mergePatch({ event: { name: "A", city: "Charlotte" }, options: [{ key: "a" }] }, { event: { name: "B" }, options: [{ key: "b" }] })
    expect(merged).toEqual({ event: { name: "B", city: "Charlotte" }, options: [{ key: "b" }] })
  })
})

describe("builder report helpers", () => {
  const report: BuilderReport = {
    progress: 60,
    publishable: false,
    summary: { types: 1, spaces: 10, free_spaces: 0, max_base_fees: 500 },
    checks: [
      { key: "attest", step: "publish", level: "required", ok: false, label: "Ownership", message: "Confirm" },
      { key: "deadline", step: "payments", level: "required", ok: false, label: "Deadline", message: "Pick" },
      { key: "name", step: "info", level: "required", ok: false, label: "Name", message: "Add" },
      { key: "image", step: "info", level: "recommended", ok: false, label: "Image", message: "Add" },
      { key: "options", step: "options", level: "required", ok: true, label: "Options", message: "" },
    ],
  }

  it("lists failing required checks in step order", () => {
    expect(blockingChecks(report).map((c) => c.key)).toEqual(["name", "deadline", "attest"])
    expect(blockingChecks(null)).toEqual([])
  })

  it("folds payments into the native applications step", () => {
    expect(nativeStepFor("payments")).toBe("applications")
    expect(nativeStepFor("info")).toBe("info")
  })

  it("recognizes multi-date methods", () => {
    expect(isPortfolioMethod("recurring")).toBe(true)
    expect(isPortfolioMethod("tour")).toBe(true)
    expect(isPortfolioMethod("single")).toBe(false)
  })
})

describe("publish results", () => {
  it("classifies single-event, portfolio and scheduled responses", () => {
    expect(classifyPublish({ published: true, event_id: 9, title: "Fest", public_url: "https://x/e", apply_url: "" })).toEqual({ kind: "published", eventId: 9, title: "Fest", publicUrl: "https://x/e", applyUrl: "" })
    expect(classifyPublish({ portfolio: true, tour_id: 4, title: "Market", dates: 5, live: 5, background: false, type: "recurring" })).toMatchObject({ kind: "portfolio", tourId: 4, dates: 5, live: 5 })
    expect(classifyPublish({ scheduled: true, publish_at: "2027-01-01T10:00:00Z" })).toEqual({ kind: "scheduled", publishAt: "2027-01-01T10:00:00Z" })
  })

  it("turns validation and booked-vendor errors into actionable results", () => {
    const notReady = new WpFetchError("Finish", "lnkup_builder_not_ready", 422, { checks: [{ key: "name", step: "info", level: "required", ok: false, label: "Name", message: "Add" }] })
    expect(classifyPublish(null, notReady)).toMatchObject({ kind: "not_ready", checks: [{ key: "name" }] })
    const confirm = new WpFetchError("Review", "lnkup_builder_confirm", 409, { review: { booked: 3 } })
    expect(classifyPublish(null, confirm)).toEqual({ kind: "confirm", booked: 3, message: "Review" })
    expect(classifyPublish(null, new WpFetchError("Nope", "lnkup_builder_conflict", 409))).toEqual({ kind: "error", message: "Nope" })
  })
})

describe("event import helpers", () => {
  const base: ImportPreview = {
    url: "https://posh.vip/e/x",
    status: "partial",
    message: "",
    provider: { key: "posh", label: "POSH", level: "B", limitations: "" },
    relation: { state: "none", message: "", event_id: 0 },
    fields: { name: "Night Market", date: "2027-01-08", description: "" },
    provenance: { name: "structured", date: "structured" },
    missing: [],
    warnings: [],
    occurrences: [],
    duplicates: [],
  }

  it("labels providers by exact domain", () => {
    expect(providerLabel("https://posh.vip/e/fest")).toBe("POSH")
    expect(providerLabel("https://charlotte.eventbrite.com/e/1")).toBe("Eventbrite")
    expect(providerLabel("https://myfest.com")).toBe("Event website")
    expect(providerLabel("not a link")).toBeNull()
  })

  it("keeps only fields that were found", () => {
    expect(keepableFields(base)).toEqual(["name", "date"])
  })

  it("blocks creating a second event for an owned or conflicting listing", () => {
    expect(importBlocked(base)).toBe(false)
    expect(importBlocked({ ...base, relation: { state: "other", message: "", event_id: 0 } })).toBe(true)
    expect(importBlocked({ ...base, relation: { state: "own", message: "", event_id: 5 } })).toBe(true)
    expect(importBlocked({ ...base, relation: { state: "unclaimed_listing", message: "", event_id: 0 } })).toBe(false)
  })
})
