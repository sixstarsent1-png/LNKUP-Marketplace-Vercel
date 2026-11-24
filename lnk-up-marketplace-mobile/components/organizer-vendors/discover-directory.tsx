import { memo, useState } from "react"
import { Pressable, StyleSheet, TextInput, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import {
  DISCOVER_SORTS,
  EMPTY_DISCOVER_FILTERS,
  vendorProfileHref,
} from "@/components/organizer-vendors/vendor-queue"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ProfileMark } from "@/components/profile-mark"
import { AppIcon } from "@/components/ui/app-icon"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { StatusBadge } from "@/components/ui/status-badge"
import type { AppErrorShape } from "@/lib/api/errors"
import { LAUNCH_CATEGORIES } from "@/lib/api/organizer-launch"
import type { OrganizerDirectoryVendor, OrganizerDiscoverFilters } from "@/lib/api/organizer-vendor-directory"
import { colors, fonts, layout, radii, spacing, typography, typeScale } from "@/lib/theme"

type DiscoverHeaderProps = {
  query: string
  onQueryChange: (query: string) => void
  filters: OrganizerDiscoverFilters
  onFiltersChange: (filters: OrganizerDiscoverFilters) => void
  activeFilterCount: number
  loading: boolean
  error: AppErrorShape | null
  count: number
  total: number
  onRetry: () => void
}

type AppliedChip = { key: keyof OrganizerDiscoverFilters; label: string }

function appliedChips(filters: OrganizerDiscoverFilters): AppliedChip[] {
  const chips: AppliedChip[] = []
  if (filters.city?.trim()) chips.push({ key: "city", label: filters.city.trim() })
  if (filters.category?.trim()) chips.push({ key: "category", label: filters.category.trim() })
  if (filters.verified) chips.push({ key: "verified", label: "Verified only" })
  if (filters.sort && filters.sort !== "score") {
    const sort = DISCOVER_SORTS.find((item) => item.id === filters.sort)
    if (sort) chips.push({ key: "sort", label: sort.label })
  }
  return chips
}

