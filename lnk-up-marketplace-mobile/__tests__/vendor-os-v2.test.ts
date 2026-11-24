import { groupOpportunitiesLocally, mapEventGroup, savedOptionIds, toggleGroupSaved } from "@/lib/api/event-groups"
import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { groupPinsByEvent, type MarketplaceMapPin } from "@/lib/api/map-pins"
import { mapWpVendorApplication, type VendorRequest } from "@/lib/api/vendor-requests"
import {
  groupTransactionsByDate,
  isMoneyMovement,
  mapReceiptDetail,
  mapVendorTransaction,
  formatLedgerDate,
} from "@/lib/api/vendor-transactions"
import { mapVendorInvoiceOrReceipt } from "@/lib/api/vendor-wallet"
import { groupRepeatedNotifications } from "@/lib/notifications/group-notifications"
import type { LnkupNotification } from "@/lib/notifications/types"
import {
  bookingSegment,
  canOpenPass,
  countdownLabel,
  isPastEventInvoice,
  isUpcomingPaymentDue,
  nextUpcomingBooking,
  payCtaLabel,
  segmentBookings,
} from "@/lib/vendor/booking-lifecycle"
import {
  deadlineLabel,
  environmentLabel,
  groupAvailability,
  groupPriceLabel,
  optionAvailability,
  optionPriceLabel,
} from "@/lib/vendor/event-group-presentation"

const TODAY = "2026-11-24"

function booking(overrides: Partial<VendorRequest>): VendorRequest {
  return { id: "1", eventName: "Fair", status: "submitted", ...overrides }
}

describe("booking lifecycle", () => {
  it("never treats an undated confirmed booking as upcoming", () => {
    const r = booking({ status: "paid", bookingState: "confirmed", eventState: "unknown" })
    expect(bookingSegment(r, TODAY)).toBe("review")
    expect(nextUpcomingBooking([r], TODAY)).toBeNull()
  })

  it("moves a past event out of upcoming even when the start date was not compared", () => {
    const r = booking({ status: "paid", bookingState: "completed", eventState: "completed", eventDateIso: "2026-08-01" })
    expect(bookingSegment(r, TODAY)).toBe("past")
  })

  it("labels an open balance on a finished event as a past-event invoice, not a spot to confirm", () => {
    const r = booking({
      status: "payment_required",
      eventState: "completed",
      paymentContext: { hasBalance: true, pastEventInvoice: true, collectible: true },
      amountDue: 99.07,
    })
    expect(isPastEventInvoice(r, TODAY)).toBe(true)
    expect(isUpcomingPaymentDue(r, TODAY)).toBe(false)
    expect(bookingSegment(r, TODAY)).toBe("past")
  })

  it("keeps an upcoming payment in Action needed with one pay label", () => {
    const r = booking({
      status: "payment_required",
      eventState: "upcoming",
      eventDateIso: "2027-01-01",
      paymentContext: { hasBalance: true, pastEventInvoice: false, collectible: true },
      amountDue: 99.07,
    })
    expect(bookingSegment(r, TODAY)).toBe("action")
    expect(payCtaLabel(r)).toBe("Pay $99.07")
  })

  it("falls back to dates for servers without event_state", () => {
    const r = booking({ status: "paid", eventDateIso: "2026-12-05" })
    expect(bookingSegment(r, TODAY)).toBe("upcoming")
    expect(countdownLabel(r, TODAY)).toBe("In 11 days")
  })

  it("only offers the pass when the credential is active", () => {
    expect(canOpenPass(booking({ status: "paid", credentialState: "ended" }))).toBe(false)
    expect(canOpenPass(booking({ status: "paid", credentialState: "active" }))).toBe(true)
  })

  it("routes unrecoverable records to Needs review and sorts upcoming soonest first", () => {
    const s = segmentBookings(
      [
        booking({ id: "a", status: "paid", bookingState: "confirmed", eventState: "upcoming", eventDateIso: "2027-02-01" }),
        booking({ id: "b", status: "paid", bookingState: "confirmed", eventState: "upcoming", eventDateIso: "2026-12-01" }),
        booking({ id: "c", status: "paid", eventResolution: "unrecoverable" }),
      ],
      TODAY,
    )
    expect(s.upcoming.map((r) => r.id)).toEqual(["b", "a"])
    expect(s.review.map((r) => r.id)).toEqual(["c"])
  })

  it("maps the server contract fields", () => {
    const r = mapWpVendorApplication({
      application_id: 7,
      event_name: "Dirty South Rodeo",
      status: "paid",
      event_state: "upcoming",
      booking_state: "confirmed",
      credential_state: "active",
      event_resolution: "snapshot",
      payment_context: { has_balance: false, past_event_invoice: false, collectible: false },
      timeline: [{ key: "submitted", label: "Application submitted", state: "done", at: "2026-11-01 10:00:00" }],
    })
    expect(r.eventResolution).toBe("snapshot")
    expect(r.credentialState).toBe("active")
    expect(r.paymentContext?.hasBalance).toBe(false)
    expect(r.timeline?.[0]).toMatchObject({ key: "submitted", state: "done" })
  })
})

