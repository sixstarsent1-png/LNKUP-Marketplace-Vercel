import {
  canWithdraw,
  mapApplyContext,
  mapTourApplyContext,
  newIdempotencyKey,
  reapplyNote,
  tourApplyBody,
} from "@/lib/api/apply"
import type { TourStop } from "@/lib/api/match"
import { tourStopCta } from "@/lib/vendor/tour-stop-cta"

function stop(opportunities: TourStop["opportunities"]): TourStop {
  return {
    eventId: 10,
    title: "Charlotte stop",
    date: "2026-12-01",
    city: "Charlotte",
    state: "NC",
    accepting: true,
    matchesMe: true,
    feeMin: 100,
    feeMax: 100,
    opportunities,
  }
}

function slot(extra: Partial<TourStop["opportunities"][number]> = {}): TourStop["opportunities"][number] {
  return { opportunityId: 5, title: "Booth", isOpen: true, applicationStatus: "", canApplyAgain: false, spotsRemaining: 3, ...extra }
}

describe("apply context mapping", () => {
  it("maps questions, draft, previous application and feature flags", () => {
    const ctx = mapApplyContext(5, {
      opportunity: { opportunity_id: 5, name: "Night Market" },
      eligibility: { can_apply: true },
      questions: [{ id: "q1", label: "Power?", type: "select", required: true, options: ["Yes", "No"], help: "" }],
      draft: { id: 9, revision: 3, fields: { selected_category: "Food", application_message: "Hi", setup_notes: "10x10" }, answers: { q1: "Yes" } },
      previous_application: { id: 4, status: "cancelled", reapply: "allowed", reason: "withdrawn" },
      tour_id: 77,
      features: { drafts: true, tour_multi_apply: false },
    })
    expect(ctx.questions).toHaveLength(1)
    expect(ctx.draft).toEqual({
      id: 9,
      revision: 3,
      updatedAt: "",
      fields: { selectedCategory: "Food", message: "Hi", setupNotes: "10x10" },
      answers: { q1: "Yes" },
    })
    expect(ctx.tourId).toBe(77)
    expect(ctx.features).toEqual({ drafts: true, tourMultiApply: false })
    expect(reapplyNote(ctx.previousApplication)).toMatch(/withdrew/)
  })

  it("defaults safely when the server sends none of the new fields", () => {
    const ctx = mapApplyContext(5, { eligibility: { can_apply: true } })
    expect(ctx.questions).toEqual([])
    expect(ctx.draft).toBeNull()
    expect(ctx.previousApplication).toBeNull()
    expect(ctx.features).toEqual({ drafts: false, tourMultiApply: false })
  })

  it("has no re-apply note while a decline still blocks", () => {
    expect(reapplyNote({ id: 1, status: "rejected", reapply: "blocked", reason: "declined" })).toBeNull()
    expect(reapplyNote({ id: 1, status: "rejected", reapply: "allowed", reason: "invited_again" })).toMatch(/invited/)
  })
})

describe("withdraw and idempotency", () => {
  it("only offers withdraw before payment", () => {
    expect(canWithdraw("under_review")).toBe(true)
    expect(canWithdraw("payment_required", "pending")).toBe(true)
    expect(canWithdraw("payment_required", "processing")).toBe(false)
    expect(canWithdraw("paid")).toBe(false)
    expect(canWithdraw("rejected")).toBe(false)
  })

  it("creates server-valid idempotency keys that differ per attempt", () => {
    const a = newIdempotencyKey()
    expect(a).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect(newIdempotencyKey()).not.toBe(a)
  })
})

describe("tour multi-stop apply", () => {
  const ctx = mapTourApplyContext({
    tour: {
      id: 3,
      title: "Summer tour",
      stops: [
        { event_id: 10, city: "Charlotte", state: "NC", opportunities: [{ opportunity_id: 5, can_apply: true, categories: ["Food"] }] },
        { event_id: 11, city: "Atlanta", state: "GA", opportunities: [{ opportunity_id: 6, can_apply: false, block_reason: "previously_declined" }] },
      ],
    },
    draft: { group_id: "grp12345", revision: 2, payload: { selections: [{ opportunity_id: 5 }], shared: { application_message: "Hi" } } },
  })

  it("flattens stops into slots and restores the saved group", () => {
    expect(ctx.slots.map((s) => [s.stopLabel, s.canApply])).toEqual([
      ["Charlotte, NC", true],
      ["Atlanta, GA", false],
    ])
    expect(ctx.draft?.selections).toEqual([5])
    expect(ctx.draft?.message).toBe("Hi")
  })

  it("sends only selected slots with their event IDs", () => {
    const body = tourApplyBody({ groupId: "grp12345", slots: ctx.slots, selected: [5], message: "Hi", setupNotes: "", perStop: {} })
    expect(body.selections).toEqual([{ event_id: 10, opportunity_id: 5 }])
    expect(body.group_id).toBe("grp12345")
  })
})

describe("tour stop action", () => {
  it("applies to an open stop", () => {
    expect(tourStopCta(stop([slot()]))).toEqual({ kind: "apply", opportunityId: 5, label: "Apply to this stop" })
  })

  it("shows the status of an active application", () => {
    expect(tourStopCta(stop([slot({ applicationStatus: "under_review" })]))).toEqual({
      kind: "status",
      label: "Application: under review",
    })
  })

  it("offers Apply again after a withdrawal or a re-opened decline", () => {
    expect(tourStopCta(stop([slot({ applicationStatus: "cancelled", canApplyAgain: true })]))).toMatchObject({
      kind: "apply",
      label: "Apply again",
    })
  })

  it("keeps a declined stop closed", () => {
    expect(tourStopCta(stop([slot({ applicationStatus: "rejected" })]))).toEqual({ kind: "status", label: "Application: rejected" })
  })
})
