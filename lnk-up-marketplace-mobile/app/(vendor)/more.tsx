import { useCallback, useEffect, useMemo, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import { Screen } from "@/components/screen"
import { DeleteAccountCard } from "@/components/delete-account-card"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Eyebrow, Meta, SectionTitle } from "@/components/ui/typography"
import {
  fetchVendorRequests,
  paymentDueRequests,
  upcomingBookedRequests,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import { getVendorPublicProfileUrl } from "@/lib/api/config"
import { fetchVendorProfileEdit, type VendorProfileEdit } from "@/lib/api/vendor-profile"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { useSignOut } from "@/lib/hooks/use-account-actions"
import { WorkspaceSwitchGroup } from "@/components/workspace-switch-group"
import { InlineBanner } from "@/components/ui/banner"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type LinkItem = {
  label: string
  hint: string
  href: string
  icon: AppIconName
}

type LinkSection = {
  title: string
  links: LinkItem[]
}

const SECTIONS: LinkSection[] = [
  {
    title: "Your business",
    links: [
      {
        label: "Vendor Passport",
        hint: "Portfolio, history, reviews, privacy",
        href: "/(vendor)/passport",
        icon: "id-card-outline",
      },
      {
        label: "Business profile",
        hint: "Name, categories, contact, logo",
        href: "/(vendor)/profile",
        icon: "storefront-outline",
      },
      {
        label: "Documents",
        hint: "Insurance, permits and licenses",
        href: "/(vendor)/documents",
        icon: "document-text-outline",
      },
    ],
  },
  {
    title: "Event day",
    links: [
      {
        label: "Event passes",
        hint: "Booth, arrival and check-in",
        href: "/(vendor)/operations",
        icon: "ticket-outline",
      },
    ],
  },
  {
    title: "Saved",
    links: [
      {
        label: "Saved events",
        hint: "Opportunities you hearted",
        href: "/(vendor)/favorites",
        icon: "heart-outline",
      },
      {
        label: "Saved searches",
        hint: "Alerts for new matches",
        href: "/(vendor)/saved-searches",
        icon: "search-outline",
      },
    ],
  },
  {
    title: "Growth",
    links: [
      {
        label: "Growth",
        hint: "Score and next moves",
        href: "/(vendor)/growth",
        icon: "trending-up-outline",
      },
      {
        label: "Referral Invites",
        hint: "Earn LNKup credit toward booth fees",
        href: "/(vendor)/referrals",
        icon: "gift-outline",
      },
      {
        label: "Reviews",
        hint: "Ratings from organizers",
        href: "/(vendor)/reviews",
        icon: "star-outline",
      },
    ],
  },
  {
    title: "Inbox",
    links: [
      {
        label: "Notifications",
        hint: "Applications, payments, docs",
        href: "/(vendor)/notifications",
        icon: "notifications-outline",
      },
    ],
  },
]

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return (parts[0]?.[0] ?? "L").concat(parts[1]?.[0] ?? "").toUpperCase()
}

