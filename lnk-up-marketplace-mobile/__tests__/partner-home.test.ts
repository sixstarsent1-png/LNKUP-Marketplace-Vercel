import type { MobileSession } from "@/lib/api/auth"
import { normalizeInboxItem } from "@/lib/api/comms"
import {
  leadFormError,
  normalizeDashboard,
  normalizeEarnings,
  normalizeLeads,
  normalizeReferrals,
  pendingTotal,
  termsSummary,
} from "@/lib/api/partner"
import { isPartnerRole, resolveHomeHref, saveHomePreference } from "@/lib/auth/roles"
import { isDestinationAllowed } from "@/lib/navigation/pending-destination"
import { hrefFromNotificationData, inAppHrefForNotification } from "@/lib/push/notification-routing"
import { inboxTabsFor, normalizeNotificationType } from "@/lib/notifications/types"

function session(roles: string[]): MobileSession {
  return { username: "u", appPassword: "", token: "t", user: { id: 1, name: "U", slug: "u", roles } }
}

const PARTNER = ["lnkup_connector"]
const AFFILIATE = ["lnkup_partner"]
const VENDOR = ["lnkup_vendor"]

describe("partner home resolution", () => {
  it("recognizes City Connector and affiliate/strategic partner roles", () => {
    expect(isPartnerRole(PARTNER)).toBe(true)
    expect(isPartnerRole(AFFILIATE)).toBe(true)
    expect(isPartnerRole(VENDOR)).toBe(false)
  })

  it("sends partner-only accounts to the Partner home", async () => {
    expect(await resolveHomeHref(session(PARTNER))).toBe("/(partner)")
    expect(await resolveHomeHref(session(AFFILIATE))).toBe("/(partner)")
  })

  it("honours a saved Partner preference for a vendor who is also a partner", async () => {
    await saveHomePreference("partner")
    expect(await resolveHomeHref(session(["lnkup_vendor", "lnkup_connector"]))).toBe("/(partner)")
    await saveHomePreference("vendor")
    expect(await resolveHomeHref(session(["lnkup_vendor", "lnkup_connector"]))).toBe("/(vendor)")
  })
})

describe("partner destinations", () => {
  it("only partner accounts may open partner screens", () => {
    expect(isDestinationAllowed("/(partner)/earnings", PARTNER)).toBe(true)
    expect(isDestinationAllowed("/(partner)/earnings", VENDOR)).toBe(false)
    expect(isDestinationAllowed("/(partner)", ["lnkup_organizer"])).toBe(false)
  })

  it("partners cannot open vendor or organizer screens", () => {
    expect(isDestinationAllowed("/(vendor)/wallet", PARTNER)).toBe(false)
    expect(isDestinationAllowed("/(organizer)/money", PARTNER)).toBe(false)
  })
})

describe("partner notification routing", () => {
  it("trusts the server deep link on comms pushes", () => {
    expect(hrefFromNotificationData({ notificationId: "9", type: "partner_commission", href: "/(partner)/earnings" })).toBe(
      "/(partner)/earnings",
    )
  })

  it("falls back to the partner screen for each type", () => {
    expect(hrefFromNotificationData({ type: "partner_referral" })).toBe("/(partner)/referrals")
    expect(hrefFromNotificationData({ type: "partner_lead" })).toBe("/(partner)/leads")
    expect(hrefFromNotificationData({ type: "partner_payout" })).toBe("/(partner)/earnings")
    expect(hrefFromNotificationData({ type: "partner_application" })).toBe("/(partner)")
  })

  it("drops partner inbox links for accounts without a partner role", () => {
    const item = { type: normalizeNotificationType("partner_commission"), deepLink: "/(partner)/earnings" }
    expect(inAppHrefForNotification(item, PARTNER)).toBe("/(partner)/earnings")
    expect(inAppHrefForNotification(item, VENDOR)).toBeNull()
  })

  it("keeps partner inbox rows typed and scoped", () => {
    const row = normalizeInboxItem({ id: "comms-1", title: "Commission approved", type: "partner_commission", role: "partner" })
    expect(row?.type).toBe("partner_commission")
    expect(row?.role).toBe("partner")
    expect(inboxTabsFor("partner")).toEqual([{ value: "all", label: "All" }])
  })
})

describe("partner API normalizers", () => {
  it("keeps estimates out of payable balances", () => {
    const d = normalizeDashboard({
      program: "affiliate",
      program_label: "Affiliate",
      code: "AFF1",
      links: { organizer: "https://x/?ref=AFF1&role=organizer" },
      earnings: { pending_hold: "10.5", awaiting_approval: 4, on_hold: 1, approved: 20, paid: 5, estimated: 99 },
      terms: { terms_pending: true, enabled: false },
    })
    expect(d.program).toBe("affiliate")
    expect(d.balances.estimated).toBe(99)
    expect(pendingTotal(d.balances)).toBe(15.5)
    expect(d.links.organizer).toContain("ref=AFF1")
  })

  it("never quotes a rate while terms are pending", () => {
    const pending = normalizeDashboard({ terms: { terms_pending: true, share_percent: 10 } })
    expect(termsSummary(pending.terms)).not.toMatch(/%/)
    const live = normalizeDashboard({
      terms: { enabled: true, basis_label: "10% of the booth price on qualifying bookings", term_months: 12, hold_days: 30 },
    })
    expect(termsSummary(live.terms)).toMatch(/^10% of the booth price/)
  })

  it("falls back safely on unknown programs and malformed rows", () => {
    expect(normalizeDashboard({ program: "bogus" }).program).toBe("city_connector")
    const referrals = normalizeReferrals({ organizers: [{ id: 0 }, { id: 2, display_name: "A" }], vendors: "nope" })
    expect(referrals.organizers).toHaveLength(1)
    expect(referrals.vendors).toEqual([])
    const earnings = normalizeEarnings({ ledger: [{ id: 3, net_amount: "12.5", display_status: "approved" }, null] })
    expect(earnings.ledger).toEqual([expect.objectContaining({ id: 3, netAmount: 12.5, status: "approved" })])
  })

  it("reports lead submission as disabled only when the server says so", () => {
    expect(normalizeLeads({}).enabled).toBe(true)
    expect(normalizeLeads({ enabled: false }).enabled).toBe(false)
  })

  it("requires a name and one public contact for leads", () => {
    expect(leadFormError({ name: "" })).toMatch(/name/)
    expect(leadFormError({ name: "Fair" })).toMatch(/website, Instagram or business email/)
    expect(leadFormError({ name: "Fair", instagram: "@fair" })).toBeNull()
  })
})
