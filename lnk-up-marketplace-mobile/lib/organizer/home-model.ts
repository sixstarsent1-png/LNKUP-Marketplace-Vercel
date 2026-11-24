import type { Href } from "expo-router"

import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import type { OrgEventCard, OrgHomeSummary, OrgQueueGroup, OrgQueueKey } from "@/lib/api/organizer-mobile"

/**
 * Home for servers without `/organizer/home-summary`: the old summary's counts in the new shape.
 * Upcoming events, recruitment and queue rows stay empty rather than guessed.
 */
export function legacyHomeToOrgHome(old: OrganizerHomeSummary): OrgHomeSummary {
  const activation = old.activation
  return {
    lifecycle: old.stats.events === 0 ? "new" : activation && !activation.isActivated ? "setup" : "active",
    setup: {
      percent: activation?.percent ?? 0,
      completed: activation?.completed ?? 0,
      total: activation?.total ?? 0,
      isActivated: activation?.isActivated ?? true,
      next: activation?.nextAction ?? null,
      stages: activation?.stages ?? [],
      share: null,
    },
    kpis: {
      collected: { value: old.financial?.collectedToDate ?? 0, label: "Collected after LNKup fees · all time" },
      awaitingReview: { value: old.stats.vendorRequests, label: "Applications to review" },
      approvedUnpaid: { value: old.stats.paymentRequired, label: "Approved, not yet paid" },
      upcomingEvents: { value: old.stats.events, label: "Events" },
      confirmed: { value: old.stats.paidVendors, label: "Confirmed vendors" },
    },
    events: {
      counts: { total: old.stats.events, live: 0, draft: 0, upcoming: 0, today: 0, completed: 0 },
      today: [],
      next: null,
      upcoming: [],
    },
    recruitment: [],
    queue: [],
    money: {
      collected: old.financial?.collectedToDate ?? 0,
      refunds: 0,
      netEarnings: 0,
      available: null,
      pending: null,
      label: "Collected after LNKup fees · all time",
      payoutsReady: null,
    },
  }
}

/** Counts the guided tutorial reads, derived from the home summary. */
export function tutorialStats(home: OrgHomeSummary): OrganizerHomeSummary["stats"] {
  return {
    events: home.events.counts.total,
    openOpportunities: home.events.counts.live,
    vendorRequests: home.kpis.awaitingReview.value,
    approvedVendors: home.kpis.approvedUnpaid.value + home.kpis.confirmed.value,
    paymentRequired: home.kpis.approvedUnpaid.value,
    paidVendors: home.kpis.confirmed.value,
  }
}

/** Native destination for each setup stage (no web handoff for daily setup). */
export function setupStageHref(stage: string, home: Pick<OrgHomeSummary, "setup" | "events">): Href {
  const eventId = home.setup.share?.eventId || home.events.next?.id || home.events.upcoming[0]?.id
  switch (stage) {
    case "organization":
      return "/(organizer)/profile" as Href
    case "payouts":
    case "first_paid_booking":
      return "/(organizer)/money" as Href
    case "first_event":
      return (eventId ? `/(organizer)/events/${eventId}` : "/(organizer)/launch") as Href
    case "publication":
      return (eventId ? `/(organizer)/events/${eventId}` : "/(organizer)/events") as Href
    case "first_applications":
      return "/(organizer)/vendors?segment=discover" as Href
    default:
      return "/(organizer)/events" as Href
  }
}

/** Where an action-queue row opens. */
export function queueRowHref(key: OrgQueueKey, row: OrgQueueGroup["rows"][number]): Href {
  switch (key) {
    case "review":
    case "documents":
      return (row.appId ? `/(organizer)/vendors/review/${row.appId}` : "/(organizer)/vendors?tab=requests") as Href
    case "payment":
      return (row.eventId ? `/(organizer)/money/event?eventId=${row.eventId}` : "/(organizer)/money") as Href
    case "booths":
      return `/(organizer)/events/booths?eventId=${row.eventId}` as Href
    case "packet":
      return `/(organizer)/packet?eventId=${row.eventId}` as Href
    default: {
      const exhaustive: never = key
      return exhaustive
    }
  }
}

/** Queue group "See all" destination. */
export function queueGroupHref(key: OrgQueueKey): Href {
  switch (key) {
    case "review":
      return "/(organizer)/vendors?tab=requests&filter=review" as Href
    case "documents":
      return "/(organizer)/vendors?tab=requests&filter=awaiting_payment" as Href
    case "payment":
      return "/(organizer)/money?segment=invoices" as Href
    case "booths":
    case "packet":
      return "/(organizer)/events" as Href
    default: {
      const exhaustive: never = key
      return exhaustive
    }
  }
}

/** Check-in is promoted only on the event day or the day before. */
export function checkinEvent(home: Pick<OrgHomeSummary, "events">): OrgEventCard | null {
  if (home.events.today[0]) return home.events.today[0]
  const next = home.events.next
  return next && next.daysUntil != null && next.daysUntil <= 1 ? next : null
}
