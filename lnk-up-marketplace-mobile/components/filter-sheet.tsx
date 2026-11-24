import { useEffect, useState } from "react"
import { StyleSheet, TextInput, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import {
  DATE_PRESET_LABELS,
  SORT_LABELS,
  type MarketplaceDatePreset,
  type MarketplaceSort,
  type SearchParams,
} from "@/lib/api/marketplace"
import { LAUNCH_CATEGORIES } from "@/lib/api/organizer-launch"
import { colors, fonts, radii, spacing, typography, typeScale } from "@/lib/theme"

const FEE_PRESETS: Array<{ label: string; feeMin?: number; feeMax?: number }> = [
  { label: "Any" },
  { label: "Free", feeMin: 0, feeMax: 0 },
  { label: "Under $150", feeMax: 150 },
  { label: "Under $250", feeMax: 250 },
  { label: "Under $500", feeMax: 500 },
]

const SORTS: MarketplaceSort[] = ["recommended", "soonest", "closing_soon", "newest", "fee_low", "fee_high"]
const DATES = Object.keys(DATE_PRESET_LABELS) as MarketplaceDatePreset[]

type FilterSheetProps = {
  visible: boolean
  value: SearchParams
  onChange: (next: SearchParams) => void
  onClose: () => void
  onApply: () => void
  /** Events matching the draft filters, for the apply button. Omit to hide the count. */
  countFor?: (draft: SearchParams, signal: AbortSignal) => Promise<number>
}

/**
 * Filters that the marketplace search actually applies (`LNKup_Discovery`). Nothing here is
 * decorative: every option changes the server query.
 */
export function FilterSheet({ visible, value, onChange, onClose, onApply, countFor }: FilterSheetProps) {
  const [count, setCount] = useState<number | null>(null)
  const feeActive = (preset: (typeof FEE_PRESETS)[number]) => {
    if (preset.feeMax === undefined && preset.feeMin === undefined) {
      return value.feeMin === undefined && value.feeMax === undefined
    }
    return value.feeMax === preset.feeMax && value.feeMin === preset.feeMin
  }

  useEffect(() => {
    if (!visible || !countFor) return
    const controller = new AbortController()
    setCount(null)
    const timer = setTimeout(() => {
      countFor({ ...value, page: 1 }, controller.signal)
        .then((n) => {
          if (!controller.signal.aborted) setCount(n)
        })
        .catch(() => undefined)
    }, 350)
    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [visible, value, countFor])

  return (
    <BottomSheet
      visible={visible}
      title="Filters"
      onClose={onClose}
      footer={
        <>
          <Button
            label="Clear all"
            variant="secondary"
            style={styles.footerBtn}
            onPress={() =>
              onChange({
                query: value.query,
                sort: "recommended",
                page: 1,
              })
            }
          />
          <Button
            label={count == null ? "Show results" : count === 0 ? "No matching events" : `Show ${count} event${count === 1 ? "" : "s"}`}
            style={styles.footerBtn}
            onPress={() => {
              onApply()
              onClose()
            }}
          />
        </>
      }
    >
      <Text style={styles.label}>Dates</Text>
      <View style={styles.rowWrap}>
        <FilterChip label="Any time" selected={!value.datePreset} onPress={() => onChange({ ...value, datePreset: undefined })} />
        {DATES.map((preset) => (
          <FilterChip
            key={preset}
            label={DATE_PRESET_LABELS[preset]}
            selected={value.datePreset === preset}
            onPress={() => onChange({ ...value, datePreset: value.datePreset === preset ? undefined : preset })}
          />
        ))}
      </View>

      <Text style={styles.label}>Vendor category</Text>
      <View style={styles.rowWrap}>
        {LAUNCH_CATEGORIES.map((cat) => {
          const active = value.category === cat
          return <FilterChip key={cat} label={cat} selected={active} onPress={() => onChange({ ...value, category: active ? undefined : cat })} />
        })}
      </View>

      <Text style={styles.label}>Location</Text>
      <View style={styles.locationRow}>
        <TextInput
          value={value.city ?? ""}
          onChangeText={(city) => onChange({ ...value, city: city || undefined, lat: undefined, lng: undefined, radiusMiles: undefined })}
          placeholder="City"
          placeholderTextColor={colors.gray500}
          style={[styles.input, styles.cityInput]}
          accessibilityLabel="City"
        />
        <TextInput
          value={value.state ?? ""}
          onChangeText={(state) => onChange({ ...value, state: state.toUpperCase().slice(0, 2) || undefined })}
          placeholder="State"
          placeholderTextColor={colors.gray500}
          autoCapitalize="characters"
          maxLength={2}
          style={[styles.input, styles.stateInput]}
          accessibilityLabel="State"
        />
      </View>
      <Text style={styles.hint}>Leave empty to browse nationwide.</Text>

      <Text style={styles.label}>Booth fee</Text>
      <View style={styles.rowWrap}>
        {FEE_PRESETS.map((preset) => (
          <FilterChip
            key={preset.label}
            label={preset.label}
            selected={feeActive(preset)}
            onPress={() => onChange({ ...value, feeMin: preset.feeMin, feeMax: preset.feeMax })}
          />
        ))}
      </View>

      <Text style={styles.label}>Setting</Text>
      <View style={styles.rowWrap}>
        <FilterChip label="Any" selected={!value.environment} onPress={() => onChange({ ...value, environment: undefined })} />
        <FilterChip label="Indoor" selected={value.environment === "indoor"} onPress={() => onChange({ ...value, environment: "indoor" })} />
        <FilterChip label="Outdoor" selected={value.environment === "outdoor"} onPress={() => onChange({ ...value, environment: "outdoor" })} />
      </View>

      <Text style={styles.label}>More</Text>
      <View style={styles.rowWrap}>
        <FilterChip
          label="Verified organizers"
          selected={Boolean(value.verifiedOrganizerOnly)}
          onPress={() => onChange({ ...value, verifiedOrganizerOnly: !value.verifiedOrganizerOnly || undefined })}
        />
        <FilterChip
          label="Applications closing soon"
          selected={Boolean(value.closingSoon)}
          onPress={() => onChange({ ...value, closingSoon: !value.closingSoon || undefined })}
        />
      </View>

      <Text style={styles.label}>Sort by</Text>
      <View style={styles.rowWrap}>
        {SORTS.map((sort) => (
          <FilterChip
            key={sort}
            label={SORT_LABELS[sort]}
            selected={(value.sort ?? "recommended") === sort}
            onPress={() => onChange({ ...value, sort })}
          />
        ))}
      </View>
    </BottomSheet>
  )
}

const styles = StyleSheet.create({
  label: {
    ...typography.eyebrow,
    marginTop: spacing.sm,
  },
  hint: { fontFamily: fonts.body, fontSize: typeScale.caption, color: colors.gray500 },
  locationRow: { flexDirection: "row", gap: spacing.sm },
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: typeScale.callout,
    fontFamily: fonts.body,
    color: colors.black,
    backgroundColor: colors.gray50,
  },
  cityInput: { flex: 1 },
  stateInput: { width: 84 },
  rowWrap: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  footerBtn: {
    flex: 1,
  },
})
