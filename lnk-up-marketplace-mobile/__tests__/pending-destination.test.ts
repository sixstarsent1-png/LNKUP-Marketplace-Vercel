import {
  clearPendingDestination,
  destinationScope,
  isDestinationAllowed,
  resetPendingDestinationForSignOut,
  safeDestination,
  setPendingDestination,
  takePendingDestination,
} from "@/lib/navigation/pending-destination"

const vendor = ["lnkup_vendor"]
const organizer = ["lnkup_organizer"]

describe("destination rules", () => {
  it("recognises in-app routes and their role", () => {
    expect(destinationScope("/(vendor)/requests?filter=payment")).toBe("vendor")
    expect(destinationScope("/(organizer)/events/56")).toBe("organizer")
    expect(destinationScope("/pass/71")).toBe("vendor")
    expect(destinationScope("/opportunity/59")).toBe("any")
  })

  it("rejects external, protocol-relative and traversal paths", () => {
    expect(destinationScope("https://evil.test/x")).toBeNull()
    expect(destinationScope("//evil.test")).toBeNull()
    expect(destinationScope("/(vendor)/../../x")).toBeNull()
    expect(destinationScope("/unknown/route")).toBeNull()
  })

  it("enforces the role", () => {
    expect(isDestinationAllowed("/(organizer)/money", vendor)).toBe(false)
    expect(isDestinationAllowed("/(organizer)/money", organizer)).toBe(true)
    expect(isDestinationAllowed("/pass/71", organizer)).toBe(false)
    expect(isDestinationAllowed("/opportunity/59", organizer)).toBe(true)
  })

  it("decodes encoded returnTo values", () => {
    expect(safeDestination("%2F(vendor)%2Fwallet", vendor)).toBe("/(vendor)/wallet")
  })
})

describe("pending destination queue", () => {
  beforeEach(() => clearPendingDestination())

  it("hands an allowed destination back once", async () => {
    setPendingDestination("/(vendor)/wallet")
    expect(await takePendingDestination(vendor)).toBe("/(vendor)/wallet")
    expect(await takePendingDestination(vendor)).toBeNull()
  })

  it("drops a destination the signed-in role may not open", async () => {
    setPendingDestination("/(organizer)/money")
    expect(await takePendingDestination(vendor)).toBeNull()
  })

  it("ignores the shell redirect right after an explicit sign-out", async () => {
    await resetPendingDestinationForSignOut()
    setPendingDestination("/(vendor)/more")
    expect(await takePendingDestination(vendor)).toBeNull()
  })
})
