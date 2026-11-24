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
import { router, useLocalSearchParams } from "expo-router"

import { DiscoveryMap } from "@/components/discovery-map"
import { FilterSheet } from "@/components/filter-sheet"
import { EventCard } from "@/components/marketplace/event-card"
import { openEventGroup } from "@/components/marketplace/event-rail"
import { NearMeButton } from "@/components/near-me-button"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { isLiveApiEnabled } from "@/lib/api/config"
import { savedOptionIds, toggleGroupSaved, type EventGroup } from "@/lib/api/event-groups"
import { isAbortError } from "@/lib/api/wp-client"
import {
  clearFilterKey,
  EMPTY_SEARCH,
  filtersToChips,
  hasActiveFilters,
  queryRecordToSearchParams,
  searchMarketplaceGroups,
  searchParamsToQueryRecord,
  SORT_LABELS,
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
const SEARCH_DEBOUNCE_MS = 450

function routeKey(query: Record<string, string | string[] | undefined>): string {
  return JSON.stringify(searchParamsToQueryRecord(queryRecordToSearchParams(query)))
}

function resultsSummary(totalEvents: number, totalOptions: number | null): string {
  const events = `${totalEvents} event${totalEvents === 1 ? "" : "s"}`
  if (totalOptions == null || totalOptions === totalEvents) return events
  return `${events} · ${totalOptions} vendor option${totalOptions === 1 ? "" : "s"}`
}

/**
 * Explore: one card per event, a compact pinned search/filter bar, and infinite scroll over
 * server pages of events. Tab state (query, filters, scroll) survives tab switches because the
 * tab stays mounted.
 */
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
  const [groups, setGroups] = useState<EventGroup[]>([])
  const [totalEvents, setTotalEvents] = useState(0)
  const [totalOptions, setTotalOptions] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)
  const favorites = useVendorFavorites(auth)
  const { savedIds, setSaved } = favorites
  const [viewMode, setViewMode] = useState<"list" | "map">("list")
  const [mapRefreshToken, setMapRefreshToken] = useState(0)
  const lastRouteKey = useRef(routeKey(routeParams))
  const hasLoadedOnce = useRef(false)
  const searchRef = useRef<AbortController | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const chips = useMemo(() => filtersToChips(filters).filter((c) => c.key !== "query" && c.key !== "category"), [filters])
  const active = hasActiveFilters(filters)
  const filterBadge = filtersToChips(filters).filter((c) => c.key !== "sort" && c.key !== "query" && c.key !== "category").length
  const mapQuery = useMemo(
    () => ({ city: filters.city, state: filters.state, lat: filters.lat, lng: filters.lng, radiusMiles: filters.radiusMiles }),
    [filters.city, filters.state, filters.lat, filters.lng, filters.radiusMiles],
  )
  const locationLabel = filters.lat != null && filters.lng != null
    ? `Near me · ${filters.radiusMiles ?? 50} mi`
    : [filters.city, filters.state].filter(Boolean).join(", ") || "Anywhere in the US"

  const load = useCallback(
    async (nextFilters: SearchParams, mode: "replace" | "append" = "replace") => {
      // A new search cancels the one in flight so a slow, older response can't overwrite it.
      searchRef.current?.abort()
      const controller = new AbortController()
      searchRef.current = controller
      const isCurrent = () => searchRef.current === controller
      if (mode === "append") setLoadingMore(true)
      else if (hasLoadedOnce.current) setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      try {
        const result = await searchMarketplaceGroups(nextFilters, auth, controller.signal)
        if (!isCurrent()) return
        setGroups((prev) => {
          if (mode !== "append") return result.groups
          const seen = new Set(prev.map((g) => g.key))
          return [...prev, ...result.groups.filter((g) => !seen.has(g.key))]
        })
        setTotalEvents(result.totalEvents)
        setTotalOptions(result.totalOptions)
        setPage(result.page)
        setPages(result.pages)
        hasLoadedOnce.current = true
        if (mode === "replace" && (nextFilters.query || nextFilters.category)) {
          void trackMobileEvent({
            event: "search_performed",
            source: "mobile_vendor_find",
            auth,
            metadata: { has_query: Boolean(nextFilters.query), category: nextFilters.category ?? null, results: result.totalEvents },
          })
        }
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

  useEffect(
    () => () => {
      searchRef.current?.abort()
      if (debounceRef.current) clearTimeout(debounceRef.current)
    },
    [],
  )

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

  const applyFilters = useCallback(
    (next: SearchParams) => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
      const normalized = { ...next, page: 1 }
      setFilters(normalized)
      setDraftQuery(normalized.query ?? "")
      const record = searchParamsToQueryRecord(normalized)
      lastRouteKey.current = JSON.stringify(record)
      router.setParams(record as never)
      void load(normalized)
    },
    [load],
  )

  function handleQueryChange(text: string) {
    setDraftQuery(text)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    const trimmed = text.trim()
    if (trimmed.length === 1 || trimmed === (filters.query ?? "")) return
    debounceRef.current = setTimeout(() => applyFilters({ ...filters, query: trimmed || undefined }), SEARCH_DEBOUNCE_MS)
  }

  function handleSearch() {
    applyFilters({ ...filters, query: draftQuery.trim() || undefined })
  }

  function handleClearAll() {
    applyFilters({ ...EMPTY_SEARCH })
  }

  async function handleSave() {
    if (saving) return
    setSaving(true)
    try {
      await createSavedSearch(filters, { name: saveName.trim() || undefined, auth })
      setSaveOpen(false)
      setSaveName("")
      showToast("Search saved. Open it anytime from Saved searches.", "success")
    } catch (err) {
      showToast(`Couldn't save this search. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setSaving(false)
    }
  }

  const countFor = useCallback(
    async (draft: SearchParams, signal: AbortSignal) => (await searchMarketplaceGroups({ ...draft, page: 1 }, auth, signal)).totalEvents,
    [auth],
  )

  const handleEndReached = useCallback(() => {
    if (loadingMore || refreshing || page >= pages || viewMode !== "list") return
    void load({ ...filters, page: page + 1 }, "append")
  }, [filters, load, loadingMore, page, pages, refreshing, viewMode])

  const showFullLoader = initialLoad && groups.length === 0
  const showList = viewMode === "list" && !showFullLoader && groups.length > 0
  const listItems = showList ? groups : EMPTY_ITEMS

  const pinnedHeader = (
    <View style={styles.pinned}>
      <DisplayTitle accessibilityRole="header">Explore</DisplayTitle>
      <View style={styles.searchRow}>
        <View style={styles.searchSurface}>
          <AppIcon name="search-outline" size={18} color={colors.gray500} />
          <TextInput
            value={draftQuery}
            onChangeText={handleQueryChange}
            placeholder="Events, cities, categories"
            placeholderTextColor={colors.gray500}
            style={styles.searchInput}
            returnKeyType="search"
            onSubmitEditing={handleSearch}
            accessibilityLabel="Search events"
            autoCorrect={false}
            autoFocus={routeParams.focus === "1"}
          />
          {draftQuery.length > 0 ? (
            <Pressable onPress={() => applyFilters({ ...filters, query: undefined })} accessibilityRole="button" accessibilityLabel="Clear search" hitSlop={10}>
              <AppIcon name="close-circle" size={18} color={colors.gray400} />
            </Pressable>
          ) : null}
        </View>
        <Pressable
          style={({ pressed }) => [styles.iconBtn, filterBadge > 0 && styles.iconBtnActive, pressed && styles.pressed]}
          onPress={() => {
            setSheetDraft(filters)
            setSheetOpen(true)
          }}
          accessibilityRole="button"
          accessibilityLabel={filterBadge > 0 ? `Filters, ${filterBadge} active` : "Filters"}
        >
          <AppIcon name="options-outline" size={20} color={colors.black} />
          {filterBadge > 0 ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{filterBadge}</Text>
            </View>
          ) : null}
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.iconBtn, viewMode === "map" && styles.iconBtnActive, pressed && styles.pressed]}
          onPress={() => setViewMode((m) => (m === "list" ? "map" : "list"))}
          accessibilityRole="button"
          accessibilityLabel={viewMode === "list" ? "Show map" : "Show list"}
          testID="find-view-toggle"
        >
          <AppIcon name={viewMode === "list" ? "map-outline" : "list"} size={20} color={colors.black} />
        </Pressable>
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.categoryRow} accessibilityLabel="Browse by category">
        <FilterChip label="All" selected={!filters.category} onPress={() => applyFilters({ ...filters, category: undefined })} />
        {QUICK_CATEGORIES.map((cat) => (
          <FilterChip
            key={cat}
            label={cat}
            selected={filters.category === cat}
            onPress={() => applyFilters({ ...filters, category: filters.category === cat ? undefined : cat })}
          />
        ))}
      </ScrollView>
    </View>
  )

  return (
    <>
      <ScreenList
        title="Explore"
        hideTitle
        keyboardAvoiding
        header={pinnedHeader}
        refreshing={refreshing}
        onRefresh={() => {
          if (viewMode === "map") setMapRefreshToken((n) => n + 1)
          void load(filters, "replace")
        }}
        testID="vendor-find-list"
        data={listItems}
        keyExtractor={groupKey}
        onEndReached={handleEndReached}
        renderItem={(group) => (
          <View style={refreshing ? styles.listRefreshing : undefined}>
            <EventCard
              group={group}
              saved={savedOptionIds(group, savedIds).length > 0}
              onToggleSave={() => void toggleGroupSaved(group, savedIds, setSaved)}
              onPress={() => openEventGroup(group)}
              testID="explore-event-card"
            />
          </View>
        )}
        listFooter={
          showList ? (
            loadingMore ? (
              <ActivityIndicator color={colors.black} accessibilityLabel="Loading more events" />
            ) : page >= pages && groups.length > 3 ? (
              <Meta style={styles.endText}>You've seen all {totalEvents} events.</Meta>
            ) : null
          ) : null
        }
        listHeader={
          <>
            {!isLiveApiEnabled() ? (
              <InlineBanner tone="info" title="Sample events" message="These cards are test data. Live events appear when the app is pointed at LNKup." />
            ) : null}

            <View style={styles.locationRow}>
              <Pressable
                style={({ pressed }) => [styles.locationChip, pressed && styles.pressed]}
                onPress={() => {
                  setSheetDraft(filters)
                  setSheetOpen(true)
                }}
                accessibilityRole="button"
                accessibilityLabel={`Location: ${locationLabel}. Change location`}
              >
                <AppIcon name="location-outline" size={16} color={colors.ink} />
                <Text style={styles.locationText} numberOfLines={1}>
                  {locationLabel}
                </Text>
                <AppIcon name="chevron-down" size={14} color={colors.gray500} />
              </Pressable>
              <NearMeButton
                onLocation={(lat, lng, radiusMiles) => applyFilters({ ...filters, lat, lng, radiusMiles, city: undefined, state: undefined, sort: "closest" })}
                onCityFallback={(city) => applyFilters({ ...filters, city, lat: undefined, lng: undefined, radiusMiles: undefined })}
              />
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
                <Button label="Clear all" variant="ghost" size="sm" onPress={handleClearAll} />
              </View>
            ) : null}

            {viewMode === "map" ? <DiscoveryMap query={mapQuery} auth={auth} refreshToken={mapRefreshToken} testID="vendor-find-map" /> : null}

            {viewMode === "list" && showFullLoader ? <LoadingPanel label="Searching events…" variant="skeleton" withMedia count={3} /> : null}

            {viewMode === "list" && !showFullLoader && error && groups.length === 0 ? (
              <ErrorPanel title={error.title} message={error.message} onAction={() => void load(filters)} />
            ) : null}

            {viewMode === "list" && !showFullLoader && !error && groups.length === 0 ? (
              <EmptyPanel
                icon="search-outline"
                title="No matching events"
                message="Try another city, clear filters, or browse nationwide."
                actionLabel={active ? "Clear filters" : undefined}
                onAction={active ? handleClearAll : undefined}
              />
            ) : null}

            {showList ? (
              <View style={styles.resultsHeader}>
                <Text style={styles.count} accessibilityLiveRegion="polite">
                  {resultsSummary(totalEvents, totalOptions)}
                </Text>
                <View style={styles.resultsActions}>
                  {active ? (
                    <Pressable onPress={() => setSaveOpen(true)} accessibilityRole="button" accessibilityLabel="Save this search" hitSlop={8} style={styles.textBtn}>
                      <AppIcon name="bookmark-outline" size={14} color={colors.ink} />
                      <Text style={styles.textBtnLabel}>Save</Text>
                    </Pressable>
                  ) : null}
                  <Pressable
                    onPress={() => {
                      setSheetDraft(filters)
                      setSheetOpen(true)
                    }}
                    accessibilityRole="button"
                    accessibilityLabel={`Sorted by ${SORT_LABELS[(filters.sort ?? "recommended") as MarketplaceSort] ?? filters.sort}. Change sort`}
                    style={styles.sortPill}
                  >
                    <AppIcon name="swap-vertical" size={12} color={colors.gray700} />
                    <Meta style={styles.sortHint}>{SORT_LABELS[(filters.sort ?? "recommended") as MarketplaceSort] ?? filters.sort}</Meta>
                  </Pressable>
                </View>
              </View>
            ) : null}
            {showList && error ? <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void load(filters)} /> : null}
          </>
        }
      />

      <FilterSheet
        visible={sheetOpen}
        value={sheetDraft}
        onChange={setSheetDraft}
        onClose={() => setSheetOpen(false)}
        onApply={() => applyFilters({ ...sheetDraft, query: filters.query })}
        countFor={countFor}
      />

      <Modal visible={saveOpen} transparent animationType="fade" onRequestClose={() => setSaveOpen(false)}>
        <KeyboardAvoidingView style={styles.saveBackdrop} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          <View style={[styles.saveCard, constrainedColumnStyle(layout.sheetMaxWidth)]} accessibilityViewIsModal>
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
              <Button label="Save" loadingLabel="Saving…" loading={saving} onPress={() => void handleSave()} />
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </>
  )
}

const EMPTY_ITEMS: EventGroup[] = []

function groupKey(group: EventGroup): string {
  return group.key
}

const styles = StyleSheet.create({
  pinned: { gap: spacing.sm, paddingBottom: spacing.sm },
  searchRow: { flexDirection: "row", gap: spacing.sm, alignItems: "center" },
  searchSurface: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    minHeight: layout.hitTargetMin,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.white,
  },
  searchInput: { flex: 1, paddingVertical: spacing.sm, fontSize: typeScale.callout, fontFamily: fonts.body, color: colors.black },
  iconBtn: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    borderRadius: radii.pill,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  iconBtnActive: { backgroundColor: colors.limeMuted, borderColor: colors.lime },
  badge: {
    position: "absolute",
    top: -2,
    right: -2,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    backgroundColor: colors.black,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 4,
  },
  badgeText: { fontSize: typeScale.nano, fontFamily: fonts.bodyBold, color: colors.white },
  categoryRow: { gap: spacing.sm, paddingRight: spacing.lg },
  locationRow: { flexDirection: "row", alignItems: "flex-start", gap: spacing.sm, flexWrap: "wrap" },
  locationChip: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    minHeight: 40,
    paddingHorizontal: spacing.md,
    borderRadius: radii.pill,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.line,
    maxWidth: "62%",
  },
  locationText: { fontFamily: fonts.bodySemi, fontSize: typeScale.footnote, color: colors.ink, flexShrink: 1 },
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
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm, alignItems: "center" },
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
  chipText: { fontSize: typeScale.caption, fontFamily: fonts.bodySemi, color: colors.gray900 },
  pressed: { opacity: 0.75 },
  listRefreshing: { opacity: 0.92 },
  resultsHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: spacing.md, marginBottom: -spacing.xs },
  resultsActions: { flexDirection: "row", alignItems: "center", gap: spacing.sm },
  count: { fontSize: typeScale.footnote, fontFamily: fonts.bodySemi, color: colors.gray700, flex: 1 },
  textBtn: { flexDirection: "row", alignItems: "center", gap: 4, minHeight: 32 },
  textBtnLabel: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.ink },
  sortPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: spacing.sm,
    minHeight: 32,
    borderRadius: radii.pill,
    backgroundColor: colors.gray100,
  },
  sortHint: { fontSize: typeScale.caption, fontFamily: fonts.bodySemi, color: colors.gray700 },
  endText: { textAlign: "center", color: colors.gray500 },
  saveBackdrop: { flex: 1, backgroundColor: colors.overlay, justifyContent: "center", padding: spacing.lg },
  saveCard: { backgroundColor: colors.white, borderRadius: radii.lg, padding: spacing.xl, gap: spacing.md },
  saveTitle: { fontSize: typeScale.headline },
  saveActions: { flexDirection: "row", justifyContent: "flex-end", gap: spacing.sm },
})
