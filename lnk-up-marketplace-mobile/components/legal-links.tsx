import { Linking, Pressable, StyleSheet, View } from "react-native"

import { getPrivacyPolicyUrl, getTermsOfServiceUrl } from "@/lib/api/config"
import { colors, fonts, spacing } from "@/lib/theme"
import { Meta } from "@/components/ui/typography"

type LegalLinksProps = {
  prefix?: string
}

/** One sentence and one pair of real links (the links were listed twice before). */
export function LegalLinks({ prefix = "By using LNKup you agree to our" }: LegalLinksProps) {
  async function openUrl(url: string) {
    try {
      await Linking.openURL(url)
    } catch {
      return
    }
  }

  return (
    <View style={styles.wrap}>
      <Meta style={styles.text}>{prefix}</Meta>
      <View style={styles.row}>
        <Pressable onPress={() => void openUrl(getTermsOfServiceUrl())} accessibilityRole="link" hitSlop={8}>
          <Meta style={styles.link}>Terms</Meta>
        </Pressable>
        <Meta style={styles.dot}>·</Meta>
        <Pressable onPress={() => void openUrl(getPrivacyPolicyUrl())} accessibilityRole="link" hitSlop={8}>
          <Meta style={styles.link}>Privacy Policy</Meta>
        </Pressable>
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    gap: spacing.xs,
    alignItems: "center",
  },
  text: {
    textAlign: "center",
    color: colors.gray700,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  link: {
    color: colors.black,
    fontFamily: fonts.bodySemi,
    textDecorationLine: "underline",
  },
  dot: {
    color: colors.gray400,
  },
})
