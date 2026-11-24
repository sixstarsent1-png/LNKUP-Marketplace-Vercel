import type { OrganizerPayout } from "@/lib/api/organizer-financial"
import { mapOrganizerEventMoney } from "@/lib/api/organizer-financial"
import {
  mapApplicationReview,
  mapCheckinSummary,
  mapEventCard,
  mapHomeSummary,
  mapInviteOverview,
  mapOperationsSummary,
  mapPayoutPreview,
} from "@/lib/api/organizer-mobile"
import { mapTourSummary } from "@/lib/api/organizer-tours"
import { awaitingReviewCount } from "@/components/organizer-vendors/vendor-queue"
import {
  canCheckInRow,
  canOverrideRow,
  matchesCheckinFilter,
  matchesCheckinSearch,
  populationLine,
  populationsFromRows,
  rowFromRoster,
} from "@/lib/organizer/checkin-model"
import { publishChecklist } from "@/lib/organizer/event-detail"
import { serverFilters, sortPortfolio } from "@/lib/organizer/event-portfolio"
import { checkinEvent, legacyHomeToOrgHome, queueRowHref, setupStageHref } from "@/lib/organizer/home-model"
import { payoutTimeline } from "@/lib/organizer/payout-timeline"

const event = (over: Record<string, unknown> = {}) => ({
  id: 927,
  title: "Summer Market",
  date: "2027-01-06",
  date_label: "Jan 6, 2027",
  days_until: 10,
  venue: "Park",
  place: "Charlotte, NC",
  publication: "live",
  publication_label: "Published",
  chronology: "upcoming",
  chronology_label: "Upcoming",
  applications_open: true,
  applications: { total: 4, pending: 2, info_requested: 0, held: 1, confirmed: 1, waitlisted: 0, declined: 0 },
  capacity: { total: 10, confirmed: 1, held: 1, open: 8, waitlisted: 0, booth_types: 2 },
  tour_id: 0,
  tour_title: "",
  public_url: "",
  media: { url: "https://x/large.jpg", medium_url: "https://x/m.jpg", large_url: "https://x/large.jpg", fit: "contain", background: "#000", alt: "" },
  fallback_url: "https://x/art.png",
  money: { collected_gross: 200, outstanding: 150, paid_bookings: 1 },
  ...over,
})

describe("home summary", () => {
  const home = mapHomeSummary({
    lifecycle: "active",
    setup: { percent: 83, completed: 5, total: 6, is_activated: false, next: { stage: "payouts", title: "Set up payouts", label: "Set up", missing: ["Payout account"] }, stages: [] },
    kpis: { collected: { value: 120, label: "Collected after LNKup fees · all time" }, awaiting_review: { value: 2, label: "" }, approved_unpaid: { value: 3, label: "" }, upcoming_events: { value: 1, label: "" }, confirmed: { value: 1, label: "" } },
    events: { counts: { total: 1, live: 1 }, today: [], next: event(), upcoming: [event()] },
    queue: { payment: { label: "Unpaid", count: 3, rows: [{ app_id: 5, event_id: 927, title: "Tacos", meta: "Summer Market · Jan 6" }] }, review: { label: "Review", count: 2, rows: [] } },
    money: { collected: 120, available: null, payouts_ready: false, label: "Collected after LNKup fees · all time" },
  })

  it("keeps one setup model and the server's queue order", () => {
    expect(home.setup.percent).toBe(83)
    expect(home.queue.map((g) => g.key)).toEqual(["review", "payment"])
  })

  it("keeps an unknown available balance as null, not zero", () => {
    expect(home.money.available).toBeNull()
  })

  it("maps media and the fallback art", () => {
    expect(home.events.next?.media?.fit).toBe("contain")
    expect(home.events.next?.fallbackUrl).toBe("https://x/art.png")
  })

  it("routes queue rows to native screens", () => {
    expect(queueRowHref("review", { appId: "5", eventId: "927", title: "", meta: "" })).toBe("/(organizer)/vendors/review/5")
    expect(queueRowHref("payment", { appId: "5", eventId: "927", title: "", meta: "" })).toBe("/(organizer)/money/event?eventId=927")
    expect(setupStageHref("payouts", home)).toBe("/(organizer)/money")
  })

  it("promotes check-in only on or the day before an event", () => {
    expect(checkinEvent(home)).toBeNull()
    expect(checkinEvent({ events: { ...home.events, next: mapEventCard(event({ days_until: 1 })) } })?.id).toBe("927")
  })

  it("maps older servers' home without guessing events", () => {
    const legacy = legacyHomeToOrgHome({
      stats: { events: 0, openOpportunities: 0, vendorRequests: 0, approvedVendors: 0, paymentRequired: 0, paidVendors: 0 },
      attentionItems: [],
      launchChecklist: [],
      intelligence: { diagnostics: [], experiments: [], recommendations: [] },
      wordpressUrl: "",
    })
    expect(legacy.lifecycle).toBe("new")
    expect(legacy.events.upcoming).toEqual([])
  })
})

