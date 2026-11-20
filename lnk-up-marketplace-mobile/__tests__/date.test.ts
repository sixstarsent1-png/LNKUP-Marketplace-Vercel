import { formatShortDate, localIsoDay, parseCalendarDate } from "@/lib/format/date"

describe("calendar dates", () => {
  it("keeps a WordPress Y-m-d on the same calendar day in every time zone", () => {
    const parsed = parseCalendarDate("2026-12-30")!
    expect(parsed.getFullYear()).toBe(2026)
    expect(parsed.getMonth()).toBe(11)
    expect(parsed.getDate()).toBe(30)
    expect(formatShortDate("2026-12-30")).toBe("Dec 30, 2026")
  })

  it("parses MySQL datetimes as local time", () => {
    const parsed = parseCalendarDate("2026-11-16 09:30:00")!
    expect(parsed.getHours()).toBe(9)
    expect(parsed.getMinutes()).toBe(30)
  })

  it("passes through non-dates", () => {
    expect(formatShortDate("Next Saturday")).toBe("Next Saturday")
    expect(formatShortDate(undefined)).toBeUndefined()
  })

  it("formats today as a local Y-m-d", () => {
    expect(localIsoDay(new Date(2026, 0, 5))).toBe("2026-01-05")
  })
})