describe("event groups", () => {
  const raw = {
    item_type: "event_group",
    key: "e10",
    event_id: 10,
    opportunity_id: 101,
    title: "UnReleased at Miami Art Week",
    date: "2026-12-04",
    place: "Miami, FL",
    organizer: { name: "Ars MAG", verified: true },
    gated: false,
    from_price: 300,
    price_max: 500,
    categories: ["Art Vendor", "Clothing Brand"],
    availability: { total_options: 2, open_options: 1, full_options: 1, open_spots: 5, has_unlimited: false },
    options: [
      { opportunity_id: 101, title: "Art Vendor", vendor_fee: 300, spots_remaining: 5, total_spots: 5, spots_unlimited: false, is_open: true },
      { opportunity_id: 102, title: "Clothing Brand", vendor_fee: 500, spots_remaining: 0, total_spots: 4, spots_unlimited: false, is_open: false },
    ],
  }

  it("keeps each option's own price and availability", () => {
    const g = mapEventGroup(raw)!
    expect(g.options).toHaveLength(2)
    expect(optionPriceLabel(g.options[1], g.gated)).toBe("$500")
    expect(optionAvailability(g.options[0])?.label).toBe("5 of 5 spaces available")
    expect(optionAvailability(g.options[1])?.label).toBe("Full")
  })

  it("does not call an event full when another option is open", () => {
    const g = mapEventGroup(raw)!
    expect(groupAvailability(g)?.label).toBe("1 of 2 vendor options open")
    expect(groupPriceLabel(g)).toBe("From $300")
  })

  it("hides pricing for guests", () => {
    const g = mapEventGroup({ ...raw, gated: true, from_price: null })!
    expect(groupPriceLabel(g)).toBe("Sign in for pricing")
    expect(groupAvailability(g)).toBeNull()
  })

  it("groups old per-opportunity responses by event on the client", () => {
    const base: MarketplaceOpportunity = {
      id: "opp-1",
      opportunityId: 1,
      eventId: 9,
      title: "Fair",
      categories: ["Food Vendor"],
      vendorFee: 100,
      expectedAttendance: 0,
      spotsRemaining: 3,
      spotsTotal: 5,
      organizerVerified: false,
      trending: false,
    }
    const groups = groupOpportunitiesLocally([base, { ...base, id: "opp-2", opportunityId: 2, vendorFee: 80, categories: ["Retail Vendor"] }])
    expect(groups).toHaveLength(1)
    expect(groups[0].fromPrice).toBe(80)
    expect(groups[0].categories).toEqual(["Food Vendor", "Retail Vendor"])
  })

  it("saves events through existing per-option favorites", async () => {
    const g = mapEventGroup(raw)!
    expect(savedOptionIds(g, new Set([102]))).toEqual([102])
    const calls: Array<[number, boolean]> = []
    const setSaved = async (id: number, wanted: boolean) => {
      calls.push([id, wanted])
      return wanted
    }
    await toggleGroupSaved(g, new Set(), setSaved)
    await toggleGroupSaved(g, new Set([102]), setSaved)
    expect(calls).toEqual([
      [101, true],
      [102, false],
    ])
  })

  it("labels deadlines and environments in words", () => {
    expect(deadlineLabel("2026-11-25", TODAY)).toEqual({ label: "Apply by Nov 25, 2026", urgent: true })
    expect(deadlineLabel("2026-11-20", TODAY)).toBeNull()
    expect(environmentLabel(true, true)).toBe("Indoor and outdoor areas")
  })

  it("collapses map pins to one per event", () => {
    const pin = (opportunityId: number, eventId: number, vendorFee: number): MarketplaceMapPin => ({
      id: `pin-${opportunityId}`,
      opportunityId,
      eventId,
      title: "Fair",
      lat: 1,
      lng: 1,
      vendorFee,
    })
    const pins = groupPinsByEvent([pin(1, 9, 300), pin(2, 9, 200), pin(3, 10, 50)])
    expect(pins).toHaveLength(2)
    expect(pins[0]).toMatchObject({ optionCount: 2, vendorFee: 200 })
  })
})

