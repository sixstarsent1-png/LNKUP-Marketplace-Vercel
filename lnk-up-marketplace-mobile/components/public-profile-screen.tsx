import { useCallback, useEffect, useState } from "react"
import { Image, Pressable, ScrollView, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ProfileMark } from "@/components/profile-mark"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import type { PublicProfile } from "@/lib/api/public-profiles"
import { useAuth } from "@/lib/auth/auth-context"
import { loadHomePreference } from "@/lib/auth/roles"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, radii, spacing } from "@/lib/theme"

type PublicProfileScreenProps = {
  title: string
  slug?: string
  websiteUrl: string
  load: (slug: string) => Promise<PublicProfile>
}

export function PublicProfileScreen({ title, slug, websiteUrl, load }: PublicProfileScreenProps) {
  const { isAuthenticated, isOrganizer } = useAuth()
  const [loading, setLoading] = useState(Boolean(slug))
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [profile, setProfile] = useState<PublicProfile | null>(null)

  const refresh = useCallback(async () => {
    if (!slug) {
      setLoading(false)
      setProfile(null)
      return
    }
    setLoading(true)
    setError(null)
    try {
      setProfile(await load(slug))
    } catch (err) {
      setProfile(null)
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [load, slug])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function handleBack() {
    if (router.canGoBack()) {
      router.back()
      return
    }
    if (!isAuthenticated) {
      router.replace("/")
      return
    }
    const pref = await loadHomePreference()
    if (pref === "vendor") {
      router.replace("/(vendor)")
      return
    }
    if (pref === "organizer") {
      router.replace("/(organizer)")
      return
    }
    router.replace(isOrganizer ? "/(organizer)" : "/(vendor)")
  }

  const place = [profile?.city, profile?.state].filter(Boolean).join(", ")

  return (
    <SafeAreaView style={styles.safe} edges={["top"]}>
      <View style={styles.header}>
        <Pressable onPress={() => void handleBack()} accessibilityRole="button">
          <Text style={styles.back}>Back</Text>
        </Pressable>
        <Text style={styles.headerTitle}>{title}</Text>
        <View style={styles.backSpacer} />
      </View>
      <ScrollView contentContainerStyle={styles.body}>
        {!slug ? (
          <ErrorPanel
            title="Profile link incomplete"
            message="This page needs a public profile link. Open it on the website if the app does not have the full address."
            actionLabel="Open website"
            onAction={() => void openExternalUrl(websiteUrl, "public profile")}
          />
        ) : null}
        {slug && loading ? <LoadingPanel label="Loading profile…" /> : null}
        {slug && !loading && error ? (
          <ErrorPanel
            title={error.title}
            message={error.message}
            actionLabel="Try again"
            onAction={() => void refresh()}
          />
        ) : null}
        {profile ? (
          <View style={styles.content}>
            {profile.coverUrl ? (
              <Image source={{ uri: profile.coverUrl }} style={styles.cover} accessibilityLabel="" />
            ) : null}
            <ProfileMark name={profile.name} logoUrl={profile.logoUrl} size={84} />
            <Text style={styles.name}>{profile.name}</Text>
            {profile.tagline ? <Text style={styles.tagline}>{profile.tagline}</Text> : null}
            <Text style={styles.meta}>City: {place || "City not set"}</Text>
            <Text style={styles.meta}>
              Category: {profile.categories.length ? profile.categories.join(", ") : "Category not set"}
            </Text>
            {profile.markets ? <Text style={styles.meta}>Markets: {profile.markets}</Text> : null}
            {profile.bio ? <Text style={styles.bio}>{profile.bio}</Text> : null}
            {profile.website ? <Text style={styles.meta}>{profile.website}</Text> : null}
            {profile.instagram ? <Text style={styles.meta}>{profile.instagram}</Text> : null}
          </View>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  back: {
    minWidth: 56,
    fontWeight: "700",
    color: colors.black,
  },
  backSpacer: { minWidth: 56 },
  headerTitle: {
    flex: 1,
    textAlign: "center",
    fontWeight: "700",
    fontSize: 16,
    color: colors.black,
  },
  body: {
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
  },
  content: {
    gap: spacing.sm,
  },
  cover: {
    width: "100%",
    height: 140,
    borderRadius: radii.lg,
    backgroundColor: colors.gray100,
  },
  name: {
    fontSize: 28,
    fontWeight: "800",
    color: colors.black,
  },
  tagline: {
    fontSize: 16,
    color: colors.gray700,
  },
  meta: {
    fontSize: 15,
    lineHeight: 22,
    color: colors.gray700,
  },
  bio: {
    marginTop: spacing.sm,
    fontSize: 16,
    lineHeight: 24,
    color: colors.black,
  },
})
