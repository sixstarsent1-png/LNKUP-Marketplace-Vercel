import { useEffect, useRef, useState } from "react"
import { Platform, Share, StyleSheet, View } from "react-native"

import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { Eyebrow, Meta } from "@/components/ui/typography"
import { copyToClipboard } from "@/lib/clipboard"
import { colors, spacing, typeScale } from "@/lib/theme"

type ShareLink = {
  key: string
  label: string
  hint: string
  url: string
  message: string
}

type ShareLinkCardProps = {
  code: string
  links: ShareLink[]
}

/** Referral code plus tracked share links. Attribution happens server-side from the link's `ref`. */
export function ShareLinkCard({ code, links }: ShareLinkCardProps) {
  const [copied, setCopied] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current)
    },
    [],
  )

  async function handleShare(link: ShareLink) {
    try {
      if (Platform.OS === "ios") {
        await Share.share({ message: link.message, url: link.url })
      } else {
        await Share.share({ message: `${link.message}\n${link.url}` })
      }
    } catch {
      // Share sheet dismissed.
    }
  }

  async function handleCopy(link: ShareLink) {
    if (!(await copyToClipboard(link.url))) return
    setCopied(link.key)
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => setCopied(null), 1600)
  }

  const available = links.filter((link) => link.url)

  return (
    <Surface style={styles.card} testID="partner-share-card">
      <Eyebrow>Your referral code</Eyebrow>
      <Text style={styles.code} selectable testID="partner-referral-code">
        {code || "—"}
      </Text>
      {available.length === 0 ? (
        <Meta>Your share links appear here once LNKup activates your code.</Meta>
      ) : (
        available.map((link) => (
          <View key={link.key} style={styles.link}>
            <Text style={styles.linkLabel}>{link.label}</Text>
            <Meta>{link.hint}</Meta>
            <Text style={styles.url} numberOfLines={2} selectable>
              {link.url}
            </Text>
            <View style={styles.actions}>
              <Button
                label="Share"
                size="sm"
                icon="share-outline"
                style={styles.flex}
                onPress={() => void handleShare(link)}
                accessibilityLabel={`Share ${link.label}`}
                testID={`partner-share-${link.key}`}
              />
              <Button
                label={copied === link.key ? "Copied" : "Copy"}
                size="sm"
                variant="secondary"
                icon="copy-outline"
                style={styles.flex}
                onPress={() => void handleCopy(link)}
                accessibilityLabel={`Copy ${link.label}`}
                testID={`partner-copy-${link.key}`}
              />
            </View>
          </View>
        ))
      )}
    </Surface>
  )
}

const styles = StyleSheet.create({
  card: { gap: spacing.sm },
  code: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.black,
    letterSpacing: 1,
  },
  link: {
    gap: spacing.xs,
    paddingTop: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
  },
  linkLabel: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  url: { fontSize: typeScale.caption, color: colors.gray500 },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.xs },
  flex: { flex: 1 },
})
