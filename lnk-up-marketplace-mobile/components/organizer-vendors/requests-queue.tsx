import { StyleSheet, View } from "react-native"

import { REQUEST_FILTERS, type FilterKey } from "@/components/organizer-vendors/vendor-queue"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { InlineBanner } from "@/components/ui/banner"
import { FilterChipRow } from "@/components/ui/chip"
import type { AppErrorShape } from "@/lib/api/errors"
import { spacing } from "@/lib/theme"

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
  const options = REQUEST_FILTERS.map((item) => ({
    value: item.key,
    label: item.label,
    count: filterCounts[item.key],
  }))

  return (
    <View style={styles.stack}>
      <FilterChipRow options={options} value={filter} onChange={onFilterChange} />

      {!loading && error && hasItems ? (
        <InlineBanner
          tone="error"
          message={`Couldn’t refresh requests. ${error.message}`}
          actionLabel="Retry"
          onAction={onRetry}
        />
      ) : null}

      {loading && !hasItems ? <LoadingPanel label="Loading requests…" variant="skeleton" /> : null}

      {!loading && error && !hasItems ? (
        <ErrorPanel title={error.title} message={error.message} onAction={onRetry} />
      ) : null}

      {!loading && !(error && !hasItems) && visibleCount === 0 ? (
        <EmptyPanel
          icon="file-tray-outline"
          title={eventFiltered ? "No requests for this event" : "No requests here"}
          message={
            eventFiltered
              ? "Try clearing the event filter or check another status chip."
              : "Incoming vendor applications will show up in this queue."
          }
          actionLabel={eventFiltered ? "Clear filter" : "Discover vendors"}
          onAction={eventFiltered ? onClearEventFilter : onDiscover}
          secondaryActionLabel={filter !== "all" ? "Show all statuses" : undefined}
          onSecondaryAction={filter !== "all" ? () => onFilterChange("all") : undefined}
        />
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.md,
  },
})
