import { getWpBaseUrl, isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_PASS_LOCKED, MOCK_PASS_UNLOCKED } from "@/lib/data/fixtures"

export type VendorEventPass = {
  requestId: string
  applicationId: string
  status: "locked" | "unlocked"
  headline: string
  message: string
  eventName: string
  eventDate?: string
  startTime?: string
  endTime?: string
  city?: string
  state?: string
  venueName?: string
  venueAddress?: string
  mapUrl?: string
  mapImageUrl?: string
  organizerName?: string
  organizerEmail?: string
  organizerPhone?: string
  loadInTime?: string
  loadInGate?: string
  loadOutTime?: string
  parkingInstructions?: string
  boothAssignment?: string
  boothZone?: string
  boothDimensions?: string
  powerDetails?: string
  waterDetails?: string
  weatherNote?: string
  rainPlan?: string
  heatPlan?: string
  forecastStatus?: string
  weatherRecommendations: string[]
  contingency?: string
  weatherRisks: string[]
  dayOfContact?: string
  emergencyContact?: string
  vendorRules: string[]
  setupRestrictions: string[]
  prohibitedItems: string[]
  refundPolicy?: string
  weatherPolicy?: string
  checkInCode?: string
  qrCodeUrl?: string
  unlocks: string[]
  amountDue?: number
  amountDueFormatted?: string
  readinessPercent?: number
  readinessLabel?: string
  readinessRiskBucket?: "green" | "amber" | "red"
  readinessMissing: string[]
  emptyMessage?: string
  wordpressUrl?: string
  paymentId?: string
}

/** Real WP DTO + legacy flat/mock shape. */
type WpPacketPassResponse = {
  application_id?: number
  status?: string
  locked?: boolean
  is_unlocked?: boolean
  headline?: string
  message?: string
  empty_message?: string
  unlocks_after_payment?: string[]
  unlocks?: string[]
  refund_policy?: string
  weather_policy?: string
  ops_url?: string
  wordpress_url?: string
  event?: {
    title?: string
    name?: string
    date?: string
    start_time?: string
    end_time?: string
    city?: string
    state?: string
    venue_name?: string
    address?: string
    venue_address?: string
    map_url?: string
  }
  organizer?: {
    name?: string
    email?: string
    phone?: string
  }
  logistics?: {
    load_in?: string
    load_out?: string
    parking?: string
    booth?: string
    power?: string
    water?: string
    weather?: string
  }
  booth?: {
    assignment?: string
    zone?: string
    dimensions?: string
    has_power?: boolean
    has_water?: boolean
  } | null
  load_in?: {
    window?: string
    gate?: string
    notes?: string
  }
  load_out?: {
    time?: string
  }
  parking?: {
    lot?: string
    instructions?: string
    gate?: string
  } | null
  map?: {
    attachment_url?: string
    map_url?: string
  }
  contacts?: {
    organizer?: { name?: string; email?: string; phone?: string }
    day_of?: { name?: string; phone?: string; email?: string } | string
    emergency?:
      | { name?: string; phone?: string }
      | Array<{ name?: string; phone?: string; label?: string }>
  }
  rules?: {
    vendor_rules?: string | string[]
    setup_restrictions?: string | string[]
    prohibited_items?: string | string[]
  }
  policies?: {
    refund?: string
    weather?: string
  }
  weather?: {
    summary?: string
    rain_plan?: string
    heat_plan?: string
    forecast_status?: string
    recommendations?: unknown
    contingency?: string
    contingency_plan?: string
    risks?: unknown
  }
  qr_pass?: {
    code?: string
    enabled?: boolean
    url?: string
    image_url?: string
  }
  check_in_code?: string
  readiness?: {
    percent?: number
    label?: string
    missing_items?: string[]
  }
  payment?: {
    payment_id?: number
    id?: number
    status?: string
    amount_due?: number
    formatted?: string
  }
  cta?: { pay_url?: string; label?: string }
}

function asLines(value: string | string[] | undefined | null): string[] {
  if (!value) return []
  if (Array.isArray(value)) return value.map(String).map((s) => s.trim()).filter(Boolean)
  return String(value)
    .split(/\r?\n|;/)
    .map((s) => s.trim())
    .filter(Boolean)
}

function formatPerson(
  contact?: { name?: string; phone?: string; email?: string; label?: string } | string | null,
): string | undefined {
  if (!contact) return undefined
  if (typeof contact === "string") {
    const t = contact.trim()
    return t || undefined
  }
  const parts = [contact.label || contact.name, contact.phone, contact.email].filter(Boolean)
  return parts.length ? parts.join(" · ") : undefined
}

