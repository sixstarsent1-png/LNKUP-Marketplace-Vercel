import {
  changedProfileFields,
  mapApplyContext,
  mapDraft,
  mapDraftCards,
  mapPriceQuote,
  profileOverridesBody,
  type ApplyProfile,
} from "@/lib/api/apply"
import { mapVendorBooking } from "@/lib/api/vendor-bookings"

const PROFILE: ApplyProfile = {
  businessName: "Sweet Spot",
  products: "Cupcakes",
  setupNeeds: "10x10 tent",
  contactEmail: "hi@sweet.test",
  contactPhone: "555-0100",
}

describe("profile snapshot and overrides", () => {
  it("maps the apply-context profile snapshot to override fields", () => {
    const ctx = mapApplyContext(5, {
      opportunity: { opportunity_id: 5, name: "Night Market" },
      profile: { business_name: "Sweet Spot", products: "Cupcakes", setup_needs: "10x10 tent", contact_email: "hi@sweet.test", cities_served: ["Charlotte"] },
    })
    expect(ctx.profile).toEqual({ ...PROFILE, contactPhone: "" })
  })

  it("has no profile when the vendor has none", () => {
    expect(mapApplyContext(5, { opportunity: { opportunity_id: 5 }, profile: null }).profile).toBeNull()
  })

  it("keeps only fields that differ from the profile, trimmed", () => {
    expect(
      changedProfileFields({ businessName: " Sweet Spot ", products: "Cupcakes and cookies", contactPhone: "" }, PROFILE),
    ).toEqual({ products: "Cupcakes and cookies", contactPhone: "" })
  })

  it("sends overrides with the server's snake_case keys", () => {
    expect(profileOverridesBody({ businessName: "Sweet Spot Pop-up", setupNeeds: "Power" })).toEqual({
      business_name: "Sweet Spot Pop-up",
      setup_needs: "Power",
    })
    expect(profileOverridesBody(undefined)).toEqual({})
  })

  it("reads saved overrides from a draft, including PHP's empty []", () => {
    expect(mapDraft({ id: 1, profile_overrides: { products: "Cookies" } })?.profileOverrides).toEqual({ products: "Cookies" })
    expect(mapDraft({ id: 1, profile_overrides: [] })?.profileOverrides).toEqual({})
  })
})

describe("draft resume cards", () => {
  it("maps open drafts and drops ones without an opportunity", () => {
    const cards = mapDraftCards({
      items: [
        {
          id: 9,
          opportunity_id: 5,
          event_name: "Night Market",
          opportunity_title: "Food row",
          event_date: "2026-12-30",
          location_label: "Charlotte, NC",
          deadline: "2026-12-20",
          updated_at: "2026-11-20 14:00:00",
          progress_percent: 140,
          can_submit: true,
          price: { changed: true },
        },
        { id: 10, opportunity_id: 0 },
      ],
    })
    expect(cards).toEqual([
      {
        id: 9,
        opportunityId: 5,
        eventName: "Night Market",
        boothName: "Food row",
        eventDate: "Dec 30, 2026",
        location: "Charlotte, NC",
        deadline: "Dec 20, 2026",
        updatedAt: "Nov 20, 2026",
        progressPercent: 100,
        canSubmit: true,
        priceChanged: true,
      },
    ])
  })

  it("returns nothing for a missing payload", () => {
    expect(mapDraftCards(null)).toEqual([])
  })
})

describe("price quote", () => {
  it("maps fee engine lines and total", () => {
    expect(
      mapPriceQuote({
        currency: "USD",
        lines: [
          { key: "booth", label: "Booth fee", amount: 100 },
          { key: "processing", label: "Card processing", amount: 3.2 },
        ],
        total: 103.2,
        note: "Estimate.",
      }),
    ).toEqual({
      currency: "USD",
      lines: [
        { key: "booth", label: "Booth fee", amount: 100 },
        { key: "processing", label: "Card processing", amount: 3.2 },
      ],
      total: 103.2,
      note: "Estimate.",
    })
  })

  it("is null when the quote has no lines", () => {
    expect(mapPriceQuote({ lines: [], total: 0 })).toBeNull()
  })
})

