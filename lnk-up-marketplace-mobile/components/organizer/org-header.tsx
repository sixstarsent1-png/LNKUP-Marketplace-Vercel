import type { ReactNode } from "react"
import { StyleSheet, View } from "react-native"

import { ProfileMark } from "@/components/profile-mark"
import { AppText as Text } from "@/components/ui/app-text"
import { colors, fonts, fontScaleCaps, spacing, typeScale, typography } from "@/lib/theme"

type OrgHeaderProps = {
  organizationName: string
  logoUrl?: string
  /** Short context line, e.g. "2 events this month". */
  subtitle?: string
  /** Trailing slot (notifications bell). */
  right?: ReactNode
}

/** Organization identity at the top of Home: logo, public name and a context line. */
export function OrgHeader({ organizationName, logoUrl, subtitle, right }: OrgHeaderProps) {
  return (
    <View style={styles.row}>
      <ProfileMark name={organizationName} logoUrl={logoUrl || undefined} size={44} />
      <View style={styles.text}>
        <Text style={styles.name} numberOfLines={1} accessibilityRole="header" maxFontSizeMultiplier={fontScaleCaps.title}>
          {organizationName}
        </Text>
        {subtitle ? (
          <Text style={styles.subtitle} numberOfLines={1}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right}
    </View>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  name: {
    fontFamily: fonts.display,
    fontSize: typeScale.title,
    color: colors.black,
  },
  subtitle: {
    ...typography.meta,
  },
})
