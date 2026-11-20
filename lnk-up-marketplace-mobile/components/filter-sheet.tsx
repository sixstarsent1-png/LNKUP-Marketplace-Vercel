import { StyleSheet, TextInput, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import type { MarketplaceSort, SearchParams } from "@/lib/api/marketplace"
import { colors, fonts, radii, spacing, typography } from "@/lib/theme"

const CATEGORIES = ["Food Vendor", "Retail Vendor", "Food Truck", "Beauty Vendor"] as const

const FEE_PRESETS: Array<{ label: string; feeMin?: number; feeMax?: number }> = [
  { label: "Any" },
  { label: "Free", feeMin: 0, feeMax: 0 },
  { label: "Under $150", feeMax: 150 },
  { label: "Under $250", feeMax: 250 },
]

const SORTS: Array<{ id: MarketplaceSort; label: string }> = [
  { id: "recommended", label: "Recommended" },
  { id: "newest", label: "Newest" },
  { id: "closing_soon", label: "Closing soon" },
  { id: "fee_low", label: "Fee: low to high" },
]

type FilterSheetProps = {
  visible: boolean
  value: SearchParams
  onChange: (next: SearchParams) => void
  onClose: () => void
  onApply: () => void
}

export function FilterSheet({ visible, value, onChange, onClose, onApply }: FilterSheetProps) {
  const feeActive = (preset: (typeof FEE_PRESETS)[number]) => {
    if (preset.feeMax === undefined && preset.feeMin === undefined) {
      return value.feeMin === undefined && value.feeMax === undefined
    }
    return value.feeMax === preset.feeMax && value.feeMin === preset.feeMin
  }

  return (
    <BottomSheet
      visible={visible}
      title="Filters"
      onClose={onClose}
      footer={
        <>
          <Button
            label="Reset"
            variant="secondary"
            style={styles.footerBtn}
            onPress={() =>
              onChange({
                ...value,
                city: undefined,
                state: undefined,
                category: undefined,
                feeMin: undefined,
                feeMax: undefined,
                verifiedOrganizerOnly: undefined,
                sort: "recommended",
              })
            }
          />
          <Button
            label="Show results"
            style={styles.footerBtn}
            onPress={() => {
              onApply()
              onClose()
            }}
          />
        </>
      }
    >
      <Text style={styles.label}>City</Text>
      <TextInput
        value={value.city ?? ""}
        onChangeText={(city) => onChange({ ...value, city })}
        placeholder="e.g. Charlotte"
        placeholderTextColor={colors.gray500}
        style={styles.input}
        accessibilityLabel="City"
      />

      <Text style={styles.label}>State</Text>
      <TextInput
        value={value.state ?? ""}
        onChangeText={(state) => onChange({ ...value, state: state.toUpperCase().slice(0, 2) })}
        placeholder="e.g. NC"
        placeholderTextColor={colors.gray500}
        autoCapitalize="characters"
        maxLength={2}
        style={styles.input}
        accessibilityLabel="State"
      />

      <Text style={styles.label}>Category</Text>
      <View style={styles.rowWrap}>
        {CATEGORIES.map((cat) => {
          const active = value.category === cat
          return (
            <FilterChip
              key={cat}
              label={cat}
              selected={active}
              onPress={() => onChange({ ...value, category: active ? undefined : cat })}
            />
          )
        })}
      </View>

      <Text style={styles.label}>Fee</Text>
      <View style={styles.rowWrap}>
        {FEE_PRESETS.map((preset) => (
          <FilterChip
            key={preset.label}
            label={preset.label}
            selected={feeActive(preset)}
            onPress={() =>
              onChange({
                ...value,
                feeMin: preset.feeMin,
                feeMax: preset.feeMax,
              })
            }
          />
        ))}
      </View>

      <Text style={styles.label}>Organizer</Text>
      <View style={styles.rowWrap}>
        <FilterChip
          label="Verified only"
          selected={Boolean(value.verifiedOrganizerOnly)}
          onPress={() =>
            onChange({
              ...value,
              verifiedOrganizerOnly: !value.verifiedOrganizerOnly,
            })
          }
        />
      </View>

      <Text style={styles.label}>Sort</Text>
      <View style={styles.rowWrap}>
        {SORTS.map((sort) => (
          <FilterChip
            key={sort.id}
            label={sort.label}
            selected={(value.sort ?? "recommended") === sort.id}
            onPress={() => onChange({ ...value, sort: sort.id })}
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
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    fontSize: 15,
    fontFamily: fonts.body,
    color: colors.black,
    backgroundColor: colors.gray50,
  },
  rowWrap: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  footerBtn: {
    flex: 1,
  },
})