describe("booking detail", () => {
  const detail = {
    application_id: 42,
    event_id: 7,
    event_name: "Night Market",
    organizer_name: "CultureWave",
    event_date: "2026-12-30",
    start_time: "6:00 PM",
    venue: "Camp North End",
    city: "Charlotte",
    state: "NC",
    booth: "Food row",
    status: "approved",
    payment_id: 88,
    payment_status: "pending",
    amount_due: 103.2,
    event: { venue_address: "1824 Statesville Ave" },
    booking: {
      category: "Dessert Vendor",
      submitted_at: "2026-11-01 10:00:00",
      answers: [
        { id: "q1", label: "Need power?", type: "checkbox", value: true },
        { id: "q2", label: "Insurance", type: "file", value: 12 },
        { id: "q3", label: "Menu", type: "text", value: "Cupcakes" },
        { id: "q4", label: "Empty", type: "text", value: "" },
      ],
      rejection_reason: "",
      can_withdraw: true,
    },
    payment: {
      status: "pending",
      total: 103.2,
      credit_applied: 10,
      card_charged: 0,
      amount_due: 93.2,
      refunded: 0,
      credit_refunded: 0,
      due_at: "2026-12-10 23:59:00",
      receipts: [{ id: 3, receipt_number: "R-1", amount: 10, created_at: "2026-11-05 09:00:00" }],
      refunds: [],
    },
    readiness: null,
    timeline: [
      { key: "submitted", label: "Application submitted", at: "2026-11-01 10:00:00", state: "done", note: "" },
      { key: "payment", label: "Payment", at: "", state: "current", note: "Due by Dec 10, 2026" },
      { key: "pass", label: "Event pass available", at: "", state: "bogus", note: "" },
    ],
  }

  it("maps the request view used by status pills and actions", () => {
    const booking = mapVendorBooking(detail)
    expect(booking.request).toMatchObject({
      id: "42",
      eventName: "Night Market",
      eventDate: "Dec 30, 2026",
      eventDateIso: "2026-12-30",
      city: "Charlotte, NC",
      status: "approved",
      amountDue: 103.2,
      paymentId: "88",
      submittedAt: "Nov 1, 2026",
    })
    expect(booking.canWithdraw).toBe(true)
    expect(booking.venueAddress).toBe("1824 Statesville Ave")
  })

  it("shows answers as text and skips empty ones", () => {
    expect(mapVendorBooking(detail).answers).toEqual([
      { id: "q1", label: "Need power?", value: "Yes" },
      { id: "q2", label: "Insurance", value: "Document attached" },
      { id: "q3", label: "Menu", value: "Cupcakes" },
    ])
  })

  it("maps the payment block with formatted dates", () => {
    expect(mapVendorBooking(detail).payment).toEqual({
      status: "pending",
      total: 103.2,
      creditApplied: 10,
      cardCharged: 0,
      amountDue: 93.2,
      refunded: 0,
      dueAt: "Dec 10, 2026",
      paidAt: undefined,
      receipts: [{ id: 3, number: "R-1", amount: 10, createdAt: "Nov 5, 2026" }],
      refunds: [],
    })
  })

  it("uses the server timeline, preferring notes over dates and guarding unknown states", () => {
    expect(mapVendorBooking(detail).timeline).toEqual([
      { key: "submitted", label: "Application submitted", detail: "Nov 1, 2026", state: "done" },
      { key: "payment", label: "Payment", detail: "Due by Dec 10, 2026", state: "current" },
      { key: "pass", label: "Event pass available", detail: undefined, state: "upcoming" },
    ])
  })

  it("has no payment or readiness blocks before approval", () => {
    const booking = mapVendorBooking({ application_id: 1, status: "submitted", payment: null, readiness: null })
    expect(booking.payment).toBeNull()
    expect(booking.readiness).toBeNull()
    expect(booking.request.amountDue).toBeUndefined()
  })

  it("maps readiness items for paid bookings", () => {
    const booking = mapVendorBooking({
      application_id: 1,
      status: "paid",
      readiness: { percent: 66.6, items: [{ key: "w9", label: "Upload W-9", done: false }, { key: "paid", label: "Payment complete", done: true }] },
    })
    expect(booking.readiness).toEqual({
      percent: 67,
      items: [
        { key: "w9", label: "Upload W-9", done: false },
        { key: "paid", label: "Payment complete", done: true },
      ],
    })
  })
})
