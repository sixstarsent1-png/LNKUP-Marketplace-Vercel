import { useEffect, useRef, type ReactElement, type ReactNode } from "react"
import {
  FlatList,
  KeyboardAvoidingView,
  Platform,
  RefreshControl,
  ScrollView,
  StyleSheet,
  View,
  type ViewProps,
} from "react-native"
import { SafeAreaView } from "react-native-safe-area-context"

import { clearDemoDataBanner } from "@/components/demo-data-banner"
import { DisplayTitle, Eyebrow, Meta } from "@/components/ui/typography"
import { constrainedColumnStyle, useBreakpoint } from "@/lib/layout/use-breakpoint"
import { colors, layout, spacing } from "@/lib/theme"

type ScreenProps = ViewProps & {
  title: string
  subtitle?: string
  rightSlot?: ReactNode
  scroll?: boolean
  refreshing?: boolean
  onRefresh?: () => void
  /** Keep inputs above the keyboard (on by default; harmless on screens without inputs). */
  keyboardAvoiding?: boolean
  /** Rendered above the scroll area (e.g. a back link or segmented tabs that should stay visible). */
  header?: ReactNode
  /** Pinned below the scroll area (e.g. StickyActionBar). */
  footer?: ReactNode
  /** Hide the large title block (screens that render their own hero). */
  hideTitle?: boolean
  children: ReactNode
}

export function Screen({
  title,
  subtitle,
  rightSlot,
  scroll = true,
  refreshing = false,
  onRefresh,
  keyboardAvoiding = true,
  header,
  footer,
  hideTitle = false,
  children,
  style,
  ...rest
}: ScreenProps) {
  const { contentPadding } = useBreakpoint()
  const column = [constrainedColumnStyle(layout.contentMaxWidth), { paddingHorizontal: contentPadding }]
  const body = (
    <View style={[styles.content, ...column, style]} {...rest}>
      {hideTitle ? null : (
        <View style={styles.header}>
          <View style={styles.headerText}>
            <Eyebrow>LNKup</Eyebrow>
            <DisplayTitle accessibilityRole="header">{title}</DisplayTitle>
            {subtitle ? <Meta style={styles.subtitle}>{subtitle}</Meta> : null}
          </View>
          {rightSlot}
        </View>
      )}
      {children}
    </View>
  )

  const content = scroll ? (
    <ScrollView
      style={styles.flex}
      contentContainerStyle={styles.scroll}
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode="on-drag"
      refreshControl={
        onRefresh ? (
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              clearDemoDataBanner()
              onRefresh()
            }}
            tintColor={colors.black}
          />
        ) : undefined
      }
    >
      {body}
    </ScrollView>
  ) : (
    <View style={styles.flex}>{body}</View>
  )

  const stack = (
    <>
      {header ? <View style={[styles.pinnedHeader, ...column]}>{header}</View> : null}
      {content}
      {footer}
    </>
  )

  return (
    <SafeAreaView style={styles.safe} edges={["top"]}>
      {keyboardAvoiding ? (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          {stack}
        </KeyboardAvoidingView>
      ) : (
        stack
      )}
    </SafeAreaView>
  )
}

type ScreenListProps<T> = Omit<ScreenProps, "scroll" | "children" | "style"> & {
  /** Everything above the rows (filters, banners, loading/empty/error states). */
  listHeader?: ReactNode
  data: ReadonlyArray<T>
  renderItem: (item: T, index: number) => ReactElement | null
  keyExtractor: (item: T, index: number) => string
  listFooter?: ReactNode
  onEndReached?: () => void
  testID?: string
  /**
   * When set, the scroll offset is remembered under this key and restored when the list remounts,
   * or when `focused` turns true again (the web build hides a tab screen under a pushed route and
   * the browser drops its scroll position).
   */
  restoreKey?: string
  /** Screen focus from the navigator; pass it together with `restoreKey`. */
  focused?: boolean
}

const savedListOffsets = new Map<string, number>()

/**
 * `Screen` whose body is a virtualized FlatList, for long or paginated lists. Same title, pinned
 * header, pull-to-refresh, keyboard and column rules as `Screen`.
 */
