import { Platform, type TextStyle, type ViewStyle } from "react-native"

export const colors = {
  black: "#0A0A0A",
  white: "#FFFFFF",
  /** sRGB of the WordPress theme accent oklch(0.88 0.19 128). */
  lime: "#B3EE55",
  limeDark: "#A8D120",
  limeMuted: "#EEF9C8",
  gray50: "#FAFAFA",
  gray100: "#F4F4F5",
  gray200: "#E4E4E7",
  gray400: "#A1A1AA",
  gray500: "#71717A",
  gray700: "#3F3F46",
  gray900: "#18181B",
  red50: "#FEF2F2",
  red200: "#FECACA",
  red600: "#DC2626",
  amber50: "#FFFBEB",
  amber200: "#FDE68A",
  amber700: "#B45309",
  green50: "#F0FDF4",
  green200: "#BBF7D0",
  green700: "#15803D",
  overlay: "rgba(0, 0, 0, 0.4)",
  /** Vendor OS tokens shared with the WordPress design layer (vendor-os.css). */
  canvas: "#F6F5F1",
  surfaceMuted: "#FAF9F6",
  line: "#E7E5DF",
  lineStrong: "#D6D3CB",
  ink: "#141414",
  inkMuted: "#62626B",
  shell: "#121212",
} as const

export const fonts = {
  display: "Sora_700Bold",
  displaySemi: "Sora_600SemiBold",
  body: "Inter_400Regular",
  bodyMedium: "Inter_500Medium",
  bodySemi: "Inter_600SemiBold",
  bodyBold: "Inter_700Bold",
} as const

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const

export const radii = {
  sm: 8,
  md: 12,
  lg: 16,
  xl: 20,
  pill: 999,
} as const

export const layout = {
  regularMinWidth: 600,
  contentMaxWidth: 720,
  formMaxWidth: 420,
  sheetMaxWidth: 480,
  hitTargetMin: 44,
  tabBarHeight: {
    compact: 62,
    regular: 72,
  },
  tabBarPaddingHorizontal: {
    compact: 0,
    regular: 64,
  },
} as const

type ShadowLevel = "sm" | "md" | "card"

const shadowNative: Record<ShadowLevel, ViewStyle> = {
  sm: {
    shadowColor: "#000",
    shadowOpacity: 0.06,
    shadowRadius: 4,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
  md: {
    shadowColor: "#000",
    shadowOpacity: 0.1,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
    elevation: 4,
  },
  card: {
    shadowColor: "#000",
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6,
  },
}

const shadowWeb: Record<ShadowLevel, ViewStyle> = {
  sm: { boxShadow: "0 2px 4px rgba(0, 0, 0, 0.06)" },
  md: { boxShadow: "0 4px 8px rgba(0, 0, 0, 0.1)" },
  card: { boxShadow: "0 6px 12px rgba(0, 0, 0, 0.12)" },
}

export function shadow(level: ShadowLevel): ViewStyle {
  return Platform.OS === "web" ? shadowWeb[level] : shadowNative[level]
}

export type SurfaceVariant = "default" | "hero" | "accent" | "urgent" | "elevated"

export function surfaceStyle(variant: SurfaceVariant = "default"): ViewStyle {
  switch (variant) {
    case "hero":
      return {
        backgroundColor: colors.gray50,
        borderColor: colors.gray200,
      }
    case "accent":
      return {
        backgroundColor: colors.limeMuted,
        borderColor: colors.lime,
      }
    case "urgent":
      return {
        backgroundColor: colors.amber50,
        borderColor: colors.amber200,
      }
    case "elevated":
      return {
        backgroundColor: colors.white,
        borderColor: colors.gray200,
        ...shadow("card"),
      }
    default:
      return {
        backgroundColor: colors.white,
        borderColor: colors.line,
      }
  }
}

/** Point sizes for the type ramp; screens pick a step instead of a raw number. */
export const typeScale = {
  jumbo: 48,
  hero: 36,
  large: 32,
  display: 28,
  title: 22,
  headline: 18,
  bodyLarge: 16,
  body: 14,
  callout: 15,
  footnote: 13,
  caption: 12,
  micro: 11,
  nano: 10,
} as const

/**
 * Dynamic Type caps per role: body copy grows the most, large display type the least, so the
 * accessibility sizes stay readable without blowing headers, chips and tab labels off-screen.
 */
export const fontScaleCaps = {
  display: 1.3,
  title: 1.4,
  body: 1.8,
  meta: 1.6,
  label: 1.5,
  control: 1.35,
  tab: 1.2,
} as const

export const iconSizes = {
  sm: 16,
  md: 20,
  lg: 24,
  xl: 32,
} as const

export const typography = {
  displayTitle: {
    fontFamily: fonts.display,
    fontSize: 28,
    color: colors.black,
  } satisfies TextStyle,
  sectionTitle: {
    fontFamily: fonts.displaySemi,
    fontSize: 18,
    color: colors.black,
  } satisfies TextStyle,
  eyebrow: {
    fontFamily: fonts.bodySemi,
    fontSize: 12,
    letterSpacing: 0.6,
    textTransform: "uppercase",
    color: colors.gray500,
  } satisfies TextStyle,
  body: {
    fontFamily: fonts.body,
    fontSize: 14,
    color: colors.gray700,
    lineHeight: 20,
  } satisfies TextStyle,
  meta: {
    fontFamily: fonts.body,
    fontSize: 13,
    color: colors.gray500,
    lineHeight: 18,
  } satisfies TextStyle,
  label: {
    fontFamily: fonts.bodySemi,
    fontSize: 14,
    color: colors.black,
    lineHeight: 20,
  } satisfies TextStyle,
  caption: {
    fontFamily: fonts.bodySemi,
    fontSize: 11,
    color: colors.gray500,
    lineHeight: 14,
  } satisfies TextStyle,
} as const

export type StatusTone = "review" | "due" | "approved" | "paid" | "waitlist" | "rejected" | "neutral"

export const statusToneColors: Record<StatusTone, { bg: string; text: string; border: string }> = {
  review: { bg: colors.amber50, text: colors.amber700, border: colors.amber200 },
  due: { bg: colors.amber50, text: colors.amber700, border: colors.amber200 },
  approved: { bg: colors.limeMuted, text: colors.gray900, border: colors.lime },
  paid: { bg: colors.green50, text: colors.green700, border: colors.green200 },
  waitlist: { bg: colors.gray100, text: colors.gray700, border: colors.gray200 },
  rejected: { bg: colors.red50, text: colors.red600, border: colors.red200 },
  neutral: { bg: colors.gray100, text: colors.gray700, border: colors.gray200 },
}
