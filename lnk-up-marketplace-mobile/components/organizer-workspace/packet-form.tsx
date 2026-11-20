import { useCallback, useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchOrganizerPacketDetails,
  saveOrganizerPacketDetails,
  type OrganizerPacketDetails,
} from "@/lib/api/organizer-packet"
import type { WpAuth } from "@/lib/api/wp-client"
import { showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

type FieldKey = Exclude<keyof OrganizerPacketDetails, "eventId" | "eventTitle">

const FIELDS: Array<{ key: FieldKey; label: string; hint?: string; multiline?: boolean; placeholder?: string }> = [
  { key: "venueAddress", label: "Venue address", multiline: true, hint: "Shown on every vendor's pass once they've paid." },
  { key: "loadInTime", label: "Load-in time", placeholder: "e.g. 7:00–9:00 AM" },
  { key: "loadOutTime", label: "Load-out time", placeholder: "e.g. after 6:00 PM" },
  { key: "vendorGate", label: "Vendor gate", placeholder: "e.g. Gate C on 5th St" },
  { key: "loadInEntrance", label: "Load-in entrance" },
  { key: "parkingInfo", label: "Parking", multiline: true },
  { key: "vendorRules", label: "Vendor rules", multiline: true, hint: "Setup rules, quiet hours, tent weights…" },
]

/**
 * Arrival and rules details that appear on confirmed vendors' event passes. Saving notifies the
 * server once; changes reach vendors through their passes and the logistics notification.
 */
export function PacketForm({ eventId, auth, onSaved }: { eventId: string; auth?: WpAuth; onSaved?: () => void }) {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [saved, setSaved] = useState<OrganizerPacketDetails | null>(null)
  const [draft, setDraft] = useState<OrganizerPacketDetails | null>(null)
  const [saving, setSaving] = useState(false)
  const inFlight = useRef(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const details = await fetchOrganizerPacketDetails(eventId, auth)
      setSaved(details)
      setDraft(details)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, eventId])

  useEffect(() => {
    void load()
  }, [load])

  const dirty = Boolean(draft && saved && JSON.stringify(draft) !== JSON.stringify(saved))

  async function handleSave() {
    if (!draft || inFlight.current) return
    inFlight.current = true
    setSaving(true)
    try {
      const result = await saveOrganizerPacketDetails(draft, auth)
      setSaved(draft)
      showToast(result.message || "Pass details saved. Confirmed vendors see them on their pass.", "success")
      onSaved?.()
    } catch (err) {
      showToast(`Couldn't save pass details. ${appErrorFromWp(err).message}`, "error")
    } finally {
      inFlight.current = false
      setSaving(false)
    }
  }

  if (loading) return <LoadingPanel label="Loading pass details…" variant="skeleton" count={2} />
  if (error) return <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
  if (!draft) return null

  return (
    <View style={styles.form} testID="packet-form">
      <Meta>These details appear on confirmed vendors' event passes (and stay hidden until they've paid).</Meta>
      {FIELDS.map((field) => (
        <FormField
          key={field.key}
          label={field.label}
          value={draft[field.key]}
          onChangeText={(value) => setDraft((prev) => (prev ? { ...prev, [field.key]: value } : prev))}
          multiline={field.multiline}
          hint={field.hint}
          placeholder={field.placeholder}
        />
      ))}
      <Button
        label={dirty ? "Save pass details" : "Saved"}
        icon={dirty ? undefined : "checkmark"}
        loading={saving}
        loadingLabel="Saving…"
        disabled={!dirty}
        onPress={() => void handleSave()}
      />
    </View>
  )
}

const styles = StyleSheet.create({
  form: { gap: spacing.md },
})
