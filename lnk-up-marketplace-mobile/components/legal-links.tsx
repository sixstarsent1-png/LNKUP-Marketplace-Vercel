import { Linking, Pressable, StyleSheet, View } from "react-native"

import { getPrivacyPolicyUrl, getTermsOfServiceUrl } from "@/lib/api/config"
import { colors, fonts, spacing } from "@/lib/theme"
import { Meta } from "@/components/ui/typography"

type LegalLinksProps = {
  prefix?: string
}

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
      <Meta style={styles.text}>
        {prefix}{" "}
        <Meta style={styles.link} onPress={() => void openUrl(getTermsOfServiceUrl())}>
          Terms
        </Meta>{" "}
        and{" "}
        <Meta style={styles.link} onPress={() => void openUrl(getPrivacyPolicyUrl())}>
          Privacy Policy
        </Meta>
        .
      </Meta>
      <View style={styles.row}>
        <Pressable onPress={() => void openUrl(getPrivacyPolicyUrl())} accessibilityRole="link">
          <Meta style={styles.link}>Privacy Policy</Meta>
        </Pressable>
        <Meta style={styles.dot}>·</Meta>
        <Pressable onPress={() => void openUrl(getTermsOfServiceUrl())} accessibilityRole="link">
          <Meta style={styles.link}>Terms</Meta>
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
