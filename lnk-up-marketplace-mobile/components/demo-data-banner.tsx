import { useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import {
  isFixtureDataActive,
  resetFixtureHonesty,
  subscribeFixtureHonesty,
} from "@/lib/api/fixture-honesty"
import { colors, spacing, typeScale } from "@/lib/theme"

/**
 * Shown when live API mode unexpectedly falls back to demo/fixture data.
 * Hidden in development builds where fixtures are intentional.
 */
export function DemoDataBanner() {
  const [visible, setVisible] = useState(isFixtureDataActive())

  useEffect(() => {
    return subscribeFixtureHonesty(setVisible)
  }, [])

  if (!visible) {
    return null
  }

  return (
    <View style={styles.wrap} accessibilityRole="alert">
      <Text style={styles.title}>Demo data</Text>
      <Text style={styles.body}>
        Live LNKup data could not be loaded. Showing sample data until you refresh or reconnect.
      </Text>
    </View>
  )
}

/** Clear fixture banner state after a successful pull-to-refresh. */
export function clearDemoDataBanner(): void {
  resetFixtureHonesty()
}

const styles = StyleSheet.create({
  wrap: {
    backgroundColor: colors.black,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    gap: 2,
  },
  title: {
    color: colors.lime,
    fontSize: typeScale.caption,
    fontWeight: "800",
    letterSpacing: 0.6,
    textTransform: "uppercase",
  },
  body: {
    color: colors.white,
    fontSize: typeScale.footnote,
    lineHeight: 18,
  },
})
