import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  TextInput,
  View,
} from "react-native"
import { router, useLocalSearchParams, type Href } from "expo-router"

import { DiscoveryMap } from "@/components/discovery-map"
import { FilterSheet } from "@/components/filter-sheet"
import { MatchRails } from "@/components/match-rails"
import { NearMeButton } from "@/components/near-me-button"
import { OpportunityCard } from "@/components/opportunity-card"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { isLiveApiEnabled } from "@/lib/api/config"
import { isAbortError } from "@/lib/api/wp-client"
import {
  clearFilterKey,
  EMPTY_SEARCH,
  filtersToChips,
  hasActiveFilters,
  queryRecordToSearchParams,
  searchMarketplace,
  searchParamsToQueryRecord,
  type MarketplaceOpportunity,
  type MarketplaceSort,
  type SearchParams,
} from "@/lib/api/marketplace"
import { LAUNCH_CATEGORIES } from "@/lib/api/organizer-launch"
import { createSavedSearch } from "@/lib/api/saved-searches"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { useVendorFavorites } from "@/lib/hooks/use-vendor-favorites"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

/** Quick category chips use the same names organizers pick when creating vendor spots. */
const QUICK_CATEGORIES: readonly string[] = LAUNCH_CATEGORIES

function routeKey(query: Record<string, string | string[] | undefined>): string {
  return JSON.stringify(searchParamsToQueryRecord(queryRecordToSearchParams(query)))
}

const SORT_LABELS: Record<string, string> = {
  recommended: "Recommended",
  newest: "Newest",
  closing_soon: "Closing soon",
  fee_low: "Fee: low to high",
  closest: "Near you",
}

function sortLabel(sort?: MarketplaceSort | string): string {
  if (!sort) return SORT_LABELS.recommended
  return SORT_LABELS[sort] ?? String(sort).replace(/_/g, " ")
}

