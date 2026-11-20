import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type OrganizerPacketDetails = {
  eventId: string
  eventTitle: string
  venueAddress: string
  loadInTime: string
  loadOutTime: string
  vendorGate: string
  loadInEntrance: string
  parkingInfo: string
  vendorRules: string
}

type WpPacketDetails = {
  event_id?: string | number
  event_title?: string
  venue_address?: string
  load_in_time?: string
  load_out_time?: string
  vendor_gate?: string
  loadin_entrance?: string
  parking_info?: string
  vendor_rules?: string
}

function mapDetails(data: WpPacketDetails, fallbackEventId: string): OrganizerPacketDetails {
  return {
    eventId: String(data.event_id ?? fallbackEventId),
    eventTitle: data.event_title ?? "Event",
    venueAddress: data.venue_address ?? "",
    loadInTime: data.load_in_time ?? "",
    loadOutTime: data.load_out_time ?? "",
    vendorGate: data.vendor_gate ?? "",
    loadInEntrance: data.loadin_entrance ?? "",
    parkingInfo: data.parking_info ?? "",
    vendorRules: data.vendor_rules ?? "",
  }
}

export async function fetchOrganizerPacketDetails(
  eventId: string,
  auth?: WpAuth,
): Promise<OrganizerPacketDetails> {
  if (!isLiveApiEnabled()) {
    return {
      eventId,
      eventTitle: "Sample event",
      venueAddress: "",
      loadInTime: "",
      loadOutTime: "",
      vendorGate: "",
      loadInEntrance: "",
      parkingInfo: "",
      vendorRules: "",
    }
  }
  const data = await wpFetchJson<WpPacketDetails>(`/packets/event/${eventId}/pass-details`, { auth })
  return mapDetails(data, eventId)
}

export async function saveOrganizerPacketDetails(
  details: OrganizerPacketDetails,
  auth?: WpAuth,
): Promise<{ success: boolean; message?: string }> {
  if (!isLiveApiEnabled()) {
    return { success: true, message: "Packet details saved (mock)." }
  }
  const result = await wpFetchJson<{ success?: boolean; message?: string }>(
    `/packets/event/${details.eventId}/pass-details`,
    {
      method: "POST",
      auth,
      body: {
        venue_address: details.venueAddress,
        load_in_time: details.loadInTime,
        load_out_time: details.loadOutTime,
        vendor_gate: details.vendorGate,
        loadin_entrance: details.loadInEntrance,
        parking_info: details.parkingInfo,
        vendor_rules: details.vendorRules,
      },
    },
  )
  return { success: result.success !== false, message: result.message }
}
