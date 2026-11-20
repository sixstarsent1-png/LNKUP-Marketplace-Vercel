import { useWindowDimensions, type ViewStyle } from "react-native"

import { layout, spacing } from "@/lib/theme"

export function useBreakpoint() {
  const { width, height } = useWindowDimensions()
  const isRegular = width >= layout.regularMinWidth

  return {
    width,
    height,
    isRegular,
    contentMaxWidth: layout.contentMaxWidth,
    formMaxWidth: layout.formMaxWidth,
    sheetMaxWidth: layout.sheetMaxWidth,
    contentPadding: isRegular ? spacing.xl : spacing.lg,
    tabBarHeight: isRegular ? layout.tabBarHeight.regular : layout.tabBarHeight.compact,
    tabBarPaddingHorizontal: isRegular
      ? layout.tabBarPaddingHorizontal.regular
      : layout.tabBarPaddingHorizontal.compact,
    tabIconSize: isRegular ? 24 : 22,
    tabLabelSize: isRegular ? 12 : 11,
    primaryButtonPaddingVertical: isRegular ? spacing.lg : spacing.md,
    mapHeight: isRegular ? Math.min(480, width * 0.42) : 360,
  }
}

export function constrainedColumnStyle(maxWidth: number): ViewStyle {
  return {
    width: "100%",
    maxWidth,
    alignSelf: "center",
  }
}