describe("check-in populations", () => {
  const summary = mapCheckinSummary({
    event_id: 927,
    booked: 4,
    expected: 2,
    checked_in: 1,
    waiting: 1,
    blocked: { payment_pending: 1, documents: 1 },
    arrival_pct: 50,
    rows: [
      { application_id: 1, vendor_name: "Tacos", category: "Food", booth: "4", reason: "already_checked_in", eligible: false, checked_in: true, actions: [] },
      { application_id: 2, vendor_name: "Soap", category: "Goods", booth: "", reason: "ready", eligible: true, checked_in: false, actions: ["checked_in"] },
      { application_id: 3, vendor_name: "Late", category: "", booth: "", reason: "payment_pending", eligible: false, checked_in: false, actions: [] },
      { application_id: 4, vendor_name: "Docs", category: "", booth: "", reason: "documents", eligible: false, checked_in: false, actions: [] },
    ],
  })

  it("uses eligible vendors as the denominator", () => {
    expect(populationLine(summary)).toBe("1 of 2 eligible checked in · 2 blocked")
  })

  it("recomputes the same populations from rows for older servers", () => {
    const p = populationsFromRows(summary.rows)
    expect([p.booked, p.expected, p.checkedIn, p.blocked.paymentPending, p.blocked.documents]).toEqual([4, 2, 1, 1, 1])
    expect(p.arrivalPct).toBe(50)
  })

  it("never counts blocked vendors as not-arrived", () => {
    const expected = summary.rows.filter((r) => matchesCheckinFilter(r, "expected")).map((r) => r.vendorName)
    expect(expected).toEqual(["Soap"])
    expect(summary.rows.filter((r) => matchesCheckinFilter(r, "attention"))).toHaveLength(2)
  })

  it("offers check-in or an audited override, never both", () => {
    const [done, ready, unpaid] = summary.rows
    expect(canCheckInRow(ready!)).toBe(true)
    expect(canOverrideRow(ready!)).toBe(false)
    expect(canCheckInRow(unpaid!)).toBe(false)
    expect(canOverrideRow(unpaid!)).toBe(true)
    expect(canCheckInRow(done!)).toBe(false)
  })

  it("finds vendors by name, category or exact booth", () => {
    const [tacos] = summary.rows
    expect(matchesCheckinSearch(tacos!, "taco")).toBe(true)
    expect(matchesCheckinSearch(tacos!, "food")).toBe(true)
    expect(matchesCheckinSearch(tacos!, "4")).toBe(true)
    expect(matchesCheckinSearch(tacos!, "44")).toBe(false)
  })

  it("maps roster entries for older servers", () => {
    const row = rowFromRoster({ applicationId: "9", businessName: "X", status: "paid", group: "paid", booth: "", checkedIn: false, noShow: false, blockReason: "documents_missing", actions: [] })
    expect(row.reason).toBe("documents")
  })
})

