import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import { Screen } from "@/components/screen"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { DeleteAccountCard } from "@/components/delete-account-card"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { PressableScale } from "@/components/ui/pressable-scale"
import { Eyebrow } from "@/components/ui/typography"
import {
  getOrganizerDashboardUrl,
  getPrivacyPolicyUrl,
  getTermsOfServiceUrl,
} from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { useSignOut } from "@/lib/hooks/use-account-actions"
import { WorkspaceSwitchGroup } from "@/components/workspace-switch-group"
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

type MoreSection = {
  title: string
  links: MoreLink[]
}

const SECTIONS: MoreSection[] = [
  {
    title: "Organization",
    links: [
      {
        title: "Public profile",
        meta: "Name, logo, and how vendors see you",
        icon: "business-outline",
        href: "/(organizer)/profile",
      },
      {
        title: "Referral invites",
        meta: "Earn LNKup credit by inviting organizers",
        icon: "gift-outline",
        href: "/(organizer)/referrals",
      },
    ],
  },
  {
    title: "Event tools",
    links: [
      {
        title: "Create event",
        meta: "New event and vendor spots",
        icon: "add-circle-outline",
        href: "/(organizer)/launch",
      },
      {
        title: "Your events",
        meta: "Spots, applications, packets, and event day",
        icon: "calendar-outline",
        href: "/(organizer)/events",
      },
      {
        title: "Vendors",
        meta: "Requests, invites, and discovery",
        icon: "people-outline",
        href: "/(organizer)/vendors",
      },
    ],
  },
  {
    title: "Payments",
    links: [
      {
        title: "Money",
        meta: "Balances, cash outs, and payout setup",
        icon: "wallet-outline",
        href: "/(organizer)/money",
      },
    ],
  },
  {
    title: "Notifications",
    links: [
      {
        title: "Notification inbox",
        meta: "Applications, payments, and reminders",
        icon: "notifications-outline",
        href: "/(organizer)/notifications",
      },
    ],
  },
  {
    title: "Help",
    links: [
      {
        title: "Organizer dashboard",
        meta: "Maps, vendor lists, and bulk tools on the web",
        icon: "open-outline",
        url: getOrganizerDashboardUrl,
        external: "organizer dashboard",
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

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return (parts[0]?.[0] ?? "L").concat(parts[1]?.[0] ?? "").toUpperCase()
}

function openLink(link: MoreLink): void {
  if (link.href) {
    router.push(link.href)
    return
  }
  if (link.url) void openExternalUrl(link.url(), link.external)
}

export default function OrganizerMoreScreen() {
  const { session } = useAuth()
  const [signOut, signingOut] = useSignOut()
  const displayName = session?.user.name ?? "Organizer"

  return (
    <Screen title="More" subtitle="Your organization, tools, and account">
      <PressableScale
        style={styles.profileCard}
        onPress={() => router.push("/(organizer)/profile" as Href)}
        accessibilityRole="button"
        accessibilityLabel={`Public profile for ${displayName}. Edit profile`}
      >
        <View style={styles.identityRow}>
          <View style={styles.avatar} accessibilityElementsHidden>
            <Text style={styles.avatarText}>{initials(displayName)}</Text>
          </View>
          <View style={styles.identityText}>
            <Eyebrow style={styles.profileEyebrow}>Public profile</Eyebrow>
            <Text style={styles.profileName} numberOfLines={2}>
              {displayName}
            </Text>
            <Text style={styles.profileHandle}>@{session?.username}</Text>
          </View>
        </View>
        <View style={styles.profileCtaRow}>
          <Text style={styles.profileCta}>Edit profile</Text>
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

      <Button
        label="Sign out"
        variant="danger"
        icon="log-out-outline"
        loading={signingOut}
        onPress={() => void signOut()}
        accessibilityLabel="Sign out"
        testID="organizer-more-sign-out"
      />

      <DeleteAccountCard />
    </Screen>
  )
}

const styles = StyleSheet.create({
  profileCard: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    borderTopWidth: 3,
    borderTopColor: colors.lime,
    padding: spacing.lg,
    gap: spacing.md,
  },
  identityRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  avatar: {
    width: 52,
    height: 52,
    borderRadius: radii.pill,
    backgroundColor: colors.lime,
    alignItems: "center",
    justifyContent: "center",
  },
  avatarText: {
    fontFamily: fonts.display,
    fontSize: typeScale.headline,
    color: colors.black,
  },
  identityText: {
    flex: 1,
    gap: 2,
  },
  profileEyebrow: {
    color: colors.lime,
  },
  profileName: {
    fontFamily: fonts.display,
    fontSize: typeScale.headline,
    lineHeight: 26,
    color: colors.white,
  },
  profileHandle: {
    fontFamily: fonts.body,
    fontSize: typeScale.footnote,
    color: colors.gray200,
  },
  profileCtaRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
  },
  profileCta: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.callout,
    color: colors.lime,
  },
  section: {
    gap: spacing.md,
  },
})