describe("wallet ledger and documents", () => {
  it("keeps reminders and open invoices out of history", () => {
    const paid = mapVendorTransaction({ id: 1, type: "payment", status: "paid", payment_status: "paid", amount: 422.18 })
    const pending = mapVendorTransaction({ id: 2, type: "payment", status: "pending", payment_status: "pending", amount: 99 })
    const removed = mapVendorTransaction({ id: 3, type: "payment", status: "pending", payment_status: "removed", amount: 99 })
    expect([paid, pending, removed].filter(isMoneyMovement).map((t) => t.id)).toEqual(["1"])
    expect(paid.typeLabel).toBe("Vendor payment")
  })

  it("groups history by recency and never shows raw timestamps", () => {
    const now = new Date(2026, 10, 24, 12)
    const groups = groupTransactionsByDate(
      [
        mapVendorTransaction({ id: 1, status: "paid", paid_at: "2026-11-24 09:00:00", amount: 1 }),
        mapVendorTransaction({ id: 2, status: "paid", paid_at: "2026-10-17 03:21:20", amount: 1 }),
      ],
      now,
    )
    expect(groups.map((g) => g.title)).toEqual(["Today", "October 2026"])
    expect(formatLedgerDate("2026-10-17 03:21:20")).toBe("Oct 17, 2026")
  })

  it("reads a receipt's payment status instead of its issued document state", () => {
    expect(mapVendorInvoiceOrReceipt({ id: 4, status: "issued", payment_status: "paid" }, "receipt", 0).status).toBe("paid")
    expect(mapVendorInvoiceOrReceipt({ id: 4, status: "issued" }, "receipt", 0).status).toBe("paid")
    expect(mapVendorInvoiceOrReceipt({ id: 4, status: "issued", payment_status: "refunded" }, "receipt", 0).status).toBe("refunded")
    expect(mapReceiptDetail({ id: 4, status: "issued", receipt_number: "RCP-1" }).paymentStatus).toBe("paid")
  })
})

describe("notification grouping", () => {
  const n = (id: string, title: string, applicationId?: string, readAt?: string): LnkupNotification => ({
    id,
    type: "payment_due" as LnkupNotification["type"],
    title,
    applicationId,
    readAt,
  })

  it("collapses repeated reminders for the same booking only", () => {
    const rows = groupRepeatedNotifications([
      n("1", "Payment past due", "7"),
      n("2", "Payment past due", "7", "2026-11-01"),
      n("3", "Payment past due", "8"),
    ])
    expect(rows).toHaveLength(2)
    expect(rows[0]).toMatchObject({ memberIds: ["1", "2"], unreadCount: 1 })
  })
})
