import serverTypes from "./fixtures/comms-types.json"

import { NOTIFICATION_TYPES, inboxTabsFor } from "@/lib/notifications/types"
import { hrefFromNotificationData } from "@/lib/push/notification-routing"
import { destinationScope } from "@/lib/navigation/pending-destination"

/**
 * `comms-types.json` is exported from WordPress (`wp lnkup-comms export-types`). A server type the
 * app does not know renders as a generic update, so every new server type must be added here first.
 */
describe("notification types stay in sync with lnkup-communications", () => {
  const known = new Set<string>(NOTIFICATION_TYPES)

  it("knows every type the server can send", () => {
    const missing = serverTypes.types.map((t) => t.type).filter((type) => !known.has(type))
    expect(missing).toEqual([])
  })

  it("routes every server type to an allowlisted in-app screen", () => {
    const unroutable = serverTypes.types
      .map((t) => t.type)
      .filter((type) => {
        const href = hrefFromNotificationData({ type })
        return !href || destinationScope(href) === null
      })
    expect(unroutable).toEqual([])
  })

  it("matches the server inbox tabs for each role", () => {
    expect(inboxTabsFor("vendor").map((t) => t.value)).toEqual(serverTypes.tabs.vendor)
    expect(inboxTabsFor("organizer").map((t) => t.value)).toEqual(serverTypes.tabs.organizer)
    expect(inboxTabsFor("partner").map((t) => t.value)).toEqual(serverTypes.tabs.partner)
  })
})
