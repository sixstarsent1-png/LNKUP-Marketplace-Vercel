import { Pressable, ScrollView, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, fonts, layout, radii, shadow, spacing } from "@/lib/theme"

type FilterChipProps = {
  label: string
  selected?: boolean
  count?: number
  onPress: () => void
  accessibilityLabel?: string
}

/** Toggleable filter chip. Selected chips use a black fill so the chip language is consistent app-wide. */
export function FilterChip({ label, selected = false, count, onPress, accessibilityLabel }: FilterChipProps) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? (count != null ? `${label}, ${count}` : label)}
      accessibilityState={{ selected }}
      aria-selected={selected}
      hitSlop={{ top: 6, bottom: 6 }}
      style={({ pressed }) => [
        styles.chip,
        selected && styles.chipSelected,
        pressed && styles.pressed,
      ]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
      {count != null && count > 0 ? (
        <View style={[styles.count, selected && styles.countSelected]}>
          <Text style={[styles.countText, selected && styles.countTextSelected]}>{count}</Text>
        </View>
      ) : null}
    </Pressable>
  )
}

export type ChipOption<T extends string> = {
  value: T
  label: string
  count?: number
}

/** Horizontally scrollable single-select row of FilterChips. */
export function FilterChipRow<T extends string>({
  options,
  value,
  onChange,
}: {
  options: ReadonlyArray<ChipOption<T>>
  value: T
  onChange: (value: T) => void
}) {
  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.row}
      keyboardShouldPersistTaps="handled"
    >
      {options.map((option) => (
        <FilterChip
          key={option.value}
          label={option.label}
          count={option.count}
          selected={option.value === value}
          onPress={() => onChange(option.value)}
        />
      ))}
    </ScrollView>
  )
}

/** Equal-width segmented control for switching between sibling views. */
export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
}: {
  options: ReadonlyArray<ChipOption<T>>
  value: T
  onChange: (value: T) => void
}) {
  return (
    <View style={styles.segment} accessibilityRole="tablist">
      {options.map((option) => {
        const selected = option.value === value
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
      aria-selected={selected}
            accessibilityLabel={option.count != null ? `${option.label}, ${option.count}` : option.label}
            style={({ pressed }) => [
              styles.segmentItem,
              selected && styles.segmentItemSelected,
              pressed && !selected && styles.pressed,
            ]}
          >
            <Text
              style={[styles.segmentText, selected && styles.segmentTextSelected]}
              numberOfLines={1}
            >
              {option.label}
              {option.count != null && option.count > 0 ? ` · ${option.count}` : ""}
            </Text>
          </Pressable>
        )
      })}
    </View>
  )
}

const styles = StyleSheet.create({
  row: {
    gap: spacing.sm,
    paddingVertical: 2,
  },
  chip: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs + 2,
    minHeight: 36,
    paddingHorizontal: spacing.md + 2,
    borderRadius: radii.pill,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
  },
  chipSelected: {
    backgroundColor: colors.black,
    borderColor: colors.black,
  },
  chipText: {
    fontFamily: fonts.bodySemi,
    fontSize: 13,
    color: colors.gray700,
  },
  chipTextSelected: {
    color: colors.white,
  },
  count: {
    minWidth: 20,
    height: 20,
    paddingHorizontal: 6,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
    alignItems: "center",
    justifyContent: "center",
  },
  countSelected: {
    backgroundColor: colors.lime,
  },
  countText: {
    fontFamily: fonts.bodyBold,
    fontSize: 11,
    color: colors.gray700,
  },
  countTextSelected: {
    color: colors.black,
  },
  segment: {
    flexDirection: "row",
    backgroundColor: colors.gray100,
    borderRadius: radii.md,
    padding: 4,
    gap: 4,
  },
  segmentItem: {
    flex: 1,
    minHeight: layout.hitTargetMin,
    alignItems: "center",
    justifyContent: "center",
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
  },
  segmentItemSelected: {
    backgroundColor: colors.white,
    ...shadow("sm"),
  },
  segmentText: {
    fontFamily: fonts.bodySemi,
    fontSize: 13,
    color: colors.gray500,
  },
  segmentTextSelected: {
    color: colors.black,
  },
  pressed: {
    opacity: 0.75,
  },
})