/** Discover segment above the rows: search, filter sheet, applied filters, and loading/empty/error states. */
export function DiscoverHeader({
  query,
  onQueryChange,
  filters,
  onFiltersChange,
  activeFilterCount,
  loading,
  error,
  count,
  total,
  onRetry,
}: DiscoverHeaderProps) {
  const [sheetOpen, setSheetOpen] = useState(false)
  const [draft, setDraft] = useState<OrganizerDiscoverFilters>(EMPTY_DISCOVER_FILTERS)
  const hasSearch = query.trim().length > 0 || activeFilterCount > 0
  const chips = appliedChips(filters)

  function clearAll() {
    onQueryChange("")
    onFiltersChange(EMPTY_DISCOVER_FILTERS)
  }

  return (
    <View style={styles.stack}>
      <View style={styles.searchWrap}>
        <AppIcon name="search-outline" size={18} color={colors.gray500} />
        <TextInput
          value={query}
          onChangeText={onQueryChange}
          placeholder="Search by name, category, or city"
          placeholderTextColor={colors.gray500}
          style={styles.search}
          autoCapitalize="none"
          autoCorrect={false}
          returnKeyType="search"
          accessibilityLabel="Search vendors"
        />
        {query.length > 0 ? (
          <Pressable
            onPress={() => onQueryChange("")}
            accessibilityRole="button"
            accessibilityLabel="Clear search"
            hitSlop={10}
          >
            <AppIcon name="close-circle" size={18} color={colors.gray400} />
          </Pressable>
        ) : null}
      </View>

      <View style={styles.toolbar}>
        <Pressable
          style={({ pressed }) => [
            styles.filterBtn,
            activeFilterCount > 0 && styles.filterBtnActive,
            pressed && styles.pressed,
          ]}
          onPress={() => {
            setDraft(filters)
            setSheetOpen(true)
          }}
          accessibilityRole="button"
          accessibilityLabel={activeFilterCount > 0 ? `Filters, ${activeFilterCount} active` : "Filters"}
        >
          <AppIcon name="options-outline" size={16} color={colors.black} />
          <Text style={styles.filterBtnText}>Filters</Text>
          {activeFilterCount > 0 ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{activeFilterCount}</Text>
            </View>
          ) : null}
        </Pressable>
        {activeFilterCount > 0 ? (
          <Button
            label="Clear"
            variant="ghost"
            size="sm"
            onPress={() => onFiltersChange(EMPTY_DISCOVER_FILTERS)}
            accessibilityLabel="Clear discover filters"
          />
        ) : null}
        <View style={styles.flex} />
        {count > 0 || loading ? (
          <Text style={styles.resultCount} accessibilityLiveRegion="polite">
            {loading && count === 0
              ? "Searching…"
              : loading
                ? `Updating · ${count} of ${total}`
                : `${count} of ${total} vendor${total === 1 ? "" : "s"}`}
          </Text>
        ) : null}
      </View>

      {chips.length > 0 ? (
        <View style={styles.chips} accessibilityLabel="Applied filters">
          {chips.map((chip) => (
            <Pressable
              key={chip.key}
              style={({ pressed }) => [styles.appliedChip, pressed && styles.pressed]}
              onPress={() => onFiltersChange({ ...filters, [chip.key]: undefined })}
              accessibilityRole="button"
              accessibilityLabel={`Remove filter ${chip.label}`}
              hitSlop={{ top: 6, bottom: 6 }}
            >
              <Text style={styles.appliedChipText}>{chip.label}</Text>
              <AppIcon name="close" size={14} color={colors.gray700} />
            </Pressable>
          ))}
        </View>
      ) : null}

      {loading && count === 0 ? <LoadingPanel label="Searching vendors…" variant="skeleton" /> : null}

      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={onRetry} />
      ) : null}

      {!loading && !error && count === 0 ? (
        <EmptyPanel
          icon="people-outline"
          title="No vendors found"
          message={
            hasSearch
              ? "Try a different search or clear filters."
              : "Try a different search term or browse without a filter."
          }
          actionLabel={hasSearch ? "Clear filters" : undefined}
          onAction={hasSearch ? clearAll : undefined}
        />
      ) : null}

      <BottomSheet
        visible={sheetOpen}
        title="Discover filters"
        onClose={() => setSheetOpen(false)}
        footer={
          <>
            <Button
              label="Reset"
              variant="secondary"
              style={styles.flex}
              onPress={() => {
                setDraft(EMPTY_DISCOVER_FILTERS)
                onFiltersChange(EMPTY_DISCOVER_FILTERS)
                setSheetOpen(false)
              }}
            />
            <Button
              label="Apply"
              style={styles.flex}
              onPress={() => {
                onFiltersChange(draft)
                setSheetOpen(false)
              }}
            />
          </>
        }
      >
        <Text style={styles.label}>City</Text>
        <TextInput
          value={draft.city ?? ""}
          onChangeText={(city) => setDraft((prev) => ({ ...prev, city }))}
          placeholder="e.g. Charlotte"
          placeholderTextColor={colors.gray500}
          style={styles.sheetInput}
          accessibilityLabel="City"
        />
        <Text style={styles.label}>Category</Text>
        <View style={styles.chips}>
          {LAUNCH_CATEGORIES.map((cat) => {
            const active = draft.category === cat
            return (
              <FilterChip
                key={cat}
                label={cat}
                selected={active}
                onPress={() => setDraft((prev) => ({ ...prev, category: active ? undefined : cat }))}
              />
            )
          })}
        </View>
        <Text style={styles.label}>Trust</Text>
        <View style={styles.chips}>
          <FilterChip
            label="Verified only"
            selected={Boolean(draft.verified)}
            onPress={() => setDraft((prev) => ({ ...prev, verified: !prev.verified }))}
          />
        </View>
        <Text style={styles.label}>Sort</Text>
        <View style={styles.chips}>
          {DISCOVER_SORTS.map((item) => (
            <FilterChip
              key={item.id}
              label={item.label}
              selected={(draft.sort ?? "score") === item.id}
              onPress={() => setDraft((prev) => ({ ...prev, sort: item.id }))}
            />
          ))}
        </View>
      </BottomSheet>
    </View>
  )
}

/** Below the Discover rows: automatic paging shows a spinner; the button stays as a fallback. */
export function DiscoverFooter({
  count,
  hasMore,
  loadingMore,
  onLoadMore,
}: {
  count: number
  hasMore: boolean
  loadingMore: boolean
  onLoadMore: () => void
}) {
  if (count === 0) return null
  if (!hasMore) {
    return <Text style={styles.endText}>That’s every vendor matching your search.</Text>
  }
  return (
    <Button
      label="Load more"
      loadingLabel="Loading more vendors…"
      variant="secondary"
      loading={loadingMore}
      onPress={onLoadMore}
    />
  )
}

/** "3 completed events" or "No completed events on LNKup yet" — never a bare 0. */
export function completedEventsLabel(count?: number | null): string | null {
  if (count == null) return null
  if (count <= 0) return "No completed events on LNKup yet"
  return `${count} completed event${count === 1 ? "" : "s"}`
}