export function ScreenList<T>({
  title,
  subtitle,
  rightSlot,
  refreshing = false,
  onRefresh,
  keyboardAvoiding = true,
  header,
  footer,
  hideTitle = false,
  listHeader,
  data,
  renderItem,
  keyExtractor,
  listFooter,
  onEndReached,
  testID,
  restoreKey,
  focused,
}: ScreenListProps<T>) {
  const { contentPadding } = useBreakpoint()
  const column = [constrainedColumnStyle(layout.contentMaxWidth), { paddingHorizontal: contentPadding }]
  const listRef = useRef<FlatList<T>>(null)
  const restored = useRef(false)

  useEffect(() => {
    if (!restoreKey || restored.current || data.length === 0) return
    restored.current = true
    const offset = savedListOffsets.get(restoreKey)
    if (!offset) return
    requestAnimationFrame(() => {
      listRef.current?.scrollToOffset({ offset, animated: false })
    })
  }, [restoreKey, data.length])

  const offsetAtBlur = useRef<number | null>(null)
  useEffect(() => {
    if (!restoreKey || focused === undefined) return
    if (!focused) {
      offsetAtBlur.current = savedListOffsets.get(restoreKey) ?? null
      return
    }
    const offset = offsetAtBlur.current
    offsetAtBlur.current = null
    if (!offset) return
    requestAnimationFrame(() => {
      listRef.current?.scrollToOffset({ offset, animated: false })
    })
  }, [focused, restoreKey])

  const list = (
    <FlatList
      ref={listRef}
      onScroll={
        restoreKey
          ? (event) => savedListOffsets.set(restoreKey, event.nativeEvent.contentOffset.y)
          : undefined
      }
      scrollEventThrottle={restoreKey ? 16 : undefined}
      style={styles.flex}
      contentContainerStyle={styles.scroll}
      data={data}
      keyExtractor={keyExtractor}
      renderItem={({ item, index }) => <View style={column}>{renderItem(item, index)}</View>}
      ItemSeparatorComponent={ListSeparator}
      ListHeaderComponent={
        <View style={[styles.content, styles.listHeader, ...column]}>
          {hideTitle ? null : (
            <View style={styles.header}>
              <View style={styles.headerText}>
                <Eyebrow>LNKup</Eyebrow>
                <DisplayTitle accessibilityRole="header">{title}</DisplayTitle>
                {subtitle ? <Meta style={styles.subtitle}>{subtitle}</Meta> : null}
              </View>
              {rightSlot}
            </View>
          )}
          {listHeader}
        </View>
      }
      ListFooterComponent={
        listFooter ? <View style={[styles.listFooter, ...column]}>{listFooter}</View> : null
      }
      onEndReached={onEndReached}
      onEndReachedThreshold={0.5}
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode="on-drag"
      initialNumToRender={6}
      windowSize={7}
      testID={testID}
      refreshControl={
        onRefresh ? (
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              clearDemoDataBanner()
              onRefresh()
            }}
            tintColor={colors.black}
          />
        ) : undefined
      }
    />
  )

  const stack = (
    <>
      {header ? <View style={[styles.pinnedHeader, ...column]}>{header}</View> : null}
      {list}
      {footer}
    </>
  )

  return (
    <SafeAreaView style={styles.safe} edges={["top"]}>
      {keyboardAvoiding ? (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          {stack}
        </KeyboardAvoidingView>
      ) : (
        stack
      )}
    </SafeAreaView>
  )
}

function ListSeparator() {
  return <View style={styles.separator} />
}

const styles = StyleSheet.create({
  listHeader: {
    paddingBottom: spacing.lg,
  },
  listFooter: {
    paddingTop: spacing.lg,
  },
  separator: {
    height: spacing.md,
  },
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  flex: {
    flex: 1,
  },
  scroll: {
    paddingBottom: spacing.xxl,
  },
  content: {
    paddingTop: spacing.md,
    gap: spacing.lg,
  },
  pinnedHeader: {
    paddingTop: spacing.sm,
  },
  header: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    gap: spacing.md,
  },
  headerText: {
    flex: 1,
    gap: 2,
  },
  subtitle: {
    marginTop: 2,
  },
})
