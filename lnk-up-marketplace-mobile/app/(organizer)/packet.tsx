import { useLocalSearchParams } from "expo-router"

import { PacketForm } from "@/components/organizer-workspace/packet-form"
import { EmptyPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { useAuth } from "@/lib/auth/auth-context"

/** Standalone pass-details editor (also embedded in the event workspace's Packet tab). */
export default function OrganizerPacketScreen() {
  const { eventId: eventIdParam } = useLocalSearchParams<{ eventId?: string }>()
  const eventId = Array.isArray(eventIdParam) ? eventIdParam[0] : eventIdParam
  const { auth } = useAuth()

  return (
    <Screen title="Pass details" subtitle="Arrival and rules for confirmed vendors">
      <BackLink
        label={eventId ? "Event" : "Home"}
        fallbackHref={eventId ? (`/(organizer)/events/${eventId}` as never) : "/(organizer)"}
      />
      {eventId ? (
        <PacketForm eventId={eventId} auth={auth} />
      ) : (
        <EmptyPanel
          icon="document-text-outline"
          title="Choose an event first"
          message="Open an event from Events, then use its Packet tab."
        />
      )}
    </Screen>
  )
}