/** The canonical category head: "Food — Tacos and more" → "Food". */
export function shortCategory(category?: string | null): string {
  const raw = (category ?? "").trim()
  return raw.split(/\s+[—–-]\s+/)[0] ?? raw
}

export const DirectoryCard = memo(function DirectoryCard({
  vendor,
  onInvite,
}: {
  vendor: OrganizerDirectoryVendor
  onInvite: (vendor: OrganizerDirectoryVendor) => void
}) {
  const location = [vendor.city, vendor.state].filter(Boolean).join(", ")
  const stats = [
    vendor.rating != null && vendor.rating > 0 ? `${vendor.rating.toFixed(1)}★` : null,
    completedEventsLabel(vendor.eventsCompleted),
  ]
    .filter(Boolean)
    .join(" · ")

  return (
    <View style={styles.card}>
      <Pressable
        style={({ pressed }) => [styles.cardBody, pressed && styles.pressed]}
        onPress={() => router.push(vendorProfileHref(vendor.id) as Href)}
        accessibilityRole="button"
        accessibilityLabel={`View profile for ${vendor.name}${vendor.verified ? ", verified" : ""}`}
      >
        <ProfileMark name={vendor.name} logoUrl={vendor.logoUrl} />
        <View style={styles.cardText}>
          <View style={styles.titleRow}>
            <Text style={styles.title} numberOfLines={2}>
              {vendor.name}
            </Text>
            {vendor.verified ? <StatusBadge label="Verified" tone="approved" /> : null}
          </View>
          <View style={styles.metaRow}>
            <AppIcon name="pricetag-outline" size={13} color={colors.gray500} />
            <Text style={styles.meta} numberOfLines={1}>
              {shortCategory(vendor.category) || "Category not set"}
            </Text>
          </View>
          <View style={styles.metaRow}>
            <AppIcon name="location-outline" size={13} color={colors.gray500} />
            <Text style={styles.meta}>{location || "City not set"}</Text>
          </View>
          {vendor.citiesServed && vendor.citiesServed.length > 0 ? (
            <Text style={styles.meta} numberOfLines={1}>
              Markets: {vendor.citiesServed.join(", ")}
            </Text>
          ) : null}
          {stats ? <Text style={styles.stats}>{stats}</Text> : null}
        </View>
      </Pressable>
      <View style={styles.cardActions}>
        <Button
          label="View profile"
          variant="secondary"
          size="sm"
          style={styles.flex}
          onPress={() => router.push(vendorProfileHref(vendor.id) as Href)}
          accessibilityLabel={`View profile for ${vendor.name}`}
        />
        {vendor.vendorUserId ? (
          <Button
            label="Invite"
            icon="paper-plane-outline"
            variant="accent"
            size="sm"
            style={styles.flex}
            onPress={() => onInvite(vendor)}
            accessibilityLabel={`Invite ${vendor.name} to apply`}
          />
        ) : null}
      </View>
    </View>
  )
})

const styles = StyleSheet.create({
  stack: {
    gap: spacing.md,
  },
  flex: {
    flex: 1,
  },
  searchWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.gray50,
  },
  search: {
    flex: 1,
    paddingVertical: spacing.sm + 2,
    fontSize: typeScale.callout,
    fontFamily: fonts.body,
    color: colors.black,
  },
  toolbar: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  filterBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: 40,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.white,
  },
  filterBtnActive: {
    backgroundColor: colors.limeMuted,
    borderColor: colors.lime,
  },
  filterBtnText: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.black,
  },
  badge: {
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    backgroundColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 6,
  },
  badgeText: {
    fontSize: typeScale.micro,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
  pressed: {
    opacity: 0.8,
  },
  resultCount: {
    ...typography.meta,
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
  },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.md,
  },
  cardBody: {
    flexDirection: "row",
    gap: spacing.md,
  },
  cardText: {
    flex: 1,
    gap: 3,
  },
  titleRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: spacing.sm,
  },
  title: {
    flex: 1,
    fontFamily: fonts.displaySemi,
    fontSize: typeScale.bodyLarge,
    lineHeight: 22,
    color: colors.black,
  },
  metaRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
  },
  meta: {
    ...typography.meta,
  },
  stats: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
  cardActions: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  label: {
    ...typography.eyebrow,
    marginTop: spacing.sm,
  },
  sheetInput: {
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
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  appliedChip: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
    minHeight: 32,
    paddingHorizontal: spacing.md,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
  },
  appliedChipText: {
    ...typography.meta,
    fontFamily: fonts.bodySemi,
    color: colors.black,
  },
  endText: {
    ...typography.meta,
    textAlign: "center",
    paddingVertical: spacing.md,
  },
})
