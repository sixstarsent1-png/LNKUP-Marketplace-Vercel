import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, useNavigation, type Href } from "expo-router"
import * as DocumentPicker from "expo-document-picker"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ProfileMark } from "@/components/profile-mark"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { LAUNCH_CATEGORIES } from "@/lib/api/organizer-launch"
import { fetchVendorReadiness, type VendorReadiness } from "@/lib/api/vendor-readiness"
import {
  fetchVendorProfileEdit,
  updateVendorProfileEdit,
  uploadVendorLogo,
  type VendorProfileEdit,
} from "@/lib/api/vendor-profile"
import { getVendorProfileUrl } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { safeBack } from "@/lib/navigation/safe-back"
import { colors, fonts, layout, radii, spacing } from "@/lib/theme"

type Draft = Omit<VendorProfileEdit, "profileId" | "logoUrl" | "logoAttachmentId" | "publicSlug" | "publicUrl">
type FieldErrors = Partial<Record<keyof Draft, string>>

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/
const URL_LIKE = /^(https?:\/\/)?[\w-]+(\.[\w-]+)+([/?#][^\s]*)?$/i
const DESCRIPTION_MAX = 1000

function toDraft(profile: VendorProfileEdit): Draft {
  return {
    businessName: profile.businessName,
    description: profile.description,
    website: profile.website,
    instagram: profile.instagram,
    contactEmail: profile.contactEmail,
    contactPhone: profile.contactPhone,
    city: profile.city,
    state: profile.state,
    categories: [...profile.categories],
  }
}

function validate(draft: Draft): FieldErrors {
  const errors: FieldErrors = {}
  if (!draft.businessName.trim()) errors.businessName = "Enter your business name."
  if (draft.contactEmail.trim() && !EMAIL.test(draft.contactEmail.trim())) errors.contactEmail = "Enter a valid email."
  if (draft.website.trim() && !URL_LIKE.test(draft.website.trim())) errors.website = "Enter a web address like yourbusiness.com."
  if (draft.instagram.trim() && /\s/.test(draft.instagram.trim())) errors.instagram = "Instagram handles have no spaces."
  if (draft.state.trim() && !/^[A-Za-z]{2}$/.test(draft.state.trim())) errors.state = "Use the 2-letter state code."
  if (draft.description.length > DESCRIPTION_MAX) errors.description = `Keep it under ${DESCRIPTION_MAX} characters.`
  return errors
}

function sameDraft(a: Draft | null, b: Draft | null): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

/** Vendor business profile editor: grouped fields, inline validation, logo, and readiness. */
export default function VendorProfileScreen() {
  const { auth } = useAuth()
  const navigation = useNavigation()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [readiness, setReadiness] = useState<VendorReadiness | null>(null)
  const [profile, setProfile] = useState<VendorProfileEdit | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [touched, setTouched] = useState(false)
  const [saving, setSaving] = useState(false)
  const [uploadingLogo, setUploadingLogo] = useState(false)
  const savingRef = useRef(false)
  const logoRef = useRef(false)

  const saved = useMemo(() => (profile ? toDraft(profile) : null), [profile])
  const dirty = Boolean(draft && saved && !sameDraft(draft, saved))
  const errors = useMemo(() => (draft ? validate(draft) : {}), [draft])
  const visibleErrors = touched ? errors : {}

  const load = useCallback(
    async (mode: "initial" | "refresh") => {
      if (mode === "refresh") setRefreshing(true)
      setError(null)
      try {
        const [edit, ready] = await Promise.all([
          fetchVendorProfileEdit(auth),
          fetchVendorReadiness(auth).catch(() => null),
        ])
        setProfile(edit)
        setReadiness(ready)
        setDraft((current) => (current && mode === "refresh" ? current : toDraft(edit)))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  // Leaving with unsaved edits asks first (tab switches keep the draft in memory).
  useEffect(() => {
    const unsubscribe = navigation.addListener("beforeRemove" as never, (event: { preventDefault: () => void; data: { action: unknown } }) => {
      if (!dirty || savingRef.current) return
      event.preventDefault()
      void confirmAction({
        title: "Discard changes?",
        message: "Your profile edits haven't been saved.",
        confirmLabel: "Discard",
        destructive: true,
      }).then((ok) => {
        if (ok) navigation.dispatch(event.data.action as never)
      })
    })
    return unsubscribe
  }, [navigation, dirty])

  function update<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((prev) => (prev ? { ...prev, [key]: value } : prev))
  }

  function toggleCategory(category: string) {
    setDraft((prev) => {
      if (!prev) return prev
      const has = prev.categories.includes(category)
      return { ...prev, categories: has ? prev.categories.filter((c) => c !== category) : [...prev.categories, category] }
    })
  }

  async function handleSave() {
    if (!draft || savingRef.current) return
    setTouched(true)
    if (Object.keys(errors).length > 0) {
      showToast("Fix the highlighted fields, then save.", "error")
      return
    }
    savingRef.current = true
    setSaving(true)
    try {
      const result = await updateVendorProfileEdit(
        {
          ...draft,
          businessName: draft.businessName.trim(),
          state: draft.state.trim().toUpperCase(),
          instagram: draft.instagram.trim().replace(/^@/, ""),
        },
        auth,
      )
      showToast(result.message || "Profile saved.", "success")
      setTouched(false)
      const [edit, ready] = await Promise.all([fetchVendorProfileEdit(auth), fetchVendorReadiness(auth).catch(() => readiness)])
      setProfile(edit)
      setDraft(toDraft(edit))
      setReadiness(ready)
    } catch (err) {
      showToast(`Couldn't save your profile. ${appErrorFromWp(err).message}`, "error")
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  async function handleLogo() {
    if (logoRef.current) return
    logoRef.current = true
    try {
      const picked = await DocumentPicker.getDocumentAsync({
        type: ["image/jpeg", "image/png", "image/webp"],
        copyToCacheDirectory: true,
        multiple: false,
      })
      if (picked.canceled || !picked.assets?.[0]) return
      const asset = picked.assets[0]
      if (asset.size != null && asset.size > 5 * 1024 * 1024) {
        showToast("Choose an image under 5 MB.", "error")
        return
      }
      setUploadingLogo(true)
      const uploaded = await uploadVendorLogo(
        { uri: asset.uri, fileName: asset.name || "logo.jpg", mimeType: asset.mimeType || "image/jpeg" },
        auth,
      )
      setProfile((prev) => (prev ? { ...prev, logoUrl: uploaded.logoUrl, logoAttachmentId: uploaded.logoAttachmentId } : prev))
      showToast("Logo updated.", "success")
    } catch (err) {
      showToast(`Couldn't upload your logo. ${appErrorFromWp(err).message}`, "error")
    } finally {
      logoRef.current = false
      setUploadingLogo(false)
    }
  }

  function openPublicProfile() {
    if (!profile?.publicSlug) {
      showToast("Save your business name first so we can create your public page.", "info")
      return
    }
    router.push(`/storefront/vendor?vendor=${encodeURIComponent(profile.publicSlug)}` as Href)
  }

  const completion = readiness ? Math.round(readiness.profileCompletion) : null

  return (
    <Screen
      title="Business profile"
      hideTitle
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      header={
        <Pressable
          onPress={() => safeBack("/(vendor)/more")}
          accessibilityRole="button"
          accessibilityLabel="Back to Profile"
          hitSlop={8}
          style={({ pressed }) => [styles.back, pressed && styles.pressed]}
        >
          <AppIcon name="chevron-back" size={20} color={colors.gray700} />
          <Text style={styles.backText}>Profile</Text>
        </Pressable>
      }
      footer={
        dirty ? (
          <StickyActionBar caption="You have unsaved changes">
            <Button label="Discard" variant="secondary" onPress={() => saved && setDraft({ ...saved, categories: [...saved.categories] })} />
            <Button label="Save profile" loading={saving} loadingLabel="Saving…" style={styles.flex} onPress={() => void handleSave()} />
          </StickyActionBar>
        ) : null
      }
    >
      {loading ? <LoadingPanel label="Loading your profile…" variant="skeleton" count={3} /> : null}

      {!loading && error && !profile ? (
        <ErrorPanel title="Your profile didn't load" message={error.message} onAction={() => void load("initial")} />
      ) : null}

      {profile && draft ? (
        <>
          <Surface elevated style={styles.identity}>
            <View style={styles.identityRow}>
              <ProfileMark name={profile.businessName || "Your business"} logoUrl={profile.logoUrl} size={72} />
              <View style={styles.identityCopy}>
                <DisplayTitle style={styles.identityName} numberOfLines={2}>
                  {profile.businessName || "Your business"}
                </DisplayTitle>
                {completion != null ? (
                  <View style={styles.progressRow} accessibilityLabel={`Profile ${completion} percent complete`}>
                    <View style={styles.progressTrack}>
                      <View style={[styles.progressFill, { width: `${Math.min(100, completion)}%` }]} />
                    </View>
                    <Meta>{completion}% complete</Meta>
                  </View>
                ) : null}
              </View>
            </View>
            <View style={styles.identityActions}>
              <Button
                label={uploadingLogo ? "Uploading…" : profile.logoUrl ? "Change logo" : "Add logo"}
                icon="image-outline"
                variant="secondary"
                size="sm"
                loading={uploadingLogo}
                style={styles.flex}
                onPress={() => void handleLogo()}
              />
              <Button label="Preview" icon="eye-outline" variant="secondary" size="sm" style={styles.flex} onPress={openPublicProfile} />
            </View>
          </Surface>

          {readiness && readiness.missingItems.length > 0 ? (
            <ListGroup title="To finish">
              {readiness.missingItems.slice(0, 4).map((item) => (
                <ListRow
                  key={item.id}
                  icon={item.documentUrgency ? "document-attach-outline" : "checkbox-outline"}
                  iconTone={item.documentUrgency === "expired" ? "danger" : "default"}
                  title={item.label}
                  meta={
                    item.documentUrgency === "expired"
                      ? "Expired — upload a new copy"
                      : item.documentUrgency === "expiring_soon"
                        ? "Expiring soon"
                        : undefined
                  }
                  onPress={item.actionHref && !item.actionHref.includes("/(vendor)/profile") ? () => router.push(item.actionHref as Href) : undefined}
                />
              ))}
            </ListGroup>
          ) : null}

          <View style={styles.group}>
            <SectionTitle accessibilityRole="header" style={styles.groupTitle}>
              Business
            </SectionTitle>
            <FormField
              label="Business name"
              value={draft.businessName}
              onChangeText={(v) => update("businessName", v)}
              error={visibleErrors.businessName}
              autoCapitalize="words"
              textContentType="organizationName"
            />
            <FormField
              label="What you sell"
              value={draft.description}
              onChangeText={(v) => update("description", v)}
              multiline
              maxLength={DESCRIPTION_MAX + 50}
              hint={`${draft.description.length}/${DESCRIPTION_MAX} · Organizers read this when they review your application.`}
              error={visibleErrors.description}
            />
            <Meta>Vendor categories</Meta>
            <View style={styles.chips}>
              {Array.from(new Set([...LAUNCH_CATEGORIES, ...draft.categories])).map((category) => (
                <FilterChip
                  key={category}
                  label={category}
                  selected={draft.categories.includes(category)}
                  onPress={() => toggleCategory(category)}
                />
              ))}
            </View>
          </View>

          <View style={styles.group}>
            <SectionTitle accessibilityRole="header" style={styles.groupTitle}>
              Location
            </SectionTitle>
            <View style={styles.row}>
              <View style={styles.flex}>
                <FormField label="City" value={draft.city} onChangeText={(v) => update("city", v)} textContentType="addressCity" />
              </View>
              <View style={styles.state}>
                <FormField
                  label="State"
                  value={draft.state}
                  onChangeText={(v) => update("state", v.toUpperCase().slice(0, 2))}
                  autoCapitalize="characters"
                  maxLength={2}
                  error={visibleErrors.state}
                />
              </View>
            </View>
          </View>

          <View style={styles.group}>
            <SectionTitle accessibilityRole="header" style={styles.groupTitle}>
              Contact
            </SectionTitle>
            <FormField
              label="Email"
              value={draft.contactEmail}
              onChangeText={(v) => update("contactEmail", v)}
              keyboardType="email-address"
              autoCapitalize="none"
              textContentType="emailAddress"
              error={visibleErrors.contactEmail}
              hint="Shared with organizers after they approve you."
            />
            <FormField
              label="Phone"
              value={draft.contactPhone}
              onChangeText={(v) => update("contactPhone", v)}
              keyboardType="phone-pad"
              textContentType="telephoneNumber"
            />
          </View>

          <View style={styles.group}>
            <SectionTitle accessibilityRole="header" style={styles.groupTitle}>
              Online
            </SectionTitle>
            <FormField
              label="Website"
              value={draft.website}
              onChangeText={(v) => update("website", v)}
              keyboardType="url"
              autoCapitalize="none"
              textContentType="URL"
              error={visibleErrors.website}
            />
            <FormField
              label="Instagram"
              value={draft.instagram}
              onChangeText={(v) => update("instagram", v)}
              autoCapitalize="none"
              placeholder="@yourbusiness"
              error={visibleErrors.instagram}
            />
          </View>

          <ListGroup title="More">
            <ListRow icon="document-text-outline" title="Documents" meta="Insurance, permits and licenses" onPress={() => router.push("/(vendor)/documents")} />
            <ListRow
              icon="images-outline"
              title="Cover photo & gallery"
              meta="Edit on LNKup web"
              onPress={() => void openExternalUrl(getVendorProfileUrl(), "the profile editor")}
            />
          </ListGroup>
        </>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  back: {
    flexDirection: "row",
    alignItems: "center",
    alignSelf: "flex-start",
    minHeight: layout.hitTargetMin,
    gap: 2,
  },
  backText: { fontFamily: fonts.bodySemi, fontSize: 15, color: colors.gray700 },
  pressed: { opacity: 0.6 },
  identity: { gap: spacing.md },
  identityRow: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  identityCopy: { flex: 1, gap: spacing.xs },
  identityName: { fontSize: 22, lineHeight: 28 },
  identityActions: { flexDirection: "row", gap: spacing.sm },
  progressRow: { gap: 4 },
  progressTrack: { height: 6, borderRadius: radii.pill, backgroundColor: colors.gray100, overflow: "hidden" },
  progressFill: { height: 6, backgroundColor: colors.lime },
  group: { gap: spacing.md },
  groupTitle: { fontSize: 17 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  row: { flexDirection: "row", gap: spacing.sm },
  state: { width: 96 },
  flex: { flex: 1 },
})
