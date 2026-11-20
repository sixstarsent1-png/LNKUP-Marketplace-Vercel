import { useState, type ReactNode } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import Animated, { FadeIn, FadeOut, useReducedMotion } from "react-native-reanimated"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { colors, layout, radii, spacing, typeScale } from "@/lib/theme"

type CollapsibleSectionProps = {
  title: string
  subtitle?: string
  icon?: AppIconName
  defaultOpen?: boolean
  children: ReactNode
}

/** Card section whose body can be expanded/collapsed to shorten long edit screens. */
export function CollapsibleSection({
  title,
  subtitle,
  icon,
  defaultOpen = false,
  children,
}: CollapsibleSectionProps) {
  const [open, setOpen] = useState(defaultOpen)
  const reduceMotion = useReducedMotion()

  return (
    <View style={styles.card}>
      <Pressable
        onPress={() => setOpen((v) => !v)}
        accessibilityRole="button"
        accessibilityLabel={title}
        accessibilityHint={open ? "Collapse section" : "Expand section"}
        accessibilityState={{ expanded: open }}
        style={({ pressed }) => [styles.header, pressed && styles.pressed]}
      >
        {icon ? (
          <View style={styles.icon}>
            <AppIcon name={icon} size={18} color={colors.black} />
          </View>
        ) : null}
        <View style={styles.headerText}>
          <SectionTitle style={styles.title}>{title}</SectionTitle>
          {subtitle ? <Meta numberOfLines={2}>{subtitle}</Meta> : null}
        </View>
        <AppIcon name={open ? "chevron-up" : "chevron-down"} size={20} color={colors.gray500} />
      </Pressable>
      {open ? (
        <Animated.View
          entering={reduceMotion ? undefined : FadeIn.duration(180)}
          exiting={reduceMotion ? undefined : FadeOut.duration(120)}
          style={styles.body}
        >
          {children}
        </Animated.View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 12,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  icon: {
    width: 36,
    height: 36,
    borderRadius: radii.md,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
  },
  headerText: {
    flex: 1,
    gap: 2,
  },
  title: {
    fontSize: typeScale.bodyLarge,
  },
  body: {
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.lg,
    borderTopWidth: 1,
    borderTopColor: colors.gray100,
    paddingTop: spacing.md,
  },
})
