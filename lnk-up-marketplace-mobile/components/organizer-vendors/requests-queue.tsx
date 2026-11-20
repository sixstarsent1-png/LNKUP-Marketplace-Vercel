import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { OrganizerRequestCard } from "@/components/organizer-vendors/organizer-request-card"
import {
  REQUEST_FILTERS,
  vendorProfileHref,
  type FilterKey,
} from "@/components/organizer-vendors/vendor-queue"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { InlineBanner } from "@/components/ui/banner"
import { FilterChipRow } from "@/components/ui/chip"
import type { AppErrorShape } from "@/lib/api/errors"
import type { OrganizerReviewAction, OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { spacing } from "@/lib/theme"

type RequestsQueueProps = {
  loading: boolean
  error: AppErrorShape | null
  hasItems: boolean
  visible: OrganizerVendorRequest[]
  filter: FilterKey
  filterCounts: Record<FilterKey, number>
  eventFiltered: boolean
  actingId: string | null
  onFilterChange: (filter: FilterKey) => void
  onRetry: () => void
  onClearEventFilter: () => void
  onDiscover: () => void
  onReview: (item: OrganizerVendorRequest, action: OrganizerReviewAction) => void
  onRequestInfo?: (item: OrganizerVendorRequest) => void
}

/** Requests segment: status filters, review queue, and loading/empty/error states. */
export function RequestsQueue({
  loading,
  error,
  hasItems,
  visible,
  filter,
  filterCounts,
  eventFiltered,
  actingId,
  onFilterChange,
  onRetry,
  onClearEventFilter,
  onDiscover,
  onReview,
  onRequestInfo,
}: RequestsQueueProps) {
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

      {!loading && !(error && !hasItems) && visible.length === 0 ? (
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

      {!loading && visible.length > 0 ? (
        <View style={styles.list}>
          {visible.map((item) => (
            <OrganizerRequestCard
              key={item.id}
              item={item}
              acting={actingId === item.id}
              onReview={onReview}
              onRequestInfo={onRequestInfo}
              onOpenProfile={
                item.vendorProfileId
                  ? () => router.push(vendorProfileHref(item.vendorProfileId!, item.id) as Href)
                  : undefined
              }
            />
          ))}
        </View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.md,
  },
  list: {
    gap: spacing.md,
  },
})
