import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, useFocusEffect, type Href } from "expo-router"

import { DeleteAccountCard } from "@/components/delete-account-card"
import { ProfileMark } from "@/components/profile-mark"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { Screen } from "@/components/screen"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { PressableScale } from "@/components/ui/pressable-scale"
import { Eyebrow } from "@/components/ui/typography"
import { WorkspaceSwitchGroup } from "@/components/workspace-switch-group"
import { getOrganizerDashboardUrl, getPrivacyPolicyUrl, getTermsOfServiceUrl } from "@/lib/api/config"
import { fetchOrganizerProfileEdit } from "@/lib/api/organizer-profile"
import { useAuth } from "@/lib/auth/auth-context"
import { useSignOut } from "@/lib/hooks/use-account-actions"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type MoreLink = {
  title: string
  meta: string
  icon: AppIconName
  /** In-app route, or an external URL opened in the browser. */
  href?: Href
  url?: () => string
  external?: string
}

type MoreSection = { title: string; links: MoreLink[] }

/** Tabs already cover events, vendors and money; More holds the organization and account. */
const SECTIONS: MoreSection[] = [
  {
    title: "Organization",
    links: [
      { title: "Public profile", meta: "Name, logo, and how vendors see you", icon: "business-outline", href: "/(organizer)/profile" },
      { title: "Team", meta: "Organization members and event staff", icon: "people-circle-outline", href: "/(organizer)/team" as Href },
    ],
  },
  {
    title: "Business tools",
    links: [
      { title: "Tours and series", meta: "Events you run in several cities or dates", icon: "git-network-outline", href: "/(organizer)/tours" as Href },
      { title: "Referral invites", meta: "Earn a share of LNKup’s fee as credit", icon: "gift-outline", href: "/(organizer)/referrals" },
      {
        title: "Reports, maps and bulk tools",
        meta: "Desktop-first tools on LNKup web",
        icon: "open-outline",
        url: getOrganizerDashboardUrl,
        external: "organizer dashboard",
      },
    ],
  },
  {
    title: "Notifications",
    links: [{ title: "Notification inbox", meta: "Applications, payments, and reminders", icon: "notifications-outline", href: "/(organizer)/notifications" }],
  },
]

const HELP: MoreLink[] = [
  { title: "Terms of service", meta: "LNKup marketplace terms", icon: "document-text-outline", url: getTermsOfServiceUrl, external: "terms of service" },
  { title: "Privacy policy", meta: "How LNKup handles your data", icon: "lock-closed-outline", url: getPrivacyPolicyUrl, external: "privacy policy" },
]

function openLink(link: MoreLink): void {
  if (link.href) {
    router.push(link.href)
    return
  }
  if (link.url) void openExternalUrl(link.url(), link.external)
}

export default function OrganizerMoreScreen() {
  const { session, auth } = useAuth()
  const [signOut, signingOut] = useSignOut()
  const [org, setOrg] = useState<{ name: string; logoUrl: string } | null>(null)

  useFocusEffect(
    useCallback(() => {
      if (!auth) return
      void fetchOrganizerProfileEdit(auth)
        .then((profile) => setOrg({ name: profile.publicName.trim(), logoUrl: profile.logoUrl }))
        .catch(() => undefined)
    }, [auth]),
  )

  const orgName = org?.name || session?.user.name || "Your organization"

  return (
    <Screen title="More" subtitle="Your organization, tools, and account">
      <PressableScale
        style={styles.hq}
        onPress={() => router.push("/(organizer)/profile" as Href)}
        accessibilityRole="button"
        accessibilityLabel={`${orgName}. Edit public profile`}
        testID="organizer-hq"
      >
        <View style={styles.identityRow}>
          <ProfileMark name={orgName} logoUrl={org?.logoUrl || undefined} size={52} />
          <View style={styles.identityText}>
            <Eyebrow style={styles.eyebrow}>Organization</Eyebrow>
            <Text style={styles.orgName} numberOfLines={2}>
              {orgName}
            </Text>
            {!org?.name ? <Text style={styles.hint}>Add your public name so vendors recognize you</Text> : null}
          </View>
        </View>
        <View style={styles.ctaRow}>
          <Text style={styles.cta}>Edit public profile</Text>
          <AppIcon name="arrow-forward" size={16} color={colors.lime} />
        </View>
      </PressableScale>

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
          {section.title === "Notifications" ? <PushPreferencesCard settingsHref="/(organizer)/notification-settings" /> : null}
        </View>
      ))}

      <WorkspaceSwitchGroup current="organizer" title="Account" />

      <ListGroup title="Signed in as">
        <ListRow icon="person-outline" title={session?.user.name ?? "Organizer"} meta={session?.username} showChevron={false} />
      </ListGroup>

      <ListGroup title="Help">
        {HELP.map((link) => (
          <ListRow key={link.title} icon={link.icon} title={link.title} meta={link.meta} onPress={() => openLink(link)} accessibilityHint="Opens in your browser" />
        ))}
      </ListGroup>

      <Button label="Sign out" variant="danger" icon="log-out-outline" loading={signingOut} onPress={() => void signOut()} accessibilityLabel="Sign out" testID="organizer-more-sign-out" />

      <DeleteAccountCard />
    </Screen>
  )
}

const styles = StyleSheet.create({
  hq: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    borderTopWidth: 3,
    borderTopColor: colors.lime,
    padding: spacing.lg,
    gap: spacing.md,
  },
  identityRow: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  identityText: { flex: 1, gap: 2 },
  eyebrow: { color: colors.lime },
  orgName: { fontFamily: fonts.display, fontSize: typeScale.headline, lineHeight: 26, color: colors.white },
  hint: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray200 },
  ctaRow: { flexDirection: "row", alignItems: "center", gap: spacing.xs },
  cta: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.lime },
  section: { gap: spacing.md },
})
