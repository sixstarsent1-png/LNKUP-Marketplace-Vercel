import { getPublicWpSiteUrl } from "@/lib/api/config"

export type VendorPacketPreview = {
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
  organizerName?: string
  organizerEmail?: string
  organizerPhone?: string
  loadInTime?: string
  loadOutTime?: string
  parkingInstructions?: string
  boothAssignment?: string
  powerDetails?: string
  waterDetails?: string
  weatherNote?: string
  rainPlan?: string
  heatPlan?: string
  weatherRecommendations?: string[]
  forecastStatus?: string
  contingency?: string
  weatherRisks?: string[]
  dayOfContact?: string
  emergencyContact?: string
  vendorRules?: string[]
  setupRestrictions?: string[]
  refundPolicy?: string
  weatherPolicy?: string
  checkInCode?: string
  qrCodeUrl?: string
  unlocks?: string[]
  wordpressUrl?: string
}

export type VendorEventPass = VendorPacketPreview

export type WpPacketPassResponse = {
  application_id?: number
  status?: string
  locked?: boolean
  headline?: string
  message?: string
  event?: {
    name?: string
    date?: string
    start_time?: string
    end_time?: string
    city?: string
    state?: string
    venue_name?: string
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
  weather?: {
    summary?: string
    rain_plan?: string
    heat_plan?: string
    recommendations?: string[] | string
    forecast_status?: string
    contingency?: string
    risks?: unknown
  }
  contacts?: {
    day_of?: {
      name?: string
      phone?: string
      email?: string
    }
    emergency?: {
      name?: string
      phone?: string
    }
  }
  rules?: {
    vendor_rules?: string[]
    setup_restrictions?: string[]
  }
  policies?: {
    refund?: string
    weather?: string
  }
  qr_pass?: {
    code?: string
    url?: string
    image_url?: string
  }
  check_in_code?: string
  unlocks?: string[]
  refund_policy?: string
  weather_policy?: string
  ops_url?: string
  wordpress_url?: string
}

function splitRules(rules?: WpPacketPassResponse["rules"]): {
  vendorRules: string[]
  setupRestrictions: string[]
} {
  const vendorRules = rules?.vendor_rules ?? []
  const setupRestrictions = rules?.setup_restrictions ?? []
  return {
    vendorRules: Array.isArray(vendorRules) ? vendorRules.filter(Boolean) : [],
    setupRestrictions: Array.isArray(setupRestrictions) ? setupRestrictions.filter(Boolean) : [],
  }
}

function formatEmergencyContact(
  contact?: { name?: string; phone?: string },
): string | undefined {
  if (!contact) return undefined
  const parts = [contact.name, contact.phone].filter(Boolean)
  return parts.length ? parts.join(" · ") : undefined
}

function asRecommendationLines(
  value?: string[] | string | null,
): string[] {
  if (!value) return []
  if (Array.isArray(value)) return value.map(String).map((s) => s.trim()).filter(Boolean)
  return String(value)
    .split(/\r?\n|;/)
    .map((s) => s.trim())
    .filter(Boolean)
}

function asRiskLines(value: unknown): string[] {
  if (!value) return []
  if (Array.isArray(value)) {
    return value
      .map((item) => {
        if (typeof item === "string") return item.trim()
        if (item && typeof item === "object") {
          const row = item as { label?: string; message?: string; level?: string }
          const parts = [row.label, row.message, row.level].filter(Boolean)
          return parts.join(" · ")
        }
        return ""
      })
      .filter(Boolean)
  }
  if (typeof value === "string") {
    return value
      .split(/\r?\n|;/)
      .map((s) => s.trim())
      .filter(Boolean)
  }
  return []
}

export function mapVendorPacket(
  requestId: string,
  data: WpPacketPassResponse,
): VendorPacketPreview {
  const locked = data.locked ?? data.status === "locked"
  const { vendorRules, setupRestrictions } = splitRules(data.rules)
  const wpBase = getPublicWpSiteUrl()

  const rainPlan = locked ? undefined : data.weather?.rain_plan || undefined
  const heatPlan = locked ? undefined : data.weather?.heat_plan || undefined
  const weatherRecommendations = locked
    ? []
    : asRecommendationLines(data.weather?.recommendations)
  const forecastStatus = locked ? undefined : data.weather?.forecast_status || undefined
  const contingency = locked
    ? undefined
    : data.weather?.contingency || undefined
  const weatherRisks = locked ? [] : asRiskLines(data.weather?.risks)
  const weatherNote = locked
    ? undefined
    : data.weather?.summary || data.logistics?.weather || undefined
  const weatherPolicyJoined = [rainPlan, heatPlan].filter(Boolean).join(" · ")
  const weatherPolicy = locked
    ? undefined
    : data.policies?.weather ?? data.weather_policy ?? (weatherPolicyJoined || undefined)

  return {
    requestId,
    applicationId: String(data.application_id ?? requestId),
    status: locked ? "locked" : "unlocked",
    headline: data.headline ?? (locked ? "Your vendor pass unlocks after payment" : "You're booked — your event pass is ready"),
    message:
      data.message ??
      (locked
        ? "Complete payment to unlock your full event pass with logistics, check-in, and day-of details."
        : "Your event pass is ready. Review logistics and check-in details below."),
    eventName: data.event?.name ?? "Event",
    eventDate: data.event?.date,
    startTime: data.event?.start_time,
    endTime: data.event?.end_time,
    city: data.event?.city,
    state: data.event?.state,
    venueName: data.event?.venue_name,
    venueAddress: locked ? undefined : data.event?.venue_address,
    mapUrl: locked ? undefined : data.event?.map_url,
    organizerName: data.organizer?.name,
    organizerEmail: locked ? undefined : data.organizer?.email,
    organizerPhone: locked ? undefined : data.organizer?.phone,
    loadInTime: locked ? undefined : data.logistics?.load_in,
    loadOutTime: locked ? undefined : data.logistics?.load_out,
    parkingInstructions: locked ? undefined : data.logistics?.parking,
    boothAssignment: locked ? undefined : data.logistics?.booth,
    powerDetails: locked ? undefined : data.logistics?.power,
    waterDetails: locked ? undefined : data.logistics?.water,
    weatherNote,
    rainPlan,
    heatPlan,
    weatherRecommendations,
    forecastStatus,
    contingency,
    weatherRisks,
    dayOfContact: locked
      ? undefined
      : data.contacts?.day_of
        ? [data.contacts.day_of.name, data.contacts.day_of.phone].filter(Boolean).join(" · ")
        : undefined,
    emergencyContact: locked ? undefined : formatEmergencyContact(data.contacts?.emergency),
    vendorRules: locked ? [] : vendorRules,
    setupRestrictions: locked ? [] : setupRestrictions,
    refundPolicy: locked ? undefined : (data.policies?.refund ?? data.refund_policy),
    weatherPolicy,
    checkInCode: locked ? undefined : (data.qr_pass?.code ?? data.check_in_code),
    qrCodeUrl: locked ? undefined : data.qr_pass?.image_url ?? data.qr_pass?.url,
    unlocks: data.unlocks ?? [
      "Full venue address",
      "Load-in and parking details",
      "Booth assignment",
      "Check-in QR code",
      "Day-of contacts",
    ],
    wordpressUrl: data.wordpress_url ?? data.ops_url ?? (wpBase ? `${wpBase}/vendor-dashboard/?view=operations` : undefined),
  }
}

export const mockVendorPacketLocked: VendorPacketPreview = {
  requestId: "req-2",
  applicationId: "req-2",
  status: "locked",
  headline: "Your vendor pass unlocks after payment",
  message: "Complete payment to unlock your full event pass with logistics, check-in, and day-of details.",
  eventName: "Atlanta Food Fest",
  eventDate: "Jul 15, 2026",
  city: "Atlanta",
  state: "GA",
  organizerName: "Southern Eats Co.",
  unlocks: [
    "Full venue address",
    "Load-in and parking details",
    "Booth assignment",
    "Check-in QR code",
    "Day-of contacts",
  ],
}

export const mockVendorPacketUnlocked: VendorPacketPreview = {
  requestId: "req-1",
  applicationId: "req-1",
  status: "unlocked",
  headline: "You're booked — your event pass is ready",
  message: "Your event pass is ready. Review logistics and check-in details below.",
  eventName: "Charlotte Night Market",
  eventDate: "Jul 1, 2026",
  startTime: "5:00 PM",
  endTime: "11:00 PM",
  city: "Charlotte",
  state: "NC",
  venueName: "First Ward Park",
  venueAddress: "301 E 7th St, Charlotte, NC 28202",
  mapUrl: "https://maps.example.com/charlotte-night-market",
  organizerName: "Queen City Events",
  organizerEmail: "ops@queencityevents.com",
  organizerPhone: "(704) 555-0199",
  loadInTime: "2:00 PM – 4:00 PM",
  loadOutTime: "11:30 PM – 12:30 AM",
  parkingInstructions: "Vendor lot B — enter from 7th St. Display your pass on the dash.",
  boothAssignment: "Booth A-12",
  powerDetails: "20A dedicated circuit at booth",
  waterDetails: "Shared wash station near Lot B",
  weatherNote: "Outdoor event — bring tie-downs. Rain plan: delay up to 1 hour.",
  rainPlan: "Delay up to 1 hour for light rain; cancel only for severe weather.",
  heatPlan: "Shade required for staff; water station open all day.",
  weatherRecommendations: ["Bring canopy weights", "Pack cold water for staff"],
  forecastStatus: "Live forecast",
  contingency: "If severe weather cancels the day, vendors receive a makeup date or credit.",
  weatherRisks: ["Heat advisory possible afternoon"],
  dayOfContact: "Alex Rivera · (704) 555-0142",
  emergencyContact: "Event Security · (704) 555-0911",
  vendorRules: [
    "No open flames without permit",
    "Music off by 10:45 PM",
    "Break down only after load-out window",
  ],
  setupRestrictions: [
    "No stakes in turf areas",
    "Max canopy height 10 ft",
  ],
  refundPolicy: "Booth fees are non-refundable within 7 days of the event.",
  weatherPolicy: "Event may delay up to 1 hour for weather. No refunds for vendor no-shows.",
  checkInCode: "CHK-8842",
  unlocks: [],
  wordpressUrl: getPublicWpSiteUrl()
    ? `${getPublicWpSiteUrl()}/vendor-dashboard/?view=operations`
    : undefined,
}
