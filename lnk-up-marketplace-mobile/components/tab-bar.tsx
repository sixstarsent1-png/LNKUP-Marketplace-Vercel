import { StyleSheet, View, type ColorValue } from "react-native"
import { useSafeAreaInsets } from "react-native-safe-area-context"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { useBreakpoint } from "@/lib/layout/use-breakpoint"
import { colors, fonts, layout } from "@/lib/theme"

/** Tab bar icon with a lime focus dot. */
export function TabIcon({
  name,
  color,
  focused,
  size,
}: {
  name: AppIconName
  color: string
  focused: boolean
  size: number
}) {
  return (
    <View style={styles.iconWrap}>
      <AppIcon name={name} size={size} color={color} />
      <View style={[styles.activeDot, !focused && styles.activeDotHidden]} />
    </View>
  )
}

/** Builds a tabBarIcon renderer that swaps outline/filled icons on focus. */
export function tabIcon(filled: AppIconName, outline: AppIconName = filled, size: number) {
  return function renderTabIcon({ color, focused }: { color: ColorValue; focused: boolean }) {
    return <TabIcon name={focused ? filled : outline} color={String(color)} focused={focused} size={size} />
  }
}

/** Shared, safe-area-aware screen options for the vendor and organizer tab navigators. */
export function useTabBarScreenOptions() {
  const insets = useSafeAreaInsets()
  const { tabBarHeight, tabBarPaddingHorizontal, tabIconSize, tabLabelSize } = useBreakpoint()
  // Home-indicator devices get the inset; others (and web) keep a small gap so labels never clip.
  const bottomPadding = Math.max(insets.bottom, 10)

  const screenOptions = {
    headerShown: false,
    tabBarActiveTintColor: colors.black,
    tabBarInactiveTintColor: colors.gray500,
    tabBarHideOnKeyboard: true,
    tabBarAllowFontScaling: false,
    tabBarStyle: {
      backgroundColor: colors.white,
      borderTopColor: colors.gray200,
      height: tabBarHeight + bottomPadding,
      paddingBottom: bottomPadding,
      paddingTop: 6,
      paddingHorizontal: tabBarPaddingHorizontal,
    },
    tabBarItemStyle: {
      minHeight: layout.hitTargetMin,
    },
    tabBarLabelStyle: {
      fontSize: tabLabelSize,
      lineHeight: tabLabelSize + 4,
      fontFamily: fonts.bodySemi,
      marginTop: 2,
    },
    tabBarBadgeStyle: {
      backgroundColor: colors.lime,
      color: colors.black,
      fontFamily: fonts.bodyBold,
      fontSize: 10,
    },
  }

  return { screenOptions, tabIconSize }
}

const styles = StyleSheet.create({
  iconWrap: {
    alignItems: "center",
    justifyContent: "center",
    minHeight: 28,
  },
  activeDot: {
    marginTop: 2,
    width: 4,
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.lime,
  },
  activeDotHidden: {
    backgroundColor: "transparent",
  },
})
