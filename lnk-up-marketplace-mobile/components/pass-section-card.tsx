import type { ReactNode } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, radii, spacing } from "@/lib/theme"

export function PassSectionCard({
  title,
  children,
  accent,
}: {
  title: string
  children: ReactNode
  accent?: "lime" | "default"
}) {
  return (
    <View style={[styles.card, accent === "lime" && styles.cardLime]}>
      <Text style={styles.title}>{title}</Text>
      <View style={styles.body}>{children}</View>
    </View>
  )
}

export function PassDetailRow({
  label,
  value,
  last,
}: {
  label: string
  value?: string
  last?: boolean
}) {
  if (!value) return null
  return (
    <View style={[styles.row, !last && styles.rowBorder]}>
      <Text style={styles.label}>{label}</Text>
      <Text style={styles.value}>{value}</Text>
    </View>
  )
}

export function PassBulletList({ items }: { items: string[] }) {
  if (items.length === 0) return null
  return (
    <View style={styles.bullets}>
      {items.map((item) => (
        <Text key={item} style={styles.bullet}>
          · {item}
        </Text>
      ))}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.md,
  },
  cardLime: {
    borderColor: colors.limeDark,
    backgroundColor: colors.gray50,
  },
  title: {
    fontSize: 17,
    fontWeight: "700",
    color: colors.black,
  },
  body: {
    gap: 0,
  },
  row: {
    gap: 2,
    paddingVertical: spacing.sm,
  },
  rowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.gray200,
  },
  label: {
    fontSize: 11,
    fontWeight: "700",
    letterSpacing: 0.4,
    color: colors.gray500,
    textTransform: "uppercase",
  },
  value: {
    fontSize: 15,
    color: colors.black,
    lineHeight: 22,
  },
  bullets: {
    gap: spacing.xs,
    paddingTop: spacing.xs,
  },
  bullet: {
    fontSize: 14,
    color: colors.gray700,
    lineHeight: 20,
  },
})
