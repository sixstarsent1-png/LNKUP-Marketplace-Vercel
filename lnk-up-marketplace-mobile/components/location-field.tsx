import { useCallback, useEffect, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { FormField } from "@/components/ui/form-field"
import {
  isLocationSubmittable,
  resolveLocation,
  suggestLocations,
  type LocationStatus,
  type LocationSuggestion,
} from "@/lib/api/locations"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type Props = {
  city: string
  state: string
  onChange: (value: { city: string; state: string }) => void
  onStatusChange?: (status: LocationStatus) => void
  cityError?: string
  stateError?: string
  cityLabel?: string
}

/**
 * City + state entry validated by the WordPress Location Service. Suggests "Atlanta, GA" while
 * typing, and asks instead of guessing for "Atlanta, GE" or "Columbus". WordPress re-validates
 * every save, so a failed lookup never blocks the user.
 */
export function LocationField({ city, state, onChange, onStatusChange, cityError, stateError, cityLabel = "City" }: Props) {
  const [results, setResults] = useState<LocationSuggestion[]>([])
  const [message, setMessage] = useState("")
  const [suggestions, setSuggestions] = useState<LocationSuggestion[]>([])
  const [status, setStatus] = useState<LocationStatus>(city || state ? "checking" : "")
  const suggestTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const resolveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const latest = useRef({ city, state })
  latest.current = { city, state }

  const update = useCallback(
    (next: LocationStatus) => {
      setStatus(next)
      onStatusChange?.(next)
    },
    [onStatusChange],
  )

  const resolve = useCallback(
    (nextCity: string, nextState: string) => {
      if (resolveTimer.current) clearTimeout(resolveTimer.current)
      if (!nextCity.trim() && !nextState.trim()) {
        update("")
        setMessage("")
        setSuggestions([])
        return
      }
      update("checking")
      resolveTimer.current = setTimeout(async () => {
        try {
          const result = await resolveLocation(nextCity, nextState)
          if (!result) {
            update("")
            return
          }
          if (latest.current.city !== nextCity || latest.current.state !== nextState) return
          update(result.status)
          if (isLocationSubmittable(result.status)) {
            setMessage("")
            setSuggestions([])
            if (result.city && (result.city !== nextCity || result.state_code !== nextState)) {
              onChange({ city: result.city, state: result.state_code })
            }
          } else {
            setMessage(result.message ?? "Choose a city and state from the list.")
            setSuggestions(result.suggestions ?? [])
          }
        } catch {
          update("")
        }
      }, 300)
    },
    [onChange, update],
  )

  useEffect(() => {
    if (city || state) resolve(city, state)
    // Validate the stored value once when the form opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(
    () => () => {
      if (suggestTimer.current) clearTimeout(suggestTimer.current)
      if (resolveTimer.current) clearTimeout(resolveTimer.current)
    },
    [],
  )

  function handleCity(value: string) {
    onChange({ city: value, state })
    update("checking")
    if (suggestTimer.current) clearTimeout(suggestTimer.current)
    if (value.trim().length < 2) {
      setResults([])
      return
    }
    suggestTimer.current = setTimeout(async () => {
      try {
        setResults(await suggestLocations(`${value} ${state}`.trim(), 6))
      } catch {
        setResults([])
      }
    }, 250)
  }

  function choose(place: LocationSuggestion) {
    onChange({ city: place.city, state: place.state_code })
    setResults([])
    setMessage("")
    setSuggestions([])
    update("verified")
  }

  return (
    <View style={styles.wrap}>
      <View style={styles.row}>
        <View style={styles.flex}>
          <FormField
            label={cityLabel}
            value={city}
            onChangeText={handleCity}
            onBlur={() => {
              setTimeout(() => setResults([]), 150)
              resolve(latest.current.city, latest.current.state)
            }}
            placeholder="Start typing a city"
            autoCorrect={false}
            textContentType="addressCity"
            error={cityError}
          />
        </View>
        <View style={styles.stateField}>
          <FormField
            label="State"
            value={state}
            onChangeText={(next) => {
              const code = next.toUpperCase().slice(0, 2)
              onChange({ city, state: code })
              resolve(city, code)
            }}
            autoCapitalize="characters"
            maxLength={2}
            textContentType="addressState"
            error={stateError}
          />
        </View>
      </View>
      {results.length > 0 ? (
        <View style={styles.list} accessibilityRole="list" accessibilityLabel="Matching cities">
          {results.map((place) => (
            <Pressable
              key={`${place.city}-${place.state_code}`}
              onPress={() => choose(place)}
              accessibilityRole="button"
              accessibilityLabel={`Use ${place.label ?? place.display}`}
              style={({ pressed }) => [styles.option, pressed && styles.pressed]}
            >
              <Text style={styles.optionText}>{place.label ?? place.display}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
      {message && !isLocationSubmittable(status) ? (
        <View style={styles.note} accessibilityLiveRegion="polite">
          <Text style={styles.noteText}>{message}</Text>
          <View style={styles.chips}>
            {suggestions.slice(0, 4).map((place) => (
              <Pressable
                key={`${place.city}-${place.state_code}`}
                onPress={() => choose(place)}
                accessibilityRole="button"
                style={({ pressed }) => [styles.chip, pressed && styles.pressed]}
              >
                <Text style={styles.chipText}>Use {place.display}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.xs },
  row: { flexDirection: "row", gap: spacing.md },
  flex: { flex: 1 },
  stateField: { width: 96 },
  list: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  option: { paddingVertical: spacing.sm, paddingHorizontal: spacing.md, minHeight: 44, justifyContent: "center" },
  optionText: { fontFamily: fonts.body, fontSize: typeScale.body, color: colors.black },
  pressed: { opacity: 0.7 },
  note: {
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.lime,
  },
  noteText: { fontFamily: fonts.bodyMedium, fontSize: typeScale.footnote, color: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  chip: {
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.md,
    borderRadius: radii.pill,
    backgroundColor: colors.black,
    minHeight: 36,
    justifyContent: "center",
  },
  chipText: { fontFamily: fonts.bodySemi, fontSize: typeScale.footnote, color: colors.lime },
})
