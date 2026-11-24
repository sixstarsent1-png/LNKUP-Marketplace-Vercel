import { mapPublicEvent } from "@/lib/api/deep-links"
import { hrefForResolved, mapSystemPath } from "@/lib/navigation/deep-link-map"
import { destinationScope } from "@/lib/navigation/pending-destination"
import { attributionFromUrl, pickAttribution } from "@/lib/navigation/pending-attribution"

const vendor = ["lnkup_vendor"]
const organizer = ["lnkup_organizer"]

describe("mapSystemPath", () => {
  it("keeps existing profile and opportunity links", () => {
    expect(mapSystemPath("/vendors?vendor=taco-co")).toBe("/storefront/vendor?vendor=taco-co")
    expect(mapSystemPath("/organizers?organizer=fest-co")).toBe("/storefront/organizer?organizer=fest-co")
    expect(mapSystemPath("/opportunities?apply=42")).toBe("/opportunity/42")
    expect(mapSystemPath("/opportunities/")).toBe("/(vendor)/find")
  })

  it("opens pretty profile URLs in the storefront", () => {
    expect(mapSystemPath("https://thelnkup.com/organizers/fest-co/")).toBe("/storefront/organizer?organizer=fest-co")
    expect(mapSystemPath("/vendors/taco-co")).toBe("/storefront/vendor?vendor=taco-co")
  })

  it("sends event, market, tour and tracked links to the resolver", () => {
    expect(mapSystemPath("/events/summer-fest/")).toBe(`/link?path=${encodeURIComponent("/events/summer-fest/")}`)
    expect(mapSystemPath("https://thelnkup.com/markets/charlotte/food/?utm_source=ig")).toBe(
      `/link?path=${encodeURIComponent("/markets/charlotte/food/")}`,
    )
    expect(mapSystemPath("/tours/spring-tour")).toBe(`/link?path=${encodeURIComponent("/tours/spring-tour/")}`)
    expect(mapSystemPath("/l/Ab12cd34")).toBe(`/link?path=${encodeURIComponent("/l/Ab12cd34/")}`)
  })

  it("leaves unknown or malformed paths untouched", () => {
    expect(mapSystemPath("/events/")).toBe("/events/")
    expect(mapSystemPath("/events/a/b/c")).toBe("/events/a/b/c")
    expect(mapSystemPath("/events/<script>")).toBe("/events/<script>")
    expect(mapSystemPath("/l/x")).toBe("/l/x")
    expect(mapSystemPath("/pricing")).toBe("/pricing")
  })
})

describe("hrefForResolved", () => {
  const base = { id: 0, params: {}, attribution: {} }

  it("maps resolved targets to app screens", () => {
    expect(hrefForResolved({ ...base, route: "event", id: 7 }, undefined)).toBe("/event/7")
    expect(hrefForResolved({ ...base, route: "opportunity", id: 9 }, vendor)).toBe("/opportunity/9")
    expect(hrefForResolved({ ...base, route: "tour", id: 3 }, undefined)).toBe("/tour/3")
    expect(hrefForResolved({ ...base, route: "organizer", params: { organizer: "fest co" } }, undefined)).toBe(
      "/storefront/organizer?organizer=fest%20co",
    )
  })

  it("sends signup links to signup only when signed out", () => {
    expect(hrefForResolved({ ...base, route: "signup", params: { role: "organizer" } }, undefined)).toBe("/signup?role=organizer")
    expect(hrefForResolved({ ...base, route: "signup", params: { role: "x" } }, undefined)).toBe("/signup?role=vendor")
    expect(hrefForResolved({ ...base, route: "signup", params: { role: "vendor" } }, vendor)).toBe("/")
  })

  it("opens Find with market filters for vendors and guests, home for organizers", () => {
    const feed = { ...base, route: "feed" as const, params: { city: "Charlotte", state: "NC", category: "Food & Beverage" } }
    expect(hrefForResolved(feed, undefined)).toBe("/(vendor)/find?city=Charlotte&state=NC&category=Food+%26+Beverage")
    expect(hrefForResolved({ ...base, route: "feed" }, vendor)).toBe("/(vendor)/find")
    expect(hrefForResolved(feed, organizer)).toBe("/")
  })
})

describe("attribution capture", () => {
  it("keeps only allowed, non-empty keys", () => {
    expect(pickAttribution({ ref: " ABC ", org_ref: "", cc: ["CC1", "CC2"], email: "a@b.c", password: "x" })).toEqual({
      ref: "ABC",
      cc: "CC1",
    })
    expect(pickAttribution({ utm_source: "x".repeat(201) })).toEqual({})
  })

  it("reads campaign parameters and the landing path from a link", () => {
    expect(attributionFromUrl("https://thelnkup.com/events/fest/?utm_source=ig&utm_campaign=spring&ref=R1&foo=bar")).toEqual({
      utm_source: "ig",
      utm_campaign: "spring",
      ref: "R1",
      landing: "/events/fest/",
    })
    expect(attributionFromUrl("/events/fest/")).toEqual({})
  })
})

describe("public event payload", () => {
  it("maps WordPress fields and drops unknown states and empty opportunities", () => {
    const event = mapPublicEvent({
      id: 5,
      title: "Fest",
      state: "weird",
      state_code: "NC",
      organizer: { name: "Fest Co", slug: "fest-co" },
      opportunities: [{ id: 0 }, { id: 8, title: "Food", status_label: "Open", deadline: "2026-12-01" }],
    })
    expect(event.state).toBe("scheduled")
    expect(event.stateCode).toBe("NC")
    expect(event.organizerSlug).toBe("fest-co")
    expect(event.opportunities).toEqual([{ id: 8, title: "Food", statusLabel: "Open", deadline: "2026-12-01" }])
  })
})

describe("pending destinations", () => {
  it("allows the public event screen after sign-in", () => {
    expect(destinationScope("/event/12")).toBe("any")
    expect(destinationScope("/event/abc")).toBeNull()
  })
})
