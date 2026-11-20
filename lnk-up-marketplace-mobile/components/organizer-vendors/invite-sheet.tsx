import { useEffect, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOrganizerEvents, type OrganizerEventLite } from "@/lib/api/organizer-events"
import { inviteVendorFromDirectory, type OrganizerDirectoryVendor } from "@/lib/api/organizer-vendor-directory"
import type { WpAuth } from "@/lib/api/wp-client"
import { showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

type InviteSheetProps = {
  vendor: OrganizerDirectoryVendor | null
  auth?: WpAuth
  /** Preselect when opened from an event-filtered view. */
  defaultEventId?: string | null
  onClose: () => void
  onSent?: (vendor: OrganizerDirectoryVendor) => void
}

/** Invite a vendor to a specific upcoming event (and vendor slot) with an optional note. */
export function InviteSheet({ vendor, auth, defaultEventId, onClose, onSent }: InviteSheetProps) {
  const [events, setEvents] = useState<OrganizerEventLite[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [eventId, setEventId] = useState<string | null>(null)
  const [opportunityId, setOpportunityId] = useState<string | null>(null)
  const [message, setMessage] = useState("")
  const [sending, setSending] = useState(false)
  const inFlight = useRef(false)

  useEffect(() => {
    if (!vendor) return
    let active = true
    setLoading(true)
    setError(null)
    setMessage("")
    setOpportunityId(null)
    fetchOrganizerEvents(auth)
      .then(({ items }) => {
        if (!active) return
        const upcoming = items.filter((event) => event.status !== "past")
        setEvents(upcoming)
        const preset = upcoming.find((event) => event.id === defaultEventId) ?? (upcoming.length === 1 ? upcoming[0] : null)
        setEventId(preset?.id ?? null)
      })
      .catch((err: unknown) => {
        if (active) setError(appErrorFromWp(err))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [vendor, auth, defaultEventId])

  const selectedEvent = useMemo(() => events.find((event) => event.id === eventId) ?? null, [events, eventId])
  const slots = selectedEvent?.opportunities ?? []

  useEffect(() => {
    setOpportunityId(slots.length === 1 ? slots[0].id : null)
  }, [eventId, slots.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const needsSlot = slots.length > 1 && !opportunityId
  const canSend = Boolean(vendor && eventId) && !needsSlot

  async function handleSend() {
    if (!vendor || !eventId || inFlight.current) return
    inFlight.current = true
    setSending(true)
    try {
      const result = await inviteVendorFromDirectory(
        {
          vendorUserId: vendor.vendorUserId ?? "",
          eventId,
          opportunityId: opportunityId ?? undefined,
          message: message.trim() || `You're invited to apply to ${selectedEvent?.title ?? "my event"} on LNKup.`,
        },
        auth,
      )
      if (result.status === "already_invited") {
        showToast(`${vendor.name} already has an open invite for this event.`, "info")
      } else {
        showToast(`Invite sent to ${vendor.name}.`, "success")
        onSent?.(vendor)
      }
      onClose()
    } catch (err) {
      showToast(`Couldn't send the invite. ${appErrorFromWp(err).message}`, "error")
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  return (
    <BottomSheet
      visible={Boolean(vendor)}
      title={vendor ? `Invite ${vendor.name}` : "Invite vendor"}
      onClose={onClose}
      footer={
        <Button
          label="Send invite"
          loading={sending}
          loadingLabel="Sending…"
          disabled={!canSend}
          fullWidth
          onPress={() => void handleSend()}
        />
      }
    >
      <View style={styles.body}>
        {loading ? <LoadingPanel label="Loading your events…" /> : null}
        {error ? <ErrorPanel title={error.title} message={error.message} /> : null}
        {!loading && !error && events.length === 0 ? (
          <EmptyPanel
            icon="calendar-outline"
            title="No upcoming events"
            message="Publish an event first, then invite vendors to apply."
          />
        ) : null}

        {events.length > 0 ? (
          <>
            <SectionTitle accessibilityRole="header" style={styles.heading}>
              Event
            </SectionTitle>
            <View style={styles.chips}>
              {events.map((event) => (
                <FilterChip
                  key={event.id}
                  label={[event.title, event.date].filter(Boolean).join(" · ")}
                  selected={event.id === eventId}
                  onPress={() => setEventId(event.id)}
                />
              ))}
            </View>
          </>
        ) : null}

        {selectedEvent && slots.length > 1 ? (
          <>
            <SectionTitle accessibilityRole="header" style={styles.heading}>
              Vendor slot
            </SectionTitle>
            <View style={styles.chips}>
              {slots.map((slot) => (
                <FilterChip
                  key={slot.id}
                  label={slot.category || slot.label || "Vendor slot"}
                  selected={slot.id === opportunityId}
                  onPress={() => setOpportunityId(slot.id)}
                />
              ))}
            </View>
            {needsSlot ? <Meta>Choose which slot this vendor should apply to.</Meta> : null}
          </>
        ) : null}

        {selectedEvent ? (
          <FormField
            label="Message (optional)"
            value={message}
            onChangeText={setMessage}
            placeholder="Why they'd be a great fit"
            multiline
            maxLength={1000}
          />
        ) : null}
      </View>
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  body: { gap: spacing.sm },
  heading: { fontSize: 15 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
})
