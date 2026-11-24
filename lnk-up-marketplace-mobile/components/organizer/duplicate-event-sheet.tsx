import { useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { LocationField } from "@/components/location-field"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { Meta } from "@/components/ui/typography"
import { newClientToken } from "@/lib/api/client-token"
import { appErrorFromWp } from "@/lib/api/errors"
import { isLocationSubmittable, type LocationStatus } from "@/lib/api/locations"
import { duplicateOrganizerEvent } from "@/lib/api/organizer-events"
import type { WpAuth } from "@/lib/api/wp-client"
import { showToast } from "@/lib/feedback/feedback"
import { localIsoDay } from "@/lib/format/date"
import { spacing } from "@/lib/theme"

type DuplicateEventSheetProps = {
  visible: boolean
  onClose: () => void
  eventId: string
  title: string
  place: string
  auth: WpAuth | undefined
}

/** Duplicate an event's configuration to a new date, confirming where the copy happens. */
export function DuplicateEventSheet({ visible, onClose, eventId, title, place, auth }: DuplicateEventSheetProps) {
  const [name, setName] = useState(`${title} (copy)`)
  const [date, setDate] = useState("")
  const [keepLocation, setKeepLocation] = useState(true)
  const [city, setCity] = useState("")
  const [state, setState] = useState("")
  const [locationStatus, setLocationStatus] = useState<LocationStatus>("")
  const [saving, setSaving] = useState(false)
  const tokenRef = useRef(newClientToken())

  useEffect(() => {
    if (visible) {
      setName(`${title} (copy)`)
      setDate("")
      setKeepLocation(true)
      tokenRef.current = newClientToken()
    }
  }, [visible, title])

  const dateValid = /^\d{4}-\d{2}-\d{2}$/.test(date) && date >= localIsoDay()
  const locationValid = keepLocation || (city.trim() !== "" && state.trim() !== "" && isLocationSubmittable(locationStatus))

  async function submit() {
    if (!auth || saving || !dateValid || !locationValid) return
    setSaving(true)
    try {
      const result = await duplicateOrganizerEvent(
        eventId,
        { name: name.trim() || `${title} (copy)`, date, keepLocation, city: city.trim(), state: state.trim(), clientToken: tokenRef.current },
        auth,
      )
      showToast(result.message ?? "Event copied as a draft.", "success")
      onClose()
      if (result.eventId) router.push(`/(organizer)/events/${result.eventId}` as Href)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setSaving(false)
    }
  }

  return (
    <BottomSheet
      visible={visible}
      onClose={onClose}
      title="Duplicate event"
      footer={
        <Button
          label="Create copy"
          loadingLabel="Copying…"
          loading={saving}
          disabled={!dateValid || !locationValid}
          onPress={() => void submit()}
          fullWidth
          testID="duplicate-event-submit"
        />
      }
    >
      <View style={styles.form}>
        <Meta>Booth types, prices, packet and map are copied. Applications, payments and vendors are not.</Meta>
        <FormField label="Name" value={name} onChangeText={setName} />
        <IsoDatePickerField label="New date" value={date || undefined} onChange={setDate} />
        {date && !dateValid ? <Meta>Choose today or a future date.</Meta> : null}
        <View style={styles.chips}>
          <FilterChip label={place ? `Same place · ${place}` : "Same place"} selected={keepLocation} onPress={() => setKeepLocation(true)} />
          <FilterChip label="New city" selected={!keepLocation} onPress={() => setKeepLocation(false)} />
        </View>
        {!keepLocation ? (
          <>
            <LocationField
              city={city}
              state={state}
              onChange={(next) => {
                setCity(next.city)
                setState(next.state)
              }}
              onStatusChange={setLocationStatus}
            />
            <Meta>The copy gets the market of its new city, not the original event&apos;s.</Meta>
          </>
        ) : null}
      </View>
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  form: { gap: spacing.md },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
})
