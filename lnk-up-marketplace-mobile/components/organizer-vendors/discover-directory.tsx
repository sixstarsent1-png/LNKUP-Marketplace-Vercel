import { useState } from "react"
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
import type {
  OrganizerDirectoryVendor,
  OrganizerDiscoverFilters,
} from "@/lib/api/organizer-vendor-directory"
import { colors, fonts, layout, radii, spacing, typography } from "@/lib/theme"

type DiscoverDirectoryProps = {
  query: string
  onQueryChange: (query: string) => void
  filters: OrganizerDiscoverFilters
  onFiltersChange: (filters: OrganizerDiscoverFilters) => void
  activeFilterCount: number
  loading: boolean
  loadingMore: boolean
  error: AppErrorShape | null
  directory: OrganizerDirectoryVendor[]
  total: number
  hasMore: boolean
  invitingId: string | null
  onRetry: () => void
  onLoadMore: () => void
  onInvite: (vendor: OrganizerDirectoryVendor) => void
}

/** Discover segment: vendor directory search, filters sheet, results, and invite actions. */
export function DiscoverDirectory({
  query,
  onQueryChange,
  filters,
  onFiltersChange,
  activeFilterCount,
  loading,
  loadingMore,
  error,
  directory,
  total,
  hasMore,
  invitingId,
  onRetry,
  onLoadMore,
  onInvite,
}: DiscoverDirectoryProps) {
  const [sheetOpen, setSheetOpen] = useState(false)
  const [draft, setDraft] = useState<OrganizerDiscoverFilters>(EMPTY_DISCOVER_FILTERS)
  const hasSearch = query.trim().length > 0 || activeFilterCount > 0

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
          accessibilityLabel={
            activeFilterCount > 0 ? `Filters, ${activeFilterCount} active` : "Filters"
          }
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
        {directory.length > 0 || loading ? (
          <Text style={styles.resultCount} accessibilityLiveRegion="polite">
            {loading && directory.length === 0
              ? "Searching…"
              : loading
                ? `Updating · ${directory.length} of ${total}`
                : `${directory.length} of ${total} vendor${total === 1 ? "" : "s"}`}
          </Text>
        ) : null}
      </View>

      {loading && directory.length === 0 ? (
        <LoadingPanel label="Searching vendors…" variant="skeleton" />
      ) : null}

      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={onRetry} />
      ) : null}

      {!loading && !error && directory.length === 0 ? (
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

      {directory.length > 0 ? (
        <View style={styles.list}>
          {directory.map((vendor) => (
            <DirectoryCard
              key={vendor.id}
              vendor={vendor}
              inviting={invitingId === vendor.id}
              onInvite={onInvite}
            />
          ))}
          {hasMore ? (
            <Button
              label="Load more"
              loadingLabel="Loading…"
              variant="secondary"
              loading={loadingMore}
              onPress={onLoadMore}
            />
          ) : null}
        </View>
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
                onPress={() =>
                  setDraft((prev) => ({ ...prev, category: active ? undefined : cat }))
                }
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

function DirectoryCard({
  vendor,
  inviting,
  onInvite,
}: {
  vendor: OrganizerDirectoryVendor
  inviting: boolean
  onInvite: (vendor: OrganizerDirectoryVendor) => void
}) {
  const location = [vendor.city, vendor.state].filter(Boolean).join(", ")
  const stats = [
    vendor.rating != null ? `${vendor.rating.toFixed(1)}★` : null,
    vendor.eventsCompleted != null ? `${vendor.eventsCompleted} events` : null,
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
            <Text style={styles.meta}>{vendor.category || "Category not set"}</Text>
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
            loading={inviting}
            onPress={() => onInvite(vendor)}
            accessibilityLabel={`Invite ${vendor.name} to apply`}
          />
        ) : null}
      </View>
    </View>
  )
}

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
    fontSize: 15,
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
    fontSize: 13,
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
    fontSize: 11,
    fontFamily: fonts.bodyBold,
    color: colors.black,
  },
  pressed: {
    opacity: 0.8,
  },
  resultCount: {
    ...typography.meta,
    fontSize: 12,
    fontFamily: fonts.bodySemi,
  },
  list: {
    gap: spacing.md,
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
    fontSize: 16,
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
    fontSize: 13,
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
    fontSize: 15,
    fontFamily: fonts.body,
    color: colors.black,
    backgroundColor: colors.gray50,
  },
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
})
