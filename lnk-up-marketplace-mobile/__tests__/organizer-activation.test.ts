import { activationChecklist, mapOrganizerActivation, mapOrganizerHomeSummary } from "@/lib/api/organizer-home"

const stage = (key: string, status: string, missing: string[] = []) => ({
  key,
  title: key.replace(/_/g, " "),
  status,
  missing,
  items: [],
  cta_label: "Go",
  cta_url: "https://example.test",
})

const activationPayload = {
  success: true,
  data: {
    percent: 33,
    completed: 2,
    total: 6,
    is_activated: false,
    current_stage: "first_event",
    next_action: { stage: "first_event", title: "Create your first event", label: "Create event", missing: ["Vendor categories"] },
    share: null,
    stages: [
      stage("organization", "complete"),
      stage("payouts", "complete"),
      stage("first_event", "in_progress", ["Vendor categories"]),
      stage("publication", "not_started"),
      stage("first_applications", "not_started"),
      stage("first_paid_booking", "not_started"),
    ],
  },
}

describe("organizer activation mapper", () => {
  it("maps the WordPress activation payload", () => {
    const a = mapOrganizerActivation(activationPayload)
    expect(a?.percent).toBe(33)
    expect(a?.stages.map((s) => s.status)).toEqual(["complete", "complete", "in_progress", "not_started", "not_started", "not_started"])
    expect(a?.nextAction?.missing).toEqual(["Vendor categories"])
    expect(a?.applicationUrl).toBeUndefined()
  })

  it("returns undefined for responses without stages (older WordPress)", () => {
    expect(mapOrganizerActivation({ code: "rest_no_route" })).toBeUndefined()
    expect(mapOrganizerActivation(undefined)).toBeUndefined()
  })

  it("turns stages into checklist items the home screen already routes", () => {
    const a = mapOrganizerActivation(activationPayload)!
    const items = activationChecklist(a, [{ id: "x", key: "booth_fee", label: "", complete: false, eventId: "42" }])
    expect(items.map((i) => i.key)).toEqual([
      "public_profile",
      "payout_account",
      "booth_fee",
      "vendor_categories",
      "invite_vendors",
      "collect_payment",
    ])
    expect(items[2].eventId).toBe("42")
    expect(items[2].description).toBe("Still needed: Vendor categories")
    expect(items.filter((i) => !i.complete)).toHaveLength(4)
  })

  it("prefers activation over the legacy checklist and falls back when it is missing", () => {
    const legacy = { items: [{ key: "create_event", title: "Create event", status: "not_started" }] }
    const withActivation = mapOrganizerHomeSummary({}, {}, legacy, undefined, activationPayload)
    expect(withActivation.activation?.percent).toBe(33)
    expect(withActivation.launchChecklist).toHaveLength(6)
    const fallback = mapOrganizerHomeSummary({}, {}, legacy, undefined, undefined)
    expect(fallback.activation).toBeUndefined()
    expect(fallback.launchChecklist.map((i) => i.key)).toEqual(["create_event"])
  })
})
