import {
  normalizeSupply,
  normalizeTerritories,
  supplySignalLabel,
  territoryScopeLabel,
  topOrganizerCity,
} from "@/lib/api/partner"
import { isDestinationAllowed } from "@/lib/navigation/pending-destination"
import { hrefFromNotificationData } from "@/lib/push/notification-routing"

const PARTNER = ["lnkup_connector"]
const ORGANIZER = ["lnkup_organizer"]

describe("partner territories", () => {
  it("keeps assigned territories and the home market", () => {
    const t = normalizeTerritories({
      city: "Charlotte",
      state: "NC",
      items: [
        { id: 1, scope_type: "city", value: "Charlotte, NC" },
        { id: 2, scope_type: "state", value: "SC" },
        { id: 3, scope_type: "city", value: "  " },
      ],
    })
    expect(t.homeMarket).toBe("Charlotte, NC")
    expect(t.items.map((i) => i.value)).toEqual(["Charlotte, NC", "SC"])
    expect(territoryScopeLabel("state")).toBe("State")
    expect(territoryScopeLabel("galaxy")).toBe("Area")
  })

  it("handles an empty or missing response", () => {
    expect(normalizeTerritories(null)).toEqual({ homeMarket: "", items: [] })
  })
})

describe("partner vendor supply", () => {
  it("keeps the small-count mask and labels signals", () => {
    const s = normalizeSupply({
      snapshot_date: "2026-11-22",
      items: [
        { label: "Charlotte, NC", vendors_active: 42, vendors_reach: "61", open_slots: 6, live_organizers: 3, signal: "recruit_organizers" },
        { label: "Concord, NC", vendors_active: "<3", vendors_reach: 8, open_slots: 12, live_organizers: 1, signal: "recruit_vendors" },
      ],
    })
    expect(s.snapshotDate).toBe("2026-11-22")
    expect(s.items[0].activeVendors).toBe("42")
    expect(s.items[0].vendorsInReach).toBe("61")
    expect(s.items[1].activeVendors).toBe("<3")
    expect(supplySignalLabel(s.items[0].signal)).toBe("Needs organizers")
    expect(supplySignalLabel(s.items[1].signal)).toBe("Needs vendors")
  })

  it("treats unknown signals as not enough data and drops unlabeled rows", () => {
    const s = normalizeSupply({ items: [{ label: "Nowhere", vendors_active: 1, signal: "boom" }, { vendors_active: 9 }] })
    expect(s.items).toHaveLength(1)
    expect(s.items[0].signal).toBe("insufficient_data")
    expect(supplySignalLabel(s.items[0].signal)).toBe("Not enough data yet")
  })

  it("handles no snapshot yet", () => {
    const s = normalizeSupply({ snapshot_date: "", items: [] })
    expect(s).toEqual({ snapshotDate: "", items: [] })
    expect(topOrganizerCity(s)).toBeNull()
    expect(normalizeSupply(null).items).toEqual([])
  })

  it("picks the organizer-needing city with the most open booths", () => {
    const s = normalizeSupply({
      items: [
        { label: "A", vendors_active: 20, open_slots: 2, signal: "recruit_organizers" },
        { label: "B", vendors_active: 30, open_slots: 5, signal: "recruit_organizers" },
        { label: "C", vendors_active: 1, open_slots: 50, signal: "recruit_vendors" },
      ],
    })
    expect(topOrganizerCity(s)?.label).toBe("B")
  })
})

describe("partner cities screen access and activation routing", () => {
  it("only partner accounts may open My cities", () => {
    expect(isDestinationAllowed("/(partner)/cities", PARTNER)).toBe(true)
    expect(isDestinationAllowed("/(partner)/cities", ORGANIZER)).toBe(false)
  })

  it("opens the organizer profile from the profile nudge's server link", () => {
    expect(
      hrefFromNotificationData({ notificationId: "5", type: "activation_create_event", href: "/(organizer)/profile" }),
    ).toBe("/(organizer)/profile")
  })

  it("falls back to Money for payout-setup pushes without a link", () => {
    expect(hrefFromNotificationData({ type: "organizer_payout_setup" })).toBe("/(organizer)/money")
  })
})