export default function VendorMoreScreen() {
  const { session, auth } = useAuth()
  const [signOut, signingOut] = useSignOut()
  const [refreshing, setRefreshing] = useState(false)
  const [loadError, setLoadError] = useState<AppErrorShape | null>(null)
  const [nextPass, setNextPass] = useState<VendorRequest | null>(null)
  const [needsPay, setNeedsPay] = useState(false)
  const [publicProfile, setPublicProfile] = useState<VendorProfileEdit | null>(null)

  const load = useCallback(async (force = false) => {
    if (force) setRefreshing(true)
    setLoadError(null)
    try {
      const [{ items }, profile] = await Promise.all([
        fetchVendorRequests(auth, { force }),
        fetchVendorProfileEdit(auth).catch(() => null),
      ])
      setPublicProfile(profile)
      const due = paymentDueRequests(items, 1)[0]
      const booked = upcomingBookedRequests(items, 1)[0]
      if (due) {
        setNextPass(due)
        setNeedsPay(true)
      } else if (booked) {
        setNextPass(booked)
        setNeedsPay(false)
      } else {
        setNextPass(null)
        setNeedsPay(false)
      }
    } catch (err) {
      setLoadError(appErrorFromWp(err))
    } finally {
      setRefreshing(false)
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  const passMeta = useMemo(() => {
    if (!nextPass) return ""
    return [nextPass.eventDate, nextPass.city].filter(Boolean).join(" · ")
  }, [nextPass])

  const displayName = publicProfile?.businessName || session?.user.name || "Your business"

  function handleViewProfile() {
    const slug = publicProfile?.publicSlug
    if (!slug) {
      router.push("/(vendor)/profile")
      return
    }
    router.push(`/storefront/vendor?vendor=${encodeURIComponent(slug)}` as Href)
  }

  function handleShareProfile() {
    const slug = publicProfile?.publicSlug
    const url = publicProfile?.publicUrl || (slug ? getVendorPublicProfileUrl(slug) : "")
    if (!url) {
      showToast("Add your business name in Profile & settings before sharing.", "info")
      return
    }
    const name = publicProfile?.businessName || session?.user.name || "My LNKup profile"
    void Share.share({ message: `Check out ${name} on LNKup\n${url}`, url })
  }

  return (
    <Screen
      title="Profile"
      subtitle="Your business, settings and account"
      refreshing={refreshing}
      onRefresh={() => void load(true)}
    >
      {loadError ? (
        <InlineBanner
          tone="error"
          message={`Your bookings didn't load. ${loadError.message}`}
          actionLabel="Retry"
          onAction={() => void load(true)}
        />
      ) : null}
      <View style={styles.profileCard}>
        <View style={styles.identityRow}>
          <View style={styles.avatar} accessibilityElementsHidden>
            <Text style={styles.avatarText}>{initials(displayName)}</Text>
          </View>
          <View style={styles.identityText}>
            <Eyebrow style={styles.profileEyebrow}>Public profile</Eyebrow>
            <Text style={styles.profileName} numberOfLines={2}>
              {displayName}
            </Text>
            <Text style={styles.profileHandle}>
              Signed in as {session?.user.name ?? "Vendor"} · @{session?.username}
            </Text>
          </View>
        </View>
        <Text style={styles.profileHint}>
          This is the page organizers open when you share your vendor link.
        </Text>
        <View style={styles.profileActions}>
          <Button
            label="View profile"
            variant="accent"
            icon="eye-outline"
            style={styles.flex}
            onPress={handleViewProfile}
            accessibilityLabel="View public profile"
          />
          <Button
            label="Share"
            variant="secondary"
            icon="share-outline"
            style={styles.flex}
            onPress={handleShareProfile}
            accessibilityLabel="Share vendor link"
          />
        </View>
      </View>

      {nextPass ? (
        <Surface variant={needsPay ? "urgent" : "hero"} style={styles.passCard}>
          <View style={styles.passHeader}>
            <Eyebrow style={needsPay ? styles.passEyebrowDue : undefined}>
              {needsPay ? "Needs attention" : "Next event pass"}
            </Eyebrow>
            <StatusBadge label={needsPay ? "Payment due" : "Booked"} tone={needsPay ? "due" : "paid"} />
          </View>
          <SectionTitle numberOfLines={2}>{nextPass.eventName}</SectionTitle>
          {passMeta ? <Meta>{passMeta}</Meta> : null}
          <Button
            label={needsPay ? "Pay now" : "Open pass"}
            icon={needsPay ? "card-outline" : "ticket-outline"}
            onPress={() => router.push(`/pass/${nextPass.id}` as Href)}
            accessibilityLabel={`${needsPay ? "Pay now" : "Open pass"}: ${nextPass.eventName}`}
            style={styles.passCta}
          />
        </Surface>
      ) : null}

      <WorkspaceSwitchGroup current="vendor" />

      {SECTIONS.map((section) => (
        <ListGroup key={section.title} title={section.title}>
          {section.links.map((link) => (
            <ListRow
              key={link.href}
              icon={link.icon}
              title={link.label}
              meta={link.hint}
              onPress={() => router.push(link.href as Href)}
            />
          ))}
        </ListGroup>
      ))}

      <PushPreferencesCard settingsHref="/(vendor)/notification-settings" />

      <Button
        label="Sign out"
        variant="danger"
        icon="log-out-outline"
        loading={signingOut}
        onPress={() => void signOut()}
        accessibilityLabel="Sign out"
        testID="vendor-more-sign-out"
      />

      <DeleteAccountCard />
    </Screen>
  )
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
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
  profileHint: {
    fontFamily: fonts.body,
    fontSize: typeScale.body,
    lineHeight: 20,
    color: colors.gray200,
  },
  profileActions: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  passCard: {
    gap: spacing.xs,
  },
  passHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.sm,
  },
  passEyebrowDue: {
    color: colors.amber700,
  },
  passCta: {
    marginTop: spacing.sm,
  },
})
