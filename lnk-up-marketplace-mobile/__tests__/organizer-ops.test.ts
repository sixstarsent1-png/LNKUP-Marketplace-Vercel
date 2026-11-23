import { WpFetchError } from "../lib/api/errors"
import { fetchEventOps, mapEventTask } from "../lib/api/organizer-ops"
import { wpFetchJson } from "../lib/api/wp-client"

jest.mock("../lib/api/wp-client", () => ({ wpFetchJson: jest.fn() }))

const fetchMock = wpFetchJson as jest.MockedFunction<typeof wpFetchJson>
const auth = { username: "staff", appPassword: "x" }

describe("event-day operations for staff", () => {
  beforeEach(() => fetchMock.mockReset())

  it("maps a task with its assignee, due date and completion right", () => {
    expect(
      mapEventTask({ id: 7, title: "Tape booth lanes", due_at: "2026-12-01 09:00:00", overdue: true, assignee: { name: "Ana" }, can_complete: true }),
    ).toEqual({ id: 7, title: "Tape booth lanes", dueAt: "2026-12-01 09:00:00", overdue: true, priority: "normal", assignee: "Ana", canComplete: true })
  })

  it("hides the panel when the site does not run event operations", async () => {
    fetchMock.mockRejectedValueOnce(new WpFetchError("This feature is not turned on yet.", "lnkup_feature_off", 404))
    await expect(fetchEventOps("12", auth)).resolves.toEqual({ kind: "off" })
  })

  it("shows tasks even when readiness is not available", async () => {
    fetchMock
      .mockResolvedValueOnce({ items: [{ id: 1, title: "Check power" }], counts: { open: 1, overdue: 0 } })
      .mockRejectedValueOnce(new WpFetchError("Not found", "rest_no_route", 404))
    const ops = await fetchEventOps("12", auth)
    expect(ops).toMatchObject({ kind: "ready", readiness: null, overdue: 0 })
    expect(ops.kind === "ready" ? ops.tasks.map((t) => t.title) : []).toEqual(["Check power"])
  })

  it("surfaces a server failure instead of hiding it", async () => {
    fetchMock.mockRejectedValueOnce(new WpFetchError("Internal failure", "server_error", 500))
    await expect(fetchEventOps("12", auth)).rejects.toBeInstanceOf(WpFetchError)
  })
})
