import { ActivityIndicator, StyleSheet, View } from "react-native"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { SkeletonList } from "@/components/ui/skeleton"
import { Surface } from "@/components/ui/surface"
import { Body, SectionTitle } from "@/components/ui/typography"
import { colors, radii, spacing } from "@/lib/theme"

type PanelProps = {
  title: string
  message: string
  actionLabel?: string
  onAction?: () => void
  secondaryActionLabel?: string
  onSecondaryAction?: () => void
  icon?: AppIconName
}

export function LoadingPanel({
  label = "Loading…",
  variant = "spinner",
  count = 3,
  withMedia = false,
}: {
  label?: string
  variant?: "spinner" | "skeleton"
  count?: number
  withMedia?: boolean
}) {
  if (variant === "skeleton") {
    return <SkeletonList count={count} withMedia={withMedia} label={label} />
  }
  return (
    <Surface variant="hero" style={styles.panel} accessibilityRole="progressbar" accessibilityLabel={label}>
      <ActivityIndicator color={colors.black} />
      <Body style={styles.message}>{label}</Body>
    </Surface>
  )
}

export function EmptyPanel({
  title,
  message,
  actionLabel,
  onAction,
  secondaryActionLabel,
  onSecondaryAction,
  icon = "sparkles-outline",
}: PanelProps) {
  return (
    <Surface variant="hero" style={styles.panel}>
      <View style={styles.iconBubble}>
        <AppIcon name={icon} size={22} color={colors.black} />
      </View>
      <SectionTitle style={styles.title} accessibilityRole="header">
        {title}
      </SectionTitle>
      <Body style={styles.message}>{message}</Body>
      {actionLabel && onAction ? (
        <Button label={actionLabel} onPress={onAction} style={styles.button} />
      ) : null}
      {secondaryActionLabel && onSecondaryAction ? (
        <Button label={secondaryActionLabel} onPress={onSecondaryAction} variant="ghost" size="sm" />
      ) : null}
    </Surface>
  )
}

export function ErrorPanel({
  title,
  message,
  actionLabel = "Try again",
  onAction,
  icon = "cloud-offline-outline",
}: PanelProps) {
  return (
    <Surface variant="urgent" style={styles.panel} accessibilityRole="alert">
      <View style={[styles.iconBubble, styles.iconBubbleUrgent]}>
        <AppIcon name={icon} size={22} color={colors.amber700} />
      </View>
      <SectionTitle style={styles.title} accessibilityRole="header">
        {title}
      </SectionTitle>
      <Body style={styles.message}>{message}</Body>
      {onAction ? (
        <Button label={actionLabel} onPress={onAction} icon="refresh" style={styles.button} />
      ) : null}
    </Surface>
  )
}

const styles = StyleSheet.create({
  panel: {
    alignItems: "center",
    padding: spacing.xl,
  },
  iconBubble: {
    width: 44,
    height: 44,
    borderRadius: radii.pill,
    backgroundColor: colors.limeMuted,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: spacing.xs,
  },
  iconBubbleUrgent: {
    backgroundColor: colors.white,
  },
  title: {
    fontSize: 16,
    textAlign: "center",
  },
  message: {
    textAlign: "center",
  },
  button: {
    marginTop: spacing.sm,
  },
})