export default function VendorFindScreen() {
  const { auth } = useAuth()
  const routeParams = useLocalSearchParams<Record<string, string | string[]>>()
  const [filters, setFilters] = useState<SearchParams>(() => queryRecordToSearchParams(routeParams))
  const [draftQuery, setDraftQuery] = useState(() => filters.query ?? "")
  const [sheetOpen, setSheetOpen] = useState(false)
  const [sheetDraft, setSheetDraft] = useState<SearchParams>(filters)
  const [saveOpen, setSaveOpen] = useState(false)
  const [saveName, setSaveName] = useState("")
  const [saving, setSaving] = useState(false)
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<MarketplaceOpportunity[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)
  const favorites = useVendorFavorites(auth)
  const savedIds = favorites.savedIds
  const [viewMode, setViewMode] = useState<"list" | "map">("list")
  const [mapRefreshToken, setMapRefreshToken] = useState(0)
  const lastRouteKey = useRef(routeKey(routeParams))
  const hasLoadedOnce = useRef(false)
  const searchRef = useRef<AbortController | null>(null)

  const chips = useMemo(() => filtersToChips(filters), [filters])
  const active = hasActiveFilters(filters)
  const filterBadge = chips.filter((c) => c.key !== "sort").length
  const mapQuery = useMemo(
    () => ({
      city: filters.city,
      state: filters.state,
      lat: filters.lat,
      lng: filters.lng,
      radiusMiles: filters.radiusMiles,
    }),
    [filters.city, filters.state, filters.lat, filters.lng, filters.radiusMiles],
  )

  const load = useCallback(
    async (nextFilters: SearchParams, mode: "replace" | "append" = "replace") => {
      // A new search cancels the one in flight so a slow, older response can't overwrite it.
      searchRef.current?.abort()
      const controller = new AbortController()
      searchRef.current = controller
      const isCurrent = () => searchRef.current === controller
      if (mode === "append") {
        setLoadingMore(true)
      } else if (hasLoadedOnce.current) {
        setRefreshing(true)
      } else {
        setInitialLoad(true)
      }
      setError(null)
      try {
        const result = await searchMarketplace(nextFilters, auth, controller.signal)
        if (!isCurrent()) return
        setItems((prev) => {
          if (mode !== "append") return result.items
          const seen = new Set(prev.map((item) => `${item.id}-${item.opportunityId ?? ""}`))
          return [
            ...prev,
            ...result.items.filter((item) => !seen.has(`${item.id}-${item.opportunityId ?? ""}`)),
          ]
        })
        setTotal(result.total)
        setPage(result.page)
        setPages(result.pages)
        if (mode === "append") setFilters(nextFilters)
        hasLoadedOnce.current = true
      } catch (err) {
        if (isAbortError(err) || !isCurrent()) return
        if (mode === "append") showToast(`Couldn't load more. ${appErrorFromWp(err).message}`, "error")
        else setError(appErrorFromWp(err))
      } finally {
        if (isCurrent()) {
          searchRef.current = null
          setInitialLoad(false)
          setRefreshing(false)
          setLoadingMore(false)
        }
      }
    },
    [auth],
  )

  useEffect(() => () => searchRef.current?.abort(), [])

  useEffect(() => {
    void load(filters)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const key = routeKey(routeParams)
    if (key === lastRouteKey.current) return
    lastRouteKey.current = key
    const next = queryRecordToSearchParams(routeParams)
    setFilters(next)
    setDraftQuery(next.query ?? "")
    void load(next)
  }, [routeParams, load])

  function applyFilters(next: SearchParams) {
    const normalized = { ...next, page: 1 }
    setFilters(normalized)
    setDraftQuery(normalized.query ?? "")
    const record = searchParamsToQueryRecord(normalized)
    lastRouteKey.current = JSON.stringify(record)
    router.setParams(record as never)
    void load(normalized)
  }

  function handleSearch() {
    applyFilters({ ...filters, query: draftQuery.trim() || undefined })
  }

  function handleClearQuery() {
    setDraftQuery("")
    if (filters.query) applyFilters({ ...filters, query: undefined })
  }

  function handleClearAll() {
    setDraftQuery("")
    applyFilters({ ...EMPTY_SEARCH })
  }

  function handleBrowseRecommended() {
    setDraftQuery("")
    applyFilters({ ...EMPTY_SEARCH, sort: "recommended" })
  }

  async function handleSave() {
    if (saving) return
    setSaving(true)
    try {
      await createSavedSearch(filters, {
        name: saveName.trim() || undefined,
        auth,
      })
      setSaveOpen(false)
      setSaveName("")
      showToast("Search saved. Open it anytime from Saved searches.", "success")
    } catch (err) {
      showToast(`Couldn't save this search. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setSaving(false)
    }
  }

  async function handleToggleSave(item: MarketplaceOpportunity) {
    if (!item.opportunityId) return
    await favorites.toggle(item.opportunityId)
  }

  const showFullLoader = initialLoad && items.length === 0
  const showingCount = items.length

  const showList = viewMode === "list" && !showFullLoader && items.length > 0
  const listItems = showList ? items : EMPTY_ITEMS

  return (
    <>
      <ScreenList
        title="Explore"
        subtitle="Bookable spots. Apply for free. Pay only when accepted."
        keyboardAvoiding
        refreshing={refreshing}
        onRefresh={() => {
          if (viewMode === "map") setMapRefreshToken((n) => n + 1)
          void load(filters, "replace")
        }}
        testID="vendor-find-list"
        data={listItems}
        keyExtractor={opportunityKey}
        renderItem={(item) => (
          <View style={refreshing ? styles.listRefreshing : undefined}>
            <OpportunityCard
              item={item}
              saved={item.opportunityId ? savedIds.has(item.opportunityId) : false}
              onToggleSave={item.opportunityId ? () => void handleToggleSave(item) : undefined}
              onPress={
                item.opportunityId
                  ? () => router.push(`/opportunity/${item.opportunityId}` as Href)
                  : undefined
              }
              onApply={
                item.opportunityId ? () => router.push(`/apply/${item.opportunityId}` as Href) : undefined
              }
            />
          </View>
        )}
        listFooter={
          showList && page < pages ? (
            <View style={styles.loadMoreWrap}>
              <Meta style={styles.showingMore}>
                Showing {showingCount} of {total}
              </Meta>
              <Button
                label="Load more"
                loadingLabel="Loading…"
                variant="secondary"
                fullWidth
                loading={loadingMore}
                onPress={() => {
                  if (loadingMore) return
                  void load({ ...filters, page: page + 1 }, "append")
                }}
              />
            </View>
          ) : null
        }
        listHeader={
          <>
            {!isLiveApiEnabled() ? (
              <InlineBanner
                tone="info"
                title="Sample events"
                message="These cards are test data. Live events appear when the app is pointed at LNKup."
              />
            ) : null}
            <View>
              <View style={styles.searchRow}>
                <View style={styles.inputWrap}>
                  <AppIcon name="search-outline" size={18} color={colors.gray500} />
                  <TextInput
                    value={draftQuery}
                    onChangeText={setDraftQuery}
                    placeholder="Search city, event, category…"
                    placeholderTextColor={colors.gray500}
                    style={styles.searchInput}
                    returnKeyType="search"
                    onSubmitEditing={handleSearch}
                    accessibilityLabel="Search events"
                    autoCorrect={false}
                  />
                  {draftQuery.length > 0 ? (
                    <Pressable
                      onPress={handleClearQuery}
                      accessibilityRole="button"
                      accessibilityLabel="Clear search"
                      hitSlop={10}
                    >
                      <AppIcon name="close-circle" size={18} color={colors.gray400} />
                    </Pressable>
                  ) : null}
                </View>
                <Button label="Search" onPress={handleSearch} accessibilityLabel="Run search" />
              </View>
            </View>

            <View style={styles.toolbar}>
              <Pressable
                style={({ pressed }) => [
                  styles.filterBtn,
                  active && styles.filterBtnActive,
                  pressed && styles.pressed,
                ]}
                onPress={() => {
                  setSheetDraft(filters)
                  setSheetOpen(true)
                }}
                accessibilityRole="button"
                accessibilityLabel={filterBadge > 0 ? `Filters, ${filterBadge} active` : "Filters"}
              >
                <AppIcon name="options-outline" size={16} color={active ? colors.black : colors.gray700} />
                <Meta style={[styles.filterBtnText, active && styles.filterBtnTextActive]}>Filters</Meta>
                {filterBadge > 0 ? (
                  <View style={styles.badge}>
                    <Meta style={styles.badgeText}>{filterBadge}</Meta>
                  </View>
                ) : null}
              </Pressable>
        <View>
          <NearMeButton
                  onLocation={(lat, lng, radiusMiles) =>
                    applyFilters({
                      ...filters,
                      lat,
                      lng,
                      radiusMiles,
                      sort: "closest",
                    })
                  }
                  onCityFallback={(city) =>
                    applyFilters({
                      ...filters,
                      city,
                      lat: undefined,
                      lng: undefined,
                      radiusMiles: undefined,
                    })
                  }
                />
              </View>
            </View>

            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={styles.categoryRow}
              accessibilityLabel="Browse by category"
            >
              {QUICK_CATEGORIES.map((cat) => (
                <FilterChip
                  key={cat}
                  label={cat}
                  selected={filters.category === cat}
                  onPress={() => applyFilters({ ...filters, category: filters.category === cat ? undefined : cat })}
                />
              ))}
            </ScrollView>

            <View style={styles.linkRow}>
              <View style={styles.viewToggle} testID="find-view-toggle" accessibilityRole="tablist">
                <Pressable
                  style={[styles.viewToggleBtn, viewMode === "list" && styles.viewToggleBtnActive]}
                  onPress={() => setViewMode("list")}
                  accessibilityRole="tab"
                  accessibilityLabel="List view"
                  accessibilityState={{ selected: viewMode === "list" }}
                  testID="find-view-list"
                >
                  <AppIcon
                    name={viewMode === "list" ? "list" : "list-outline"}
                    size={14}
                    color={viewMode === "list" ? colors.black : colors.gray500}
                  />
                  <Meta style={[styles.viewToggleText, viewMode === "list" && styles.viewToggleTextActive]}>
                    List
                  </Meta>
                </Pressable>
                <Pressable
                  style={[styles.viewToggleBtn, viewMode === "map" && styles.viewToggleBtnActive]}
                  onPress={() => setViewMode("map")}
                  accessibilityRole="tab"
                  accessibilityLabel="Map view"
                  accessibilityState={{ selected: viewMode === "map" }}
                  testID="find-view-map"
                >
                  <AppIcon
                    name={viewMode === "map" ? "map" : "map-outline"}
                    size={14}
                    color={viewMode === "map" ? colors.black : colors.gray500}
                  />
                  <Meta style={[styles.viewToggleText, viewMode === "map" && styles.viewToggleTextActive]}>
                    Map
                  </Meta>
                </Pressable>
              </View>
              <View style={styles.linkRight}>
                {active ? (
                  <Button
                    label="Save"
                    icon="bookmark-outline"
                    variant="ghost"
                    size="sm"
                    onPress={() => setSaveOpen(true)}
                    accessibilityLabel="Save this search"
                  />
                ) : null}
                <Button
                  label="Saved"
                  icon="albums-outline"
                  variant="ghost"
                  size="sm"
                  onPress={() => router.push("/(vendor)/saved-searches")}
                  accessibilityLabel="Open saved searches"
                />
              </View>
            </View>

            {chips.length > 0 ? (
              <View style={styles.chips}>
                {chips.map((chip) => (
                  <Pressable
                    key={chip.key}
                    style={({ pressed }) => [styles.chip, pressed && styles.pressed]}
                    onPress={() => applyFilters(clearFilterKey(filters, chip.key))}
                    accessibilityRole="button"
                    accessibilityLabel={`Remove filter ${chip.label}`}
                    hitSlop={{ top: 6, bottom: 6 }}
                  >
                    <Meta style={styles.chipText}>{chip.label}</Meta>
                    <AppIcon name="close" size={14} color={colors.gray700} />
                  </Pressable>
                ))}
                {active ? (
                  <Button label="Clear all" variant="ghost" size="sm" onPress={handleClearAll} />
                ) : null}
              </View>
            ) : null}

            {viewMode === "map" ? (
              <DiscoveryMap
                query={mapQuery}
                auth={auth}
                refreshToken={mapRefreshToken}
                testID="vendor-find-map"
              />
            ) : null}

            {viewMode === "list" && !active ? (
              <MatchRails
                auth={auth ?? undefined}
                savedIds={savedIds}
                onToggleSave={(opportunityId) => void favorites.toggle(opportunityId)}
              />
            ) : null}

            {viewMode === "list" && showFullLoader ? (
              <LoadingPanel label="Searching opportunities…" variant="skeleton" withMedia count={3} />
            ) : null}

            {viewMode === "list" && !showFullLoader && error && items.length === 0 ? (
              <ErrorPanel title={error.title} message={error.message} onAction={() => void load(filters)} />
            ) : null}

            {viewMode === "list" && !showFullLoader && !error && items.length === 0 ? (
              <EmptyPanel
                icon="search-outline"
                title="No matches"
                message="Try clearing filters, searching Near me, or opening a saved search."
                actionLabel="Clear filters"
                onAction={handleClearAll}
                secondaryActionLabel="Browse recommended"
                onSecondaryAction={handleBrowseRecommended}
              />
            ) : null}

            {showList ? (
              <View style={styles.list}>
                <View style={styles.resultsHeader}>
                  <Meta style={styles.count}>
                    {total} opportunit{total === 1 ? "y" : "ies"}
                    {pages > 1 ? ` · Showing ${showingCount}` : ""}
                  </Meta>
                  <View style={styles.sortPill}>
                    <AppIcon name="swap-vertical" size={12} color={colors.gray700} />
                    <Meta style={styles.sortHint}>{sortLabel(filters.sort)}</Meta>
                  </View>
                </View>
                {refreshing ? (
                  <View style={styles.refreshRow} accessibilityLiveRegion="polite">
                    <ActivityIndicator color={colors.black} size="small" />
                    <Meta style={styles.refreshText}>Updating…</Meta>
                  </View>
                ) : null}
                {error ? (
                  <InlineBanner
                    tone="error"
                    message={error.message}
                    actionLabel="Retry"
                    onAction={() => void load(filters)}
                  />
                ) : null}
              </View>
            ) : null}
          </>
        }
      />

      <FilterSheet
        visible={sheetOpen}
        value={sheetDraft}
        onChange={setSheetDraft}
        onClose={() => setSheetOpen(false)}
        onApply={() => applyFilters({ ...sheetDraft })}
      />

      <Modal visible={saveOpen} transparent animationType="fade" onRequestClose={() => setSaveOpen(false)}>
        <KeyboardAvoidingView
          style={styles.saveBackdrop}
          behavior={Platform.OS === "ios" ? "padding" : undefined}
        >
          <View
            style={[styles.saveCard, constrainedColumnStyle(layout.sheetMaxWidth)]}
            accessibilityViewIsModal
          >
            <SectionTitle style={styles.saveTitle} accessibilityRole="header">
              Save this search
            </SectionTitle>
            <Meta>We'll alert you about new matches. Find it anytime under Saved searches.</Meta>
            <TextInput
              value={saveName}
              onChangeText={setSaveName}
              placeholder="Name (optional)"
              placeholderTextColor={colors.gray500}
              style={styles.input}
              accessibilityLabel="Saved search name"
              autoFocus
              returnKeyType="done"
              onSubmitEditing={() => void handleSave()}
            />
            <View style={styles.saveActions}>
              <Button label="Cancel" variant="secondary" onPress={() => setSaveOpen(false)} />
              <Button
                label="Save"
                loadingLabel="Saving…"
                loading={saving}
                onPress={() => void handleSave()}
              />
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </>
  )
}

const EMPTY_ITEMS: MarketplaceOpportunity[] = []

function opportunityKey(item: MarketplaceOpportunity): string {
  return `${item.id}-${item.opportunityId ?? ""}`
}

const styles = StyleSheet.create({
  searchRow: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  inputWrap: {
    flex: 1,
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
  searchInput: {
    flex: 1,
    paddingVertical: spacing.sm + 2,
    fontSize: typeScale.callout,
    fontFamily: fonts.body,
    color: colors.black,
  },
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
  toolbar: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
    alignItems: "center",
  },
  categoryRow: {
    gap: spacing.sm,
    paddingRight: spacing.lg,
  },
  filterBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin,
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
    color: colors.black,
    fontSize: typeScale.footnote,
  },
  filterBtnTextActive: {
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
  linkRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: spacing.sm,
  },
  linkRight: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
  },
  viewToggle: {
    flexDirection: "row",
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.lg,
    overflow: "hidden",
    backgroundColor: colors.gray50,
    padding: 3,
    gap: 2,
  },
  viewToggleBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
  },
  viewToggleBtnActive: {
    backgroundColor: colors.lime,
  },
  viewToggleText: {
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  viewToggleTextActive: {
    color: colors.black,
  },
  chips: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
    alignItems: "center",
  },
  chip: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
    minHeight: 32,
    backgroundColor: colors.limeMuted,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    borderWidth: 1,
    borderColor: colors.lime,
  },
  chipText: {
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
    color: colors.gray900,
  },
  pressed: {
    opacity: 0.75,
  },
  list: {
    gap: spacing.md,
  },
  listRefreshing: {
    opacity: 0.92,
  },
  resultsHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: spacing.md,
  },
  count: {
    fontSize: typeScale.footnote,
    fontFamily: fonts.bodySemi,
    color: colors.gray500,
    flex: 1,
  },
  sortPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
  },
  sortHint: {
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
    color: colors.gray700,
  },
  refreshRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  refreshText: {
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
    color: colors.gray500,
  },
  loadMoreWrap: {
    gap: spacing.sm,
    alignItems: "center",
  },
  showingMore: {
    fontSize: typeScale.caption,
    fontFamily: fonts.bodySemi,
    color: colors.gray500,
  },
  saveBackdrop: {
    flex: 1,
    backgroundColor: colors.overlay,
    justifyContent: "center",
    padding: spacing.lg,
  },
  saveCard: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.md,
  },
  saveTitle: {
    fontSize: typeScale.headline,
  },
  saveActions: {
    flexDirection: "row",
    justifyContent: "flex-end",
    gap: spacing.sm,
  },
})
