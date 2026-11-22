import { shouldShowPushPrompt } from "@/lib/push/permission-prompt"
import * as Notifications from "expo-notifications"

jest.mock("expo-constants", () => ({
  __esModule: true,
  default: { easConfig: { projectId: "jest-project" }, expoConfig: { version: "1.0.0", extra: {} } },
}))
jest.mock("@/lib/api/push", () => ({
  registerPushDevice: jest.fn(async () => undefined),
  unregisterPushDevice: jest.fn(async () => undefined),
}))

// eslint-disable-next-line @typescript-eslint/no-require-imports
const native = require("@/lib/push/notifications.native") as typeof import("@/lib/push/notifications.native")
// eslint-disable-next-line @typescript-eslint/no-require-imports
const pushApi = require("@/lib/api/push") as { registerPushDevice: jest.Mock }
const mock = Notifications as unknown as { __state: { permission: { status: string; canAskAgain: boolean }; requestCalls: number; channels: string[] }; __reset: () => void }

const DAY = 24 * 60 * 60 * 1000

describe("shouldShowPushPrompt", () => {
  const now = Date.now()

  it("shows only while the OS has not decided", () => {
    expect(shouldShowPushPrompt("undetermined", null, now)).toBe(true)
    expect(shouldShowPushPrompt("granted", null, now)).toBe(false)
    expect(shouldShowPushPrompt("denied", null, now)).toBe(false)
    expect(shouldShowPushPrompt("unavailable", null, now)).toBe(false)
  })

  it("waits 30 days after 'Not now' and gives up after two dismissals", () => {
    expect(shouldShowPushPrompt("undetermined", { dismissedAt: now - 5 * DAY, count: 1 }, now)).toBe(false)
    expect(shouldShowPushPrompt("undetermined", { dismissedAt: now - 31 * DAY, count: 1 }, now)).toBe(true)
    expect(shouldShowPushPrompt("undetermined", { dismissedAt: now - 90 * DAY, count: 2 }, now)).toBe(false)
  })
})

describe("syncPushRegistration", () => {
  beforeEach(() => {
    mock.__reset()
    pushApi.registerPushDevice.mockClear()
  })

  it("does not show the OS prompt at app start (prompt: false)", async () => {
    const token = await native.syncPushRegistration(undefined, { prompt: false })
    expect(token).toBeNull()
    expect(mock.__state.requestCalls).toBe(0)
    expect(pushApi.registerPushDevice).not.toHaveBeenCalled()
  })

  it("prompts from an explicit action and registers the device", async () => {
    const token = await native.syncPushRegistration(undefined, { prompt: true })
    expect(mock.__state.requestCalls).toBe(1)
    expect(token).toBe("ExponentPushToken[jest]")
    expect(pushApi.registerPushDevice).toHaveBeenCalledTimes(1)
  })

  it("never re-prompts once the OS says it cannot ask again", async () => {
    mock.__state.permission = { status: "denied", canAskAgain: false }
    const token = await native.syncPushRegistration(undefined, { prompt: true })
    expect(token).toBeNull()
    expect(mock.__state.requestCalls).toBe(0)
    expect(await native.getPushPermissionState()).toBe("denied")
  })

  it("registers silently when permission was already granted", async () => {
    mock.__state.permission = { status: "granted", canAskAgain: true }
    const token = await native.syncPushRegistration(undefined, { prompt: false })
    expect(token).toBe("ExponentPushToken[jest]")
    expect(mock.__state.requestCalls).toBe(0)
  })
})
