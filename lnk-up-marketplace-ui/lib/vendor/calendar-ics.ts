import type { VendorPacketPreview } from "@/lib/api/vendor-packet"

function escapeIcs(text: string): string {
  return text.replace(/\\/g, "\\\\").replace(/;/g, "\\;").replace(/,/g, "\\,").replace(/\n/g, "\\n")
}

function formatIcsDate(date: Date): string {
  return date.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "")
}

export function buildCalendarIcs(packet: VendorPacketPreview): string | null {
  if (packet.status !== "unlocked") return null
  if (!packet.eventDate) return null

  const start = new Date(packet.eventDate)
  if (Number.isNaN(start.getTime())) return null

  const end = new Date(start.getTime() + 4 * 60 * 60 * 1000)
  const location = packet.venueAddress
    ? escapeIcs(packet.venueAddress)
    : [packet.city, packet.state].filter(Boolean).join(", ")

  const descriptionParts = ["Vendor pass/details in LNKup"]
  if (packet.loadInTime) descriptionParts.push(`Load-in: ${packet.loadInTime}`)
  const description = escapeIcs(descriptionParts.join("\\n"))

  return [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//LNKup//Vendor Event Pass//EN",
    "BEGIN:VEVENT",
    `UID:lnkup-pass-${packet.requestId}@lnkup.com`,
    `DTSTAMP:${formatIcsDate(new Date())}`,
    `DTSTART:${formatIcsDate(start)}`,
    `DTEND:${formatIcsDate(end)}`,
    `SUMMARY:${escapeIcs(packet.eventName)}`,
    location ? `LOCATION:${location}` : "",
    `DESCRIPTION:${description}`,
    "END:VEVENT",
    "END:VCALENDAR",
  ]
    .filter(Boolean)
    .join("\r\n")
}

export function downloadCalendarIcs(packet: VendorPacketPreview, filename?: string): boolean {
  const ics = buildCalendarIcs(packet)
  if (!ics) return false
  const blob = new Blob([ics], { type: "text/calendar;charset=utf-8" })
  const url = URL.createObjectURL(blob)
  const link = document.createElement("a")
  link.href = url
  link.download = filename ?? `lnkup-event-${packet.requestId}.ics`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  return true
}