describe("money", () => {
  it("reads the server's fee preview without computing fees", () => {
    const preview = mapPayoutPreview({ type: "instant", amount: 100, available: 120, fee_amount: 2, fee_rate: 0.02, estimated_net: 98, method: "lnkup", can_submit: true, problem: "" })
    expect([preview.feeAmount, preview.estimatedNet, preview.canSubmit]).toEqual([2, 98, true])
  })

  it("prefers open invoices over the legacy pending figure", () => {
    const money = mapOrganizerEventMoney("927", { collected_revenue: 200, pending_revenue: 350, outstanding_receivables: 100, outstanding_invoice_count: 1, refunds: 50 })
    expect(money.pending).toBe(100)
    expect(money.openInvoiceCount).toBe(1)
    expect(mapOrganizerEventMoney("927", { pending_revenue: 350 }).pending).toBe(350)
  })

  it("never shows a requested cash out as paid", () => {
    const base: OrganizerPayout = { id: "1", amount: 50, netAmount: 50, feeAmount: 0, type: "standard", status: "requested", canCancel: true, reference: "LNK-PO-1", createdAt: "2026-11-01 10:00:00" }
    expect(payoutTimeline(base).find((s) => s.key === "paid")?.done).toBe(false)
    expect(payoutTimeline({ ...base, status: "paid", paidAt: "2026-11-02 10:00:00" }).find((s) => s.key === "paid")?.done).toBe(true)
    expect(payoutTimeline({ ...base, status: "failed" }).some((s) => s.tone === "error")).toBe(true)
  })

  it("keeps platform fees out of tour money", () => {
    const tour = mapTourSummary({ tour: { id: 1, title: "T" }, money: { collected: 300, platform_fees: 30, organizer_net_est: 270, pending: 0 }, stops: [] })
    expect(Object.keys(tour.money)).toEqual(["collected", "outstanding", "refunds", "paidBookings"])
  })
})

describe("events and review", () => {
  it("maps portfolio chips to server filters", () => {
    expect(serverFilters("open", "x")).toEqual({ q: "x", applications: "open", publication: "live" })
    expect(serverFilters("past", "")).toEqual({ q: "", chronology: "completed" })
  })

  it("sorts underfilled events first", () => {
    const full = mapEventCard(event({ id: 1, capacity: { total: 10, confirmed: 10 } }))!
    const empty = mapEventCard(event({ id: 2, capacity: { total: 10, confirmed: 1 } }))!
    expect(sortPortfolio([full, empty], "underfilled").map((e) => e.id)).toEqual(["2", "1"])
  })

  it("requires date, place and a priced slot before publishing", () => {
    const items = publishChecklist({ id: "1", title: "E", status: "upcoming", date: "2027-01-01", city: "Austin", state: "TX" }, [])
    expect(items.filter((i) => i.required && !i.done).map((i) => i.key)).toEqual(["slot"])
  })

  it("maps the review read model with its decisions", () => {
    const review = mapApplicationReview({ application_id: 5, status: "submitted", vendor: { business_name: "Tacos" }, event: { id: 927, open: 0, unlimited: false }, fee: 200, actions: { approve: true, waitlist: true, reject: true, request_info: true }, approve_note: "invoice" })
    expect(review.actions.requestInfo).toBe(true)
    expect(review.event.open).toBe(0)
  })

  it("counts the Vendors badge the same way as Home", () => {
    expect(awaitingReviewCount([{ status: "submitted" }, { status: "under_review" }, { status: "info_requested" }, { status: "paid" }])).toBe(2)
  })
})

describe("invites and operations", () => {
  it("maps invite lifecycle fields", () => {
    const overview = mapInviteOverview({ items: [{ id: 3, status: "cancelled", can_remind: false, can_cancel: false, reminder_count: 1, sent_at: "2026-11-01 10:00:00" }], counts: { cancelled: 1 } })
    expect(overview.items[0]?.status).toBe("cancelled")
    expect(overview.counts.cancelled).toBe(1)
  })

  it("maps operations tasks with their owner", () => {
    const ops = mapOperationsSummary({ event: event(), mode: "event_day", can: { check_in: true }, tasks: [{ key: "payments", label: "Collect", detail: "", done: false, owner: "vendor", can_act: true }], readiness: { vendors: [] } })
    expect(ops?.mode).toBe("event_day")
    expect(ops?.tasks[0]?.owner).toBe("vendor")
  })
})
