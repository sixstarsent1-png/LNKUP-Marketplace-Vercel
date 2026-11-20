import { useCallback, useEffect, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"
import * as DocumentPicker from "expo-document-picker"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ProfileMark } from "@/components/profile-mark"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { FormField } from "@/components/ui/form-field"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchOrganizerProfileEdit,
  updateOrganizerProfileEdit,
  uploadOrganizerLogo,
  type OrganizerProfileEdit,
} from "@/lib/api/organizer-profile"
import { getOrganizerPublicProfileUrl } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"
import { spacing } from "@/lib/theme"

const LOGO_TYPES = ["image/jpeg", "image/png", "image/webp"]
const LOGO_MAX_BYTES = 5 * 1024 * 1024

const FIELDS = [
  ["publicName", "Organization name"],
  ["tagline", "Short intro"],
  ["bio", "About"],
  ["city", "City"],
  ["state", "State"],
  ["serviceCities", "Cities you host in"],
  ["website", "Website"],
  ["instagram", "Instagram"],
] as const

const NOT_READY_MESSAGE = "Save your organization name first so we can create your public link."

export default function OrganizerProfileScreen() {
  const { auth, session } = useAuth()
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [profile, setProfile] = useState<OrganizerProfileEdit | null>(null)
  const [draft, setDraft] = useState<OrganizerProfileEdit | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const next = await fetchOrganizerProfileEdit(auth)
      setProfile(next)
      setDraft(next)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  const [save, saving] = useSingleFlight(async () => {
    if (!draft) return
    setSaveError(null)
    if (!draft.publicName.trim()) {
      setSaveError("Add your organization name.")
      return
    }
    try {
      const saved = await updateOrganizerProfileEdit(draft, auth)
      setProfile(saved)
      setDraft(saved)
      setEditing(false)
      showToast("Your public profile is updated.", "success")
    } catch (err) {
      setSaveError(appErrorFromWp(err).message)
    }
  })

  const [pickLogo, uploadingLogo] = useSingleFlight(async () => {
    try {
      const picked = await DocumentPicker.getDocumentAsync({
        type: LOGO_TYPES,
        copyToCacheDirectory: true,
        multiple: false,
      })
      if (picked.canceled || !picked.assets?.[0]) return
      const asset = picked.assets[0]
      if (asset.mimeType && !LOGO_TYPES.includes(asset.mimeType)) {
        showToast("Choose a JPG, PNG, or WebP image.", "error")
        return
      }
      if (asset.size != null && asset.size > LOGO_MAX_BYTES) {
        showToast("That image is over 5 MB. Choose a smaller one.", "error")
        return
      }
      const uploaded = await uploadOrganizerLogo(
        {
          uri: asset.uri,
          fileName: asset.name || "logo.jpg",
          mimeType: asset.mimeType || "image/jpeg",
        },
        auth,
      )
      const apply = (prev: OrganizerProfileEdit | null) =>
        prev ? { ...prev, logoUrl: uploaded.logoUrl, logoId: uploaded.logoId } : prev
      setProfile(apply)
      setDraft(apply)
      showToast("Logo updated.", "success")
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
  })

  async function handleShare() {
    const slug = profile?.publicSlug || ""
    const url = profile?.publicUrl || (slug ? getOrganizerPublicProfileUrl(slug) : "")
    if (!url) {
      showToast(NOT_READY_MESSAGE, "info")
      return
    }
    const name = profile?.publicName || session?.user.name || "My LNKup profile"
    try {
      await Share.share({ message: `Check out ${name} on LNKup\n${url}`, url })
    } catch {
      // Share sheet dismissed.
    }
  }

  function handleViewPublic() {
    const slug = profile?.publicSlug
    if (!slug) {
      showToast(NOT_READY_MESSAGE, "info")
      return
    }
    router.push(`/storefront/organizer?organizer=${encodeURIComponent(slug)}` as Href)
  }

  return (
    <Screen
      title="Public profile"
      subtitle="How vendors see your organization"
      header={<BackLink label="Account" fallbackHref={"/(organizer)/more" as Href} />}
    >
      {loading ? <LoadingPanel label="Loading profile…" /> : null}
      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
      ) : null}
      {!loading && draft ? (
        <Surface style={styles.card}>
          <View style={styles.identity}>
            <ProfileMark name={draft.publicName || "Organizer"} logoUrl={draft.logoUrl} size={72} />
            <Button
              label="Change logo"
              loadingLabel="Uploading…"
              icon="image-outline"
              variant="secondary"
              size="sm"
              loading={uploadingLogo}
              disabled={saving}
              onPress={() => void pickLogo()}
            />
          </View>
          {!editing ? (
            <>
              <SectionTitle>{draft.publicName || "Add your organization name"}</SectionTitle>
              {draft.tagline ? <Meta>{draft.tagline}</Meta> : null}
              <Meta>{[draft.city, draft.state].filter(Boolean).join(", ") || "City not set"}</Meta>
              <Button label="Edit profile" icon="create-outline" onPress={() => setEditing(true)} />
            </>
          ) : (
            <View style={styles.form}>
              {FIELDS.map(([key, label]) => (
                <FormField
                  key={key}
                  label={label}
                  value={draft[key]}
                  onChangeText={(value) => setDraft((prev) => (prev ? { ...prev, [key]: value } : prev))}
                  multiline={key === "bio"}
                  autoCapitalize={key === "state" ? "characters" : key === "website" || key === "instagram" ? "none" : "sentences"}
                  keyboardType={key === "website" ? "url" : "default"}
                  error={key === "publicName" ? (saveError ?? undefined) : undefined}
                />
              ))}
              <View style={styles.actions}>
                <Button
                  label="Cancel"
                  variant="secondary"
                  style={styles.flex}
                  disabled={saving}
                  onPress={() => {
                    setDraft(profile)
                    setSaveError(null)
                    setEditing(false)
                  }}
                />
                <Button
                  label="Save"
                  loadingLabel="Saving…"
                  style={styles.flex}
                  loading={saving}
                  disabled={uploadingLogo}
                  onPress={() => void save()}
                />
              </View>
            </View>
          )}
        </Surface>
      ) : null}

      {!loading && !error ? (
        <View style={styles.links}>
          <Button label="View public profile" icon="eye-outline" variant="secondary" onPress={handleViewPublic} />
          <Button
            label="Share organizer link"
            icon="share-outline"
            variant="secondary"
            onPress={() => void handleShare()}
          />
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.md,
  },
  identity: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  form: {
    gap: spacing.md,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  flex: {
    flex: 1,
  },
  links: {
    gap: spacing.sm,
    marginTop: spacing.lg,
  },
})
