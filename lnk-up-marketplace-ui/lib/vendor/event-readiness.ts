import type { VendorEventDayChecklistItem } from "@/lib/api/vendor-operations"
import type { VendorPacketPreview } from "@/lib/api/vendor-packet"
import type { VendorRequest } from "@/lib/api/vendor-requests"

export type VendorEventReadinessTier = "ready" | "on_track" | "at_risk" | "unknown"

export type VendorEventReadiness = {
  requestId: string
  percent: number
  tier: VendorEventReadinessTier
  nextAction?: {
    label: string
    href: string
  }
  items: VendorEventDayChecklistItem[]
}

function isPaymentComplete(request: VendorRequest, packet?: VendorPacketPreview | null): boolean {
  if (packet?.status === "unlocked") return true
  return request.status === "paid" || request.status === "completed"
}

function isPassUnlocked(packet?: VendorPacketPreview | null): boolean {
  return packet?.status === "unlocked"
}

export function buildVendorEventReadiness(
  request: VendorRequest,
  packet?: VendorPacketPreview | null,
): VendorEventReadiness {
  const requestId = request.id
  const passUrl = `/vendor/pass/${requestId}`
  const paymentUrl = `/vendor/requests?pay=${requestId}`

  const paymentDone = isPaymentComplete(request, packet)
  const passUnlocked = isPassUnlocked(packet)

  const items: VendorEventDayChecklistItem[] = [
    {
      id: "payment",
      label: "Payment complete",
      complete: paymentDone,
      severity: "required",
      actionHref: paymentDone ? undefined : paymentUrl,
      actionLabel: paymentDone ? undefined : "Continue payment",
    },
    {
      id: "pass",
      label: "Pass unlocked",
      complete: passUnlocked,
      severity: "required",
      actionHref: passUnlocked ? passUrl : paymentUrl,
      actionLabel: passUnlocked ? "View pass" : "Unlock pass",
    },
    {
      id: "loadin",
      label: "Load-in reviewed",
      complete: Boolean(packet?.loadInTime),
      severity: "recommended",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "Review load-in",
    },
    {
      id: "parking",
      label: "Parking reviewed",
      complete: Boolean(packet?.parkingInstructions),
      severity: "recommended",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "Review parking",
    },
    {
      id: "booth",
      label: "Booth assignment reviewed",
      complete: Boolean(packet?.boothAssignment),
      severity: "recommended",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "View booth",
    },
    {
      id: "rules",
      label: "Rules reviewed",
      complete: Boolean(packet?.vendorRules?.length),
      severity: "recommended",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "View rules",
    },
    {
      id: "checkin",
      label: "Check-in code ready",
      complete: Boolean(packet?.checkInCode || packet?.qrCodeUrl),
      severity: "info",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "View check-in",
    },
    {
      id: "weather",
      label: "Weather plan reviewed",
      complete: Boolean(packet?.weatherNote),
      severity: "info",
      actionHref: passUnlocked ? passUrl : undefined,
      actionLabel: "View weather",
    },
  ]

  const complete = items.filter((i) => i.complete).length
  const percent = items.length ? Math.round((complete / items.length) * 100) : 0
  const tier = computeEventDayTier(percent, paymentDone, passUnlocked)

  let nextAction: VendorEventReadiness["nextAction"]
  if (!paymentDone) {
    nextAction = { label: "Continue payment", href: paymentUrl }
  } else if (!passUnlocked) {
    nextAction = { label: "View pass status", href: passUrl }
  } else if (!packet?.loadInTime) {
    nextAction = { label: "Review load-in details", href: passUrl }
  } else {
    nextAction = { label: "Open event pass", href: passUrl }
  }

  return { requestId, percent, tier, nextAction, items }
}

export function computeEventDayTier(
  percent: number,
  paymentDone: boolean,
  passUnlocked: boolean,
): VendorEventReadinessTier {
  if (!paymentDone) return "at_risk"
  if (!passUnlocked) return "on_track"
  if (percent >= 85) return "ready"
  if (percent >= 50) return "on_track"
  return "at_risk"
}

export function parseEventDateMs(dateStr?: string): number | null {
  if (!dateStr) return null
  const parsed = Date.parse(dateStr)
  return Number.isNaN(parsed) ? null : parsed
}

export function isEventWithinHours(dateStr: string | undefined, hours: number): boolean {
  const ms = parseEventDateMs(dateStr)
  if (ms === null) return false
  const now = Date.now()
  const diff = ms - now
  return diff >= 0 && diff <= hours * 60 * 60 * 1000
}

export function isEventDay(dateStr?: string): boolean {
  const ms = parseEventDateMs(dateStr)
  if (ms === null) return false
  const eventDay = new Date(ms)
  const now = new Date()
  return (
    eventDay.getFullYear() === now.getFullYear() &&
    eventDay.getMonth() === now.getMonth() &&
    eventDay.getDate() === now.getDate()
  )
}