function formatEmergency(
  emergency?:
    | { name?: string; phone?: string }
    | Array<{ name?: string; phone?: string; label?: string }>
    | null,
): string | undefined {
  if (!emergency) return undefined
  if (Array.isArray(emergency)) {
    const lines = emergency
      .map((item) => formatPerson(item))
      .filter(Boolean) as string[]
    return lines.length ? lines.join("\n") : undefined
  }
  return formatPerson(emergency)
}

function boolAmenity(has: boolean | undefined, label: string, fallback?: string): string | undefined {
  if (has === true) return label
  if (has === false) return undefined
  return fallback
}

function buildBoothLine(booth?: WpPacketPassResponse["booth"], legacy?: string): string | undefined {
  if (!booth) return legacy || undefined
  const parts = [booth.assignment, booth.zone, booth.dimensions].filter(Boolean)
  return parts.length ? parts.join(" · ") : legacy || undefined
}

function buildParking(parking?: WpPacketPassResponse["parking"], legacy?: string): string | undefined {
  if (!parking) return legacy || undefined
  const parts = [parking.lot, parking.instructions, parking.gate ? `Gate: ${parking.gate}` : ""]
    .filter(Boolean)
  return parts.length ? parts.join(" — ") : legacy || undefined
}

function buildLoadIn(
  loadIn?: WpPacketPassResponse["load_in"],
  legacy?: string,
): { window?: string; gate?: string } {
  if (!loadIn) return { window: legacy }
  const windowParts = [loadIn.window, loadIn.notes].filter(Boolean)
  return {
    window: windowParts.length ? windowParts.join(" · ") : legacy,
    gate: loadIn.gate || undefined,
  }
}

function asRecommendationLines(value: unknown): string[] {
  if (!value) return []
  if (Array.isArray(value)) {
    return value
      .map((item) => {
        if (typeof item === "string") return item.trim()
        if (item && typeof item === "object") {
          const row = item as Record<string, unknown>
          return String(row.label ?? row.text ?? row.message ?? "").trim()
        }
        return ""
      })
      .filter(Boolean)
  }
  if (typeof value === "string") return asLines(value)
  return []
}

function asRiskLines(value: unknown): string[] {
  if (!value) return []
  if (Array.isArray(value)) return asRecommendationLines(value)
  if (typeof value === "object") {
    const entries = Object.entries(value as Record<string, unknown>)
      .filter(([key]) => key !== "recommendations" && key !== "confidence")
      .map(([key, raw]) => {
        const label = key.replace(/_/g, " ")
        const val = String(raw ?? "").trim()
        if (!val || val === "low" || val === "none") return ""
        return `${label}: ${val}`
      })
      .filter(Boolean)
    return entries
  }
  if (typeof value === "string") return asLines(value)
  return []
}

