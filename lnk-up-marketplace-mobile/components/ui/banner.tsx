import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { colors, fonts, layout, radii, spacing, typography } from "@/lib/theme"

export type BannerTone = "attention" | "error" | "success" | "info"

type InlineBannerProps = {
  tone?: BannerTone
  title?: string
  message: string
  actionLabel?: string
  onAction?: () => void
  onDismiss?: () => void
}

const toneConfig: Record<BannerTone, { bg: string; border: string; text: string; icon: AppIconName }> = {
  attention: { bg: colors.amber50, border: colors.amber200, text: colors.amber700, icon: "alert-circle" },
  error: { bg: colors.red50, border: colors.red200, text: colors.red600, icon: "cloud-offline-outline" },
  success: { bg: colors.green50, border: colors.green200, text: colors.green700, icon: "checkmark-circle" },
  info: { bg: colors.gray50, border: colors.gray200, text: colors.gray700, icon: "information-circle-outline" },
}

/** Compact inline banner for refresh failures, payment-due notices, and confirmations. */
export function InlineBanner({
  tone = "info",
  title,
  message,
  actionLabel,
  onAction,
  onDismiss,
}: InlineBannerProps) {
  const config = toneConfig[tone]
  const isActionable = Boolean(onAction)

  const body = (
    <>
      <AppIcon name={config.icon} size={20} color={config.text} />
      <View style={styles.text}>
        {title ? <Text style={[styles.title, { color: config.text }]}>{title}</Text> : null}
        <Text style={styles.message}>{message}</Text>
      </View>
      {actionLabel && isActionable ? (
        <Text style={[styles.action, { color: config.text }]}>{actionLabel}</Text>
      ) : null}
      {onDismiss ? (
        <Pressable
          onPress={onDismiss}
          accessibilityRole="button"
          accessibilityLabel="Dismiss"
          hitSlop={12}
          style={styles.dismiss}
        >
          <AppIcon name="close" size={18} color={colors.gray500} />
        </Pressable>
      ) : null}
    </>
  )

  const containerStyle = [styles.banner, { backgroundColor: config.bg, borderColor: config.border }]

  if (isActionable) {
    return (
      <Pressable
        onPress={onAction}
        accessibilityRole="button"
        accessibilityLabel={[title, message, actionLabel].filter(Boolean).join(". ")}
        style={({ pressed }) => [...containerStyle, pressed && styles.pressed]}
      >
        {body}
      </Pressable>
    )
  }

  return (
    <View style={containerStyle} accessibilityRole={tone === "error" ? "alert" : undefined}>
      {body}
    </View>
  )
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    borderRadius: radii.md,
    borderWidth: 1,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    fontFamily: fonts.bodySemi,
    fontSize: 14,
  },
  message: {
    ...typography.meta,
    color: colors.gray700,
  },
  action: {
    fontFamily: fonts.bodySemi,
    fontSize: 13,
  },
  dismiss: {
    width: 28,
    height: 28,
    alignItems: "center",
    justifyContent: "center",
  },
  pressed: {
    opacity: 0.85,
  },
})
