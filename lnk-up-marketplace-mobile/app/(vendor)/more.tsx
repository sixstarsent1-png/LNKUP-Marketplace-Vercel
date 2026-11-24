import { useCallback, useEffect, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { Screen } from "@/components/screen"
import { DeleteAccountCard } from "@/components/delete-account-card"
import { PushPreferencesCard } from "@/components/push-preferences-card"
import { WorkspaceSwitchGroup } from "@/components/workspace-switch-group"
import { type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { getVendorPublicProfileUrl } from "@/lib/api/config"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorProfileEdit, type VendorProfileEdit } from "@/lib/api/vendor-profile"
import { fetchVendorReadiness, type VendorReadiness } from "@/lib/api/vendor-readiness"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { useSignOut } from "@/lib/hooks/use-account-actions"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

type LinkItem = { label: string; hint: string; href: string; icon: AppIconName }

const SECTIONS: Array<{ title: string; links: LinkItem[] }> = [
  {
    title: "Your business",
    links: [
      { label: "Vendor Passport", hint: "What organizers see: portfolio, history, reviews", href: "/(vendor)/passport", icon: "id-card-outline" },
      { label: "Edit business profile", hint: "Name, categories, contact, logo", href: "/(vendor)/profile", icon: "storefront-outline" },
      { label: "Documents", hint: "Insurance, permits and licenses", href: "/(vendor)/documents", icon: "document-text-outline" },
      { label: "Reviews", hint: "Ratings from organizers", href: "/(vendor)/reviews", icon: "star-outline" },
    ],
  },
  {
    title: "Your activity",
    links: [
      { label: "Event prep & passes", hint: "Booth, arrival and check-in", href: "/(vendor)/operations", icon: "ticket-outline" },
      { label: "Saved events", hint: "Events you saved", href: "/(vendor)/favorites", icon: "heart-outline" },
      { label: "Saved searches", hint: "Alerts for new matches", href: "/(vendor)/saved-searches", icon: "search-outline" },
      { label: "Invitations", hint: "Organizers who invited you", href: "/(vendor)/invitations", icon: "mail-open-outline" },
    ],
  },
  {
    title: "Grow your business",
    links: [
      { label: "Growth", hint: "Applications, bookings and markets", href: "/(vendor)/growth", icon: "trending-up-outline" },
      { label: "Referral invites", hint: "Earn LNKup credit toward booth fees", href: "/(vendor)/referrals", icon: "gift-outline" },
    ],
  },
]

/**
 * Profile hub: the public business identity on top (no private account details in it), business
 * tools grouped by purpose, then account settings.
 */
export default function VendorMoreScreen() {
  const { session, auth } = useAuth()
  const [signOut, signingOut] = useSignOut()
  const [refreshing, setRefreshing] = useState(false)
  const [loadError, setLoadError] = useState<AppErrorShape | null>(null)
  const [profile, setProfile] = useState<VendorProfileEdit | null>(null)
  const [readiness, setReadiness] = useState<VendorReadiness | null>(null)

  const load = useCallback(
    async (force = false) => {
      if (force) setRefreshing(true)
      setLoadError(null)
      try {
        const [p, r] = await Promise.all([fetchVendorProfileEdit(auth), fetchVendorReadiness(auth, { force }).catch(() => null)])
        setProfile(p)
        setReadiness(r)
      } catch (err) {
        setLoadError(appErrorFromWp(err))
      } finally {
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load()
  }, [load])

  const displayName = profile?.businessName || "Your business"
  const place = [profile?.city, profile?.state].filter(Boolean).join(", ")
  const completion = readiness ? Math.round(readiness.profileCompletion) : null

  function handleViewProfile() {
    const slug = profile?.publicSlug
    if (!slug) {
      router.push("/(vendor)/profile")
      return
    }
    router.push(`/storefront/vendor?vendor=${encodeURIComponent(slug)}` as Href)
  }

  function handleShareProfile() {
    const slug = profile?.publicSlug
    const url = profile?.publicUrl || (slug ? getVendorPublicProfileUrl(slug) : "")
    if (!url) {
      showToast("Add your business name in your profile before sharing.", "info")
      return
    }
    void Share.share({ message: `Check out ${displayName} on LNKup\n${url}`, url })
  }

  return (
    <Screen title="Profile" refreshing={refreshing} onRefresh={() => void load(true)}>
      {loadError ? <InlineBanner tone="error" message={`Your profile didn't load. ${loadError.message}`} actionLabel="Retry" onAction={() => void load(true)} /> : null}

      <View style={styles.identityCard} testID="vendor-business-identity">
        <View style={styles.identityRow}>
          {profile?.logoUrl ? (
            <EventImage uri={profile.logoUrl} height={60} aspectRatio={1} radius={radii.pill} style={styles.logo} fit="contain" background={colors.white} />
          ) : (
            <View style={styles.avatar} accessibilityElementsHidden>
              <Text style={styles.avatarText}>{initials(displayName)}</Text>
            </View>
          )}
          <View style={styles.identityText}>
            <Text style={styles.name} numberOfLines={2}>
              {displayName}
            </Text>
            <Text style={styles.meta} numberOfLines={1}>
              {[profile?.categories?.[0], place].filter(Boolean).join(" · ") || "Add your category and city"}
            </Text>
            {completion != null ? (
              <Text style={styles.completion}>Profile {completion}% complete</Text>
            ) : null}
          </View>
        </View>
        <View style={styles.actions}>
          <Button label="View public profile" variant="accent" icon="eye-outline" style={styles.flex} onPress={handleViewProfile} />
          <Button label="Share" variant="secondary" icon="share-outline" onPress={handleShareProfile} accessibilityLabel="Share your vendor profile link" />
        </View>
        <View style={styles.actions}>
          <Button label="Edit business" variant="secondary" icon="create-outline" style={styles.flex} onPress={() => router.push("/(vendor)/profile")} />
          <Button label="Passport" variant="secondary" icon="id-card-outline" style={styles.flex} onPress={() => router.push("/(vendor)/passport")} />
        </View>
      </View>

      {SECTIONS.map((section) => (
        <ListGroup key={section.title} title={section.title}>
          {section.links.map((link) => (
            <ListRow key={link.href} icon={link.icon} title={link.label} meta={link.hint} onPress={() => router.push(link.href as Href)} />
          ))}
        </ListGroup>
      ))}

      <WorkspaceSwitchGroup current="vendor" />

      <ListGroup title="Account">
        <ListRow icon="person-outline" title="Signed in" meta={`${session?.user.name ?? "Vendor"} · @${session?.username ?? ""}`} />
        <ListRow icon="notifications-outline" title="Notifications" meta="Applications, payments, documents" onPress={() => router.push("/(vendor)/notifications" as Href)} />
      </ListGroup>

      <PushPreferencesCard settingsHref="/(vendor)/notification-settings" />

      <Button label="Sign out" variant="danger" icon="log-out-outline" loading={signingOut} onPress={() => void signOut()} accessibilityLabel="Sign out" testID="vendor-more-sign-out" />

      <DeleteAccountCard />
    </Screen>
  )
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return (parts[0]?.[0] ?? "L").concat(parts[1]?.[0] ?? "").toUpperCase()
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  identityCard: { backgroundColor: colors.shell, borderRadius: radii.lg, padding: spacing.lg, gap: spacing.md },
  identityRow: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  logo: { width: 60 },
  avatar: { width: 60, height: 60, borderRadius: radii.pill, backgroundColor: colors.lime, alignItems: "center", justifyContent: "center" },
  avatarText: { fontFamily: fonts.display, fontSize: typeScale.headline, color: colors.black },
  identityText: { flex: 1, gap: 2 },
  name: { fontFamily: fonts.display, fontSize: typeScale.headline + 1, lineHeight: 24, color: colors.white },
  meta: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray200 },
  completion: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.lime },
  actions: { flexDirection: "row", gap: spacing.sm },
})
