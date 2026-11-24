import { useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"

import { REQUEST_FILTERS, type FilterKey } from "@/components/organizer-vendors/vendor-queue"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { FilterChipRow } from "@/components/ui/chip"
import type { AppErrorShape } from "@/lib/api/errors"
import { colors, layout, radii, spacing, typography } from "@/lib/theme"

/** Statuses an organizer works through daily; the rest live in the filter sheet. */
const PRIMARY_FILTERS: readonly FilterKey[] = ["review", "needs_info", "awaiting_payment", "confirmed"]

type RequestsQueueHeaderProps = {
  loading: boolean
  error: AppErrorShape | null
  hasItems: boolean
  visibleCount: number
  filter: FilterKey
  filterCounts: Record<FilterKey, number>
  eventFiltered: boolean
  onFilterChange: (filter: FilterKey) => void
  onRetry: () => void
  onClearEventFilter: () => void
  onDiscover: () => void
}

/** Requests segment above the rows: status filters and loading/empty/error states. Rows render in the screen list. */
export function RequestsQueueHeader({
  loading,
  error,
  hasItems,
  visibleCount,
  filter,
  filterCounts,
  eventFiltered,
  onFilterChange,
  onRetry,
  onClearEventFilter,
  onDiscover,
}: RequestsQueueHeaderProps) {
  const [sheetOpen, setSheetOpen] = useState(false)
  const shown = PRIMARY_FILTERS.includes(filter) ? PRIMARY_FILTERS : [...PRIMARY_FILTERS, filter]
  const options = REQUEST_FILTERS.filter((item) => shown.includes(item.key)).map((item) => ({
    value: item.key,
    label: item.label,
    count: filterCounts[item.key],
  }))

  return (
    <View style={styles.stack}>
      <View style={styles.filters}>
        <View style={styles.chips}>
          <FilterChipRow options={options} value={filter} onChange={onFilterChange} />
        </View>
        <Pressable
          onPress={() => setSheetOpen(true)}
          accessibilityRole="button"
          accessibilityLabel="More status filters"
          style={styles.more}
          testID="requests-more-filters"
        >
          <AppIcon name="options-outline" size={18} color={colors.black} />
        </Pressable>
      </View>

      {!loading && error && hasItems ? (
        <InlineBanner tone="error" message={`Couldn’t refresh requests. ${error.message}`} actionLabel="Retry" onAction={onRetry} />
      ) : null}

      {loading && !hasItems ? <LoadingPanel label="Loading requests…" variant="skeleton" /> : null}

      {!loading && error && !hasItems ? <ErrorPanel title={error.title} message={error.message} onAction={onRetry} /> : null}

      {!loading && !(error && !hasItems) && visibleCount === 0 ? (
        <EmptyPanel
          icon="file-tray-outline"
          title={eventFiltered ? "No requests for this event" : "No requests here"}
          message={
            eventFiltered
              ? "Try clearing the event filter or check another status."
              : "Incoming vendor applications will show up in this queue."
          }
          actionLabel={eventFiltered ? "Clear filter" : "Discover vendors"}
          onAction={eventFiltered ? onClearEventFilter : onDiscover}
          secondaryActionLabel={filter !== "all" ? "Show all statuses" : undefined}
          onSecondaryAction={filter !== "all" ? () => onFilterChange("all") : undefined}
        />
      ) : null}

      <BottomSheet visible={sheetOpen} onClose={() => setSheetOpen(false)} title="Filter requests">
        <View style={styles.sheetList}>
          {REQUEST_FILTERS.map((item) => (
            <Pressable
              key={item.key}
              onPress={() => {
                onFilterChange(item.key)
                setSheetOpen(false)
              }}
              accessibilityRole="radio"
              accessibilityState={{ checked: filter === item.key }}
              aria-checked={filter === item.key}
              style={[styles.sheetRow, filter === item.key && styles.sheetRowActive]}
            >
              <Text style={styles.sheetLabel}>{item.label}</Text>
              <Text style={styles.sheetCount}>{filterCounts[item.key]}</Text>
            </Pressable>
          ))}
        </View>
      </BottomSheet>
    </View>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.md,
  },
  filters: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  chips: {
    flex: 1,
  },
  more: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    borderRadius: radii.pill,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  sheetList: {
    gap: spacing.xs,
  },
  sheetRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    minHeight: layout.hitTargetMin + 4,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.gray50,
  },
  sheetRowActive: {
    backgroundColor: colors.limeMuted,
  },
  sheetLabel: {
    ...typography.label,
  },
  sheetCount: {
    ...typography.meta,
  },
})