export function mapVendorPass(requestId: string, data: WpPacketPassResponse): VendorEventPass {
  const locked =
    data.locked === true ||
    data.status === "locked" ||
    data.is_unlocked === false

  const vendorRules = asLines(data.rules?.vendor_rules)
  const setupRestrictions = asLines(data.rules?.setup_restrictions)
  const prohibitedItems = asLines(data.rules?.prohibited_items)
  const wpBase = getWpBaseUrl()

  const eventName = data.event?.title || data.event?.name || "Event"
  const venueAddress = data.event?.address || data.event?.venue_address
  const organizer =
    data.contacts?.organizer ?? data.organizer

  const loadIn = buildLoadIn(data.load_in, data.logistics?.load_in)
  const loadOut = data.load_out?.time || data.logistics?.load_out
  const boothLine = buildBoothLine(data.booth, data.logistics?.booth)
  const parking = buildParking(data.parking, data.logistics?.parking)

  const powerDetails =
    boolAmenity(data.booth?.has_power, "Power available at booth", data.logistics?.power) ??
    data.logistics?.power
  const waterDetails =
    boolAmenity(data.booth?.has_water, "Water available", data.logistics?.water) ??
    data.logistics?.water

  const weatherNote =
    data.weather?.summary || data.logistics?.weather || undefined
  const rainPlan = data.weather?.rain_plan || undefined
  const heatPlan = data.weather?.heat_plan || undefined
  const forecastStatus = data.weather?.forecast_status || undefined
  const weatherRecommendations = asRecommendationLines(data.weather?.recommendations)
  const contingency =
    data.weather?.contingency || data.weather?.contingency_plan || undefined
  const weatherRisks = asRiskLines(data.weather?.risks)
  const weatherPolicyJoined = [rainPlan, heatPlan].filter(Boolean).join(" · ")
  const weatherPolicy =
    data.policies?.weather ?? data.weather_policy ?? (weatherPolicyJoined || undefined)

  const mapImageUrl = data.map?.attachment_url || undefined
  const mapUrl =
    data.event?.map_url ||
    (venueAddress
      ? `https://maps.google.com/?q=${encodeURIComponent(venueAddress)}`
      : undefined)

  const paymentId = data.payment?.payment_id
    ? String(data.payment.payment_id)
    : data.payment?.id
      ? String(data.payment.id)
      : undefined

  const unlocks =
    data.unlocks_after_payment ??
    data.unlocks ?? [
      "Full venue address",
      "Load-in and parking details",
      "Booth assignment",
      "Check-in QR code",
      "Day-of contacts",
    ]

  const dayOf = locked ? undefined : formatPerson(data.contacts?.day_of)
  const emergency = locked ? undefined : formatEmergency(data.contacts?.emergency)

  return {
    requestId,
    applicationId: String(data.application_id ?? requestId),
    status: locked ? "locked" : "unlocked",
    headline:
      data.headline ??
      (locked
        ? "Your vendor pass unlocks after payment"
        : "You're booked — your event pass is ready"),
    message:
      data.message ??
      (locked
        ? "Complete payment to unlock your full event pass with logistics, check-in, and day-of details."
        : "Your event pass is ready. Review logistics and check-in details below."),
    eventName,
    eventDate: data.event?.date,
    startTime: data.event?.start_time,
    endTime: data.event?.end_time,
    city: data.event?.city,
    state: data.event?.state,
    venueName: locked ? undefined : data.event?.venue_name,
    venueAddress: locked ? undefined : venueAddress,
    mapUrl: locked ? undefined : mapUrl,
    mapImageUrl: locked ? undefined : mapImageUrl,
    organizerName: organizer?.name,
    organizerEmail: locked ? undefined : organizer?.email,
    organizerPhone: locked ? undefined : organizer?.phone,
    loadInTime: locked ? undefined : loadIn.window,
    loadInGate: locked ? undefined : loadIn.gate,
    loadOutTime: locked ? undefined : loadOut,
    parkingInstructions: locked ? undefined : parking,
    boothAssignment: locked ? undefined : boothLine,
    boothZone: locked ? undefined : data.booth?.zone || undefined,
    boothDimensions: locked ? undefined : data.booth?.dimensions || undefined,
    powerDetails: locked ? undefined : powerDetails,
    waterDetails: locked ? undefined : waterDetails,
    weatherNote: locked ? undefined : weatherNote,
    rainPlan: locked ? undefined : rainPlan,
    heatPlan: locked ? undefined : heatPlan,
    forecastStatus: locked ? undefined : forecastStatus,
    weatherRecommendations: locked ? [] : weatherRecommendations,
    contingency: locked ? undefined : contingency,
    weatherRisks: locked ? [] : weatherRisks,
    dayOfContact: dayOf,
    emergencyContact: emergency,
    vendorRules: locked ? [] : vendorRules,
    setupRestrictions: locked ? [] : setupRestrictions,
    prohibitedItems: locked ? [] : prohibitedItems,
    refundPolicy: locked
      ? undefined
      : (data.policies?.refund ?? data.refund_policy ?? undefined),
    weatherPolicy: locked ? undefined : weatherPolicy,
    checkInCode: locked ? undefined : (data.qr_pass?.code ?? data.check_in_code),
    qrCodeUrl: locked ? undefined : (data.qr_pass?.image_url ?? data.qr_pass?.url),
    unlocks,
    amountDue:
      typeof data.payment?.amount_due === "number" && data.payment.amount_due > 0
        ? data.payment.amount_due
        : undefined,
    amountDueFormatted: data.payment?.formatted || undefined,
    readinessPercent: locked ? undefined : data.readiness?.percent,
    readinessLabel: locked ? undefined : data.readiness?.label,
    readinessRiskBucket: locked
      ? undefined
      : data.readiness?.label?.toLowerCase().includes("risk") || (data.readiness?.percent ?? 100) < 60
        ? "red"
        : (data.readiness?.percent ?? 100) < 85
          ? "amber"
          : "green",
    readinessMissing: locked ? [] : (data.readiness?.missing_items ?? []).filter(Boolean),
    emptyMessage: locked ? undefined : data.empty_message || undefined,
    wordpressUrl:
      data.wordpress_url ??
      data.ops_url ??
      data.cta?.pay_url ??
      `${wpBase}/vendor-dashboard/?view=operations`,
    paymentId,
  }
}

export async function fetchVendorPass(
  applicationId: string,
  auth?: WpAuth,
): Promise<VendorEventPass> {
  if (!isLiveApiEnabled()) {
    if (applicationId === "r1" || applicationId.includes("locked")) {
      return { ...MOCK_PASS_LOCKED, applicationId, requestId: applicationId }
    }
    return { ...MOCK_PASS_UNLOCKED, applicationId, requestId: applicationId }
  }

  if (!auth) {
    throw new Error("Sign in to view your event pass.")
  }

  const data = await wpFetchJson<WpPacketPassResponse>(`/packets/pass/${applicationId}`, {
    auth,
  })
  return mapVendorPass(applicationId, data)
}
