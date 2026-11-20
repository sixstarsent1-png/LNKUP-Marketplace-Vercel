import { appErrorFromWp, WpFetchError } from "@/lib/api/errors"
import { isSessionExpired, type MobileSession } from "@/lib/api/auth"
import { resolveHomeHref, saveHomePreference } from "@/lib/auth/roles"
import { withFixtureFallback } from "@/lib/api/fixture-honesty"

function session(roles: string[], extra: Partial<MobileSession> = {}): MobileSession {
  return { username: "u", appPassword: "", token: "t", user: { id: 1, name: "U", slug: "u", roles }, ...extra }
}

describe("friendly errors", () => {
  it("replaces transport errors with plain language", () => {
    const shaped = appErrorFromWp(new WpFetchError("Network request failed", "network_error", 0))
    expect(shaped.message).toMatch(/offline|can't be reached/i)
    expect(shaped.message).not.toMatch(/network request failed/i)
    expect(shaped.retryable).toBe(true)
  })

  it("replaces server errors and generic fallbacks", () => {
    expect(appErrorFromWp(new WpFetchError("PHP Fatal error at line 3", "wp_error", 500)).message).toBe(
      "We couldn't reach LNKup. Try again in a moment.",
    )
    expect(appErrorFromWp(new WpFetchError("LNKup request failed", "wp_error", 400)).message).not.toMatch(
      /request failed/i,
    )
  })

  it("keeps messages the server wrote for people", () => {
    const shaped = appErrorFromWp(
      new WpFetchError("This vendor slot is full. Waitlist the vendor or add spots.", "opportunity_full", 409),
    )
    expect(shaped.message).toBe("This vendor slot is full. Waitlist the vendor or add spots.")
  })

  it("never shows raw JavaScript errors", () => {
    expect(appErrorFromWp(new TypeError("undefined is not an object")).message).not.toMatch(/undefined/)
  })

  it("reports timeouts", () => {
    expect(appErrorFromWp(new WpFetchError("Request timed out", "wordpress_unavailable", 408)).message).toMatch(
      /too long/i,
    )
  })
})

describe("session expiry", () => {
  it("treats a token past expires_at as expired", () => {
    const now = Date.UTC(2026, 10, 16)
    expect(isSessionExpired(session(["lnkup_vendor"], { expiresAt: now / 1000 - 10 }), now)).toBe(true)
    expect(isSessionExpired(session(["lnkup_vendor"], { expiresAt: now / 1000 + 3600 }), now)).toBe(false)
    expect(isSessionExpired(session(["lnkup_vendor"]), now)).toBe(false)
  })
})

describe("role homes", () => {
  it("sends single-role accounts to their shell", async () => {
    expect(await resolveHomeHref(session(["lnkup_vendor"]))).toBe("/(vendor)")
    expect(await resolveHomeHref(session(["lnkup_organizer"]))).toBe("/(organizer)")
  })

  it("sends accounts without a marketplace role to no-access instead of looping", async () => {
    expect(await resolveHomeHref(session(["subscriber"]))).toBe("/no-access")
    expect(await resolveHomeHref(session([]))).toBe("/no-access")
  })

  it("honours the saved preference for dual-role accounts", async () => {
    await saveHomePreference("vendor")
    expect(await resolveHomeHref(session(["lnkup_vendor", "lnkup_organizer"]))).toBe("/(vendor)")
  })
})

describe("fixture honesty", () => {
  it("lets live failures reach the screen instead of serving sample data", async () => {
    await expect(
      withFixtureFallback(
        "vendor.wallet",
        async () => {
          throw new Error("server down")
        },
        () => ({ dues: [{ id: "pay-mock-1" }] }),
      ),
    ).rejects.toThrow("server down")
  })
})
