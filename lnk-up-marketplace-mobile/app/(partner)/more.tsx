import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { DeleteAccountCard } from "@/components/delete-account-card"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { Screen } from "@/components/screen"
import { WorkspaceSwitchGroup } from "@/components/workspace-switch-group"
import { AppText as Text } from "@/components/ui/app-text"
import { type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Eyebrow } from "@/components/ui/typography"
import { getPartnerDashboardUrl, getPrivacyPolicyUrl, getTermsOfServiceUrl } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { useSignOut } from "@/lib/hooks/use-account-actions"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type MoreLink = {
  title: string
  meta: string
  icon: AppIconName
  href?: Href
  url?: () => string
  external?: string
}

const SECTIONS: ReadonlyArray<{ title: string; links: MoreLink[] }> = [
  {
    title: "Notifications",
    links: [
      {
        title: "Notification inbox",
        meta: "Referrals, leads, commissions and payouts",
        icon: "notifications-outline",
        href: "/(partner)/notifications",
      },
    ],
  },
  {
    title: "Program",
    links: [
      {
        title: "Content and outreach",
        meta: "Scripts, resources and profile on the web",
        icon: "open-outline",
        url: () => getPartnerDashboardUrl(),
        external: "partner dashboard",
      },
      {
        title: "Terms of service",
        meta: "LNKup marketplace terms",
        icon: "document-text-outline",
        url: getTermsOfServiceUrl,
        external: "terms of service",
      },
      {
        title: "Privacy policy",
        meta: "How LNKup handles your data",
        icon: "lock-closed-outline",
        url: getPrivacyPolicyUrl,
        external: "privacy policy",
      },
    ],
  },
]

function openLink(link: MoreLink): void {
  if (link.href) {
    router.push(link.href)
    return
  }
  if (link.url) void openExternalUrl(link.url(), link.external)
}

export default function PartnerMoreScreen() {
  const { session } = useAuth()
  const [signOut, signingOut] = useSignOut()

  return (
    <Screen title="More" subtitle="Notifications, program and account">
      <View style={styles.identity}>
        <Eyebrow style={styles.eyebrow}>Partner account</Eyebrow>
        <Text style={styles.name} numberOfLines={2}>
          {session?.user.name ?? "Partner"}
        </Text>
        <Text style={styles.handle}>@{session?.username}</Text>
      </View>

      {SECTIONS.map((section) => (
        <View key={section.title} style={styles.section}>
          <ListGroup title={section.title}>
            {section.links.map((link) => (
              <ListRow
                key={link.title}
                icon={link.icon}
                title={link.title}
                meta={link.meta}
                onPress={() => openLink(link)}
                accessibilityHint={link.url ? "Opens in your browser" : undefined}
              />
            ))}
          </ListGroup>
          {section.title === "Notifications" ? (
            <PushPreferencesCard settingsHref="/(partner)/notification-settings" />
          ) : null}
        </View>
      ))}

      <WorkspaceSwitchGroup current="partner" title="Account" />

      <Button
        label="Sign out"
        variant="danger"
        icon="log-out-outline"
        loading={signingOut}
        onPress={() => void signOut()}
        accessibilityLabel="Sign out"
        testID="partner-more-sign-out"
      />

      <DeleteAccountCard />
    </Screen>
  )
}

const styles = StyleSheet.create({
  identity: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    borderTopWidth: 3,
    borderTopColor: colors.lime,
    padding: spacing.lg,
    gap: 2,
  },
  eyebrow: { color: colors.lime },
  name: { fontFamily: fonts.display, fontSize: typeScale.headline, lineHeight: 26, color: colors.white },
  handle: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray200 },
  section: { gap: spacing.sm },
})
