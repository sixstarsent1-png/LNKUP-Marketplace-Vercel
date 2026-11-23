import { applyBlockFor, DECLINED_MESSAGE } from "../lib/api/apply"

describe("applyBlockFor", () => {
  it.each([
    ["deadline", "Applications closed"],
    ["full", "This booth is full"],
    ["closed", "Applications closed"],
    ["invite_only", "Invite only"],
    ["duplicate", "You already applied"],
  ])("stops the form for %s with a specific title", (code, title) => {
    expect(applyBlockFor(code)?.title).toBe(title)
  })

  it("keeps the declined explanation", () => {
    expect(applyBlockFor("previously_declined")?.message).toBe(DECLINED_MESSAGE)
  })

  it.each(["lnkup_price_changed", "lnkup_answers_required", "server_error", "", null, undefined])(
    "leaves the form open for %p so the vendor can review and resubmit",
    (code) => {
      expect(applyBlockFor(code)).toBeNull()
    },
  )
})
