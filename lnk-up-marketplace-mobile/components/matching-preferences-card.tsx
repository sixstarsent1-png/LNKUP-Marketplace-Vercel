import { useCallback, useEffect, useState } from "react"
import { StyleSheet, Switch, View } from "react-native"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp } from "@/lib/api/errors"
import type { WpAuth } from "@/lib/api/wp-client"
import {
  fetchMatchPreferences,
  saveMatchPreferences,
  type MatchPreferences,
  type MatchPreferencesPayload,
} from "@/lib/api/match"
import { showToast } from "@/lib/feedback/feedback"
import { colors, spacing } from "@/lib/theme"

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
const ENV_OPTIONS: Array<{ value: MatchPreferences["envPref"]; label: string }> = [
  { value: "indoor", label: "Indoor" },
  { value: "outdoor", label: "Outdoor" },
  { value: "both", label: "Both" },
]

function splitList(value: string): string[] {
  return value
    .split(",")
    .map((part) => part.trim())
    .filter(Boolean)
}

/**
 * Progressive matching preferences (markets, travel, event types, months, budget, privacy).
 * Saves independently of the business profile form; every field is optional.
 */
export function MatchingPreferencesCard({ auth }: { auth?: WpAuth }) {
  const [payload, setPayload] = useState<MatchPreferencesPayload | null>(null)
  const [draft, setDraft] = useState<MatchPreferences | null>(null)
  const [citiesText, setCitiesText] = useState("")
  const [statesText, setStatesText] = useState("")
  const [radiusText, setRadiusText] = useState("")
  const [feeText, setFeeText] = useState("")
  const [loadError, setLoadError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const hydrate = useCallback((next: MatchPreferencesPayload | null) => {
    setPayload(next)
    setDraft(next ? next.preferences : null)
    setCitiesText(next ? next.preferences.citiesServed.join(", ") : "")
    setStatesText(next ? next.preferences.statesServed.join(", ") : "")
    setRadiusText(next && next.preferences.radiusMiles ? String(next.preferences.radiusMiles) : "")
    setFeeText(next && next.preferences.feeMax != null ? String(next.preferences.feeMax) : "")
  }, [])

  useEffect(() => {
    if (!auth) return
    let cancelled = false
    fetchMatchPreferences(auth)
      .then((next) => {
        if (!cancelled) hydrate(next)
      })
      .catch((err) => {
        if (!cancelled) setLoadError(appErrorFromWp(err).message)
      })
    return () => {
      cancelled = true
    }
  }, [auth, hydrate])

  if (!auth || (!payload && !loadError)) return null
  if (loadError) {
    return (
      <Surface>
        <SectionTitle>Matching preferences</SectionTitle>
        <Meta>We couldn't load your matching preferences. Pull to refresh and try again.</Meta>
      </Surface>
    )
  }
  if (!payload || !draft) return null

  function patch(next: Partial<MatchPreferences>) {
    setDraft((current) => (current ? { ...current, ...next } : current))
  }

  function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value]
  }

  async function handleSave() {
    if (!draft || !auth || saving) return
    setSaving(true)
    try {
      const radius = Number.parseInt(radiusText, 10)
      const fee = Number.parseFloat(feeText)
      const next = await saveMatchPreferences(auth, {
        ...draft,
        citiesServed: splitList(citiesText),
        statesServed: splitList(statesText).map((s) => s.toUpperCase()),
        radiusMiles: Number.isFinite(radius) && radius > 0 ? radius : 0,
        feeMax: Number.isFinite(fee) && fee >= 0 ? fee : null,
      })
      hydrate(next)
      showToast("Matching preferences saved.", "success")
    } catch (err) {
      showToast(`Couldn't save preferences. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setSaving(false)
    }
  }

  return (
    <View testID="matching-preferences">
      <Surface>
        <SectionTitle accessibilityRole="header">Matching preferences</SectionTitle>
        <Meta>
          {payload.completeness.score}% complete. Everything here is optional — it helps LNKup recommend the right events.
        </Meta>
        {payload.completeness.missing.slice(0, 3).map((item) => (
          <Meta key={item.key} style={styles.missing}>
            • {item.label}
          </Meta>
        ))}

        <FormField
          label="Cities you serve"
          value={citiesText}
          onChangeText={setCitiesText}
          placeholder="Atlanta, Charlotte, Nashville"
          hint="Separate cities with commas. No GPS needed."
        />
        <FormField
          label="States you serve"
          value={statesText}
          onChangeText={setStatesText}
          placeholder="GA, NC, TN"
          autoCapitalize="characters"
        />
        <FormField
          label="How far will you travel? (miles)"
          value={radiusText}
          onChangeText={setRadiusText}
          keyboardType="number-pad"
        />
        <View style={styles.switchRow}>
          <Meta style={styles.switchLabel}>I travel nationally for the right event</Meta>
          <Switch
            value={draft.travelNational}
            onValueChange={(v) => patch({ travelNational: v })}
            accessibilityLabel="Travel nationally"
          />
        </View>

        <Meta>Event types you like</Meta>
        <View style={styles.chips}>
          {payload.options.eventTypes.map((type) => (
            <FilterChip
              key={type.id}
              label={type.name}
              selected={draft.eventTypes.includes(type.name)}
              onPress={() => patch({ eventTypes: toggle(draft.eventTypes, type.name) })}
            />
          ))}
        </View>

        <Meta>Months you're available</Meta>
        <View style={styles.chips}>
          {MONTHS.map((label, index) => (
            <FilterChip
              key={label}
              label={label}
              selected={draft.availableMonths.includes(index + 1)}
              onPress={() => patch({ availableMonths: toggle(draft.availableMonths, index + 1) })}
            />
          ))}
        </View>

        <Meta>Indoor or outdoor</Meta>
        <View style={styles.chips}>
          {ENV_OPTIONS.map((option) => (
            <FilterChip
              key={option.value}
              label={option.label}
              selected={draft.envPref === option.value}
              onPress={() => patch({ envPref: draft.envPref === option.value ? "" : option.value })}
            />
          ))}
        </View>

        <FormField
          label="Maximum booth fee ($)"
          value={feeText}
          onChangeText={setFeeText}
          keyboardType="decimal-pad"
        />

        <View style={styles.switchRow}>
          <Meta style={styles.switchLabel}>Let organizers find my business in vendor search</Meta>
          <Switch
            value={draft.discoverable}
            onValueChange={(v) => patch({ discoverable: v })}
            accessibilityLabel="Discoverable by organizers"
            testID="matching-discoverable"
          />
        </View>
        <View style={styles.switchRow}>
          <Meta style={styles.switchLabel}>Organizers I haven't worked with can invite me</Meta>
          <Switch
            value={draft.acceptInvites}
            onValueChange={(v) => patch({ acceptInvites: v })}
            accessibilityLabel="Accept invites from new organizers"
          />
        </View>
        <Meta style={styles.privacy}>
          Organizers only see your public profile. Your email, phone, documents and payments stay private until you
          apply to their event or accept their invite.
        </Meta>

        <Button label="Save preferences" loading={saving} loadingLabel="Saving…" onPress={() => void handleSave()} />
      </Surface>
    </View>
  )
}

const styles = StyleSheet.create({
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  switchRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.md, minHeight: 44 },
  switchLabel: { flex: 1, color: colors.gray900 },
  missing: { color: colors.gray700 },
  privacy: { color: colors.gray500 },
})
