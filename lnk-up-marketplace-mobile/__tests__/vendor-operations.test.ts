import {
  enrichWithVendorHome,
  formatBooth,
  formatLoadIn,
  isPassUnlocked,
  type VendorOperationsEvent,
} from "@/lib/api/vendor-operations"

const event: VendorOperationsEvent = {
  requestId: "12",
  eventName: "R&B Fest",
  status: "pass_ready",
  passStatus: "unlocked",
}

describe("vendor event passes mapper", () => {
  it("formats the booth object the server sends", () => {
    expect(formatBooth({ booth_number: "A12", booth_type_label: "Food", booth_size: "10x10" })).toBe("A12 (Food, 10x10)")
    expect(formatBooth({ booth_number: 7 })).toBe("7")
    expect(formatBooth(null)).toBeUndefined()
    expect(formatBooth("B4")).toBe("B4")
  })

  it("formats the load-in window object", () => {
    expect(formatLoadIn({ label: "8:00 AM – 9:00 AM", gate: "North" })).toBe("8:00 AM – 9:00 AM · Gate North")
    expect(formatLoadIn({ start_at: "2027-05-01 08:00", end_at: "2027-05-01 09:00" })).toBe(
      "2027-05-01 08:00 – 2027-05-01 09:00",
    )
    expect(formatLoadIn(null, "7 AM")).toBe("7 AM")
    expect(formatLoadIn(null)).toBeUndefined()
  })

  it("only renders strings, so assigned booths no longer crash the screen", () => {
    const result = enrichWithVendorHome(event, {
      application_id: 12,
      booth: { booth_number: "A12", booth_type_label: "Food", booth_size: "10x10" },
      loadin: { label: "8:00 AM – 9:00 AM", start_at: "x", end_at: "y", gate: "" },
      packet_status: "Not opened",
      qr_pass: "",
      readiness_percent: 80,
    })
    expect(typeof result.boothAssignment).toBe("string")
    expect(typeof result.loadInTime).toBe("string")
    expect(result.boothAssignment).toBe("A12 (Food, 10x10)")
    expect(result.loadInTime).toBe("8:00 AM – 9:00 AM")
    expect(result.readinessPercent).toBe(80)
  })

  it("reads pass unlock state from the real fields", () => {
    expect(isPassUnlocked({ qr_pass: "LNK-ABC123" })).toBe(true)
    expect(isPassUnlocked({ packet_status: "Locked", qr_pass: "" })).toBe(false)
    expect(isPassUnlocked({ packet_status: "Complete" })).toBe(true)
    expect(isPassUnlocked({ packet_status: "" })).toBe(false)
    expect(isPassUnlocked({})).toBe(false)
  })

  it("upgrades an upcoming booking to pass ready once the pass is unlocked", () => {
    const upcoming: VendorOperationsEvent = { ...event, status: "upcoming", passStatus: "locked" }
    const result = enrichWithVendorHome(upcoming, { qr_pass: "LNK-1" })
    expect(result.passStatus).toBe("unlocked")
    expect(result.status).toBe("pass_ready")
  })
})
