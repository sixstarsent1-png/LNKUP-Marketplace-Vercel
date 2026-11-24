import { router, useLocalSearchParams, type Href } from "expo-router"
import * as DocumentPicker from "expo-document-picker"
import { useCallback, useEffect, useRef, useState } from "react"
import { Image, Share, StyleSheet, Switch, TextInput, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { SegmentedTabs, type SegmentedTab } from "@/components/ui/segmented-tabs"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  appealDispute,
  asPassportTab,
  deletePortfolioItem,
  fetchMyHistory,
  fetchMyPassport,
  fetchMyReviews,
  fetchPortfolio,
  fetchVerification,
  MAX_PORTFOLIO_IMAGE_BYTES,
  reportReview,
  respondToReview,
  savePortfolio,
  submitVerification,
  updatePrivacy,
  uploadPortfolioImage,
  type HistoryPage,
  type MyPassport,
  type PassportPrivacy,
  type PassportTab,
  type PortfolioItem,
  type ReviewsPage,
  type VerificationRecord,
} from "@/lib/api/passport"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, promptText, showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

const TABS: ReadonlyArray<SegmentedTab<PassportTab>> = [
  { value: "overview", label: "Overview" },
  { value: "portfolio", label: "Portfolio" },
  { value: "verification", label: "Verification" },
  { value: "history", label: "History" },
  { value: "reviews", label: "Reviews" },
  { value: "documents", label: "Documents" },
  { value: "settings", label: "Privacy" },
]

export default function VendorPassportScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ tab?: string }>()
  const [tab, setTab] = useState<PassportTab>(asPassportTab(params.tab))
  const [passport, setPassport] = useState<MyPassport | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(
    async (mode: "initial" | "soft" = "initial") => {
      if (mode === "soft") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        setPassport(await fetchMyPassport(auth))
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

  useEffect(() => {
    if (params.tab) setTab(asPassportTab(params.tab))
  }, [params.tab])

  return (
    <Screen title="Vendor Passport" subtitle="Your professional identity on LNKup" refreshing={refreshing} onRefresh={() => void load("soft")}>
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />
      <SegmentedTabs tabs={TABS} value={tab} onChange={setTab} testID="passport-tabs" />
      {loading && !passport ? <LoadingPanel label="Loading your Passport…" variant="skeleton" count={3} /> : null}
      {!loading && error && !passport ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
      ) : null}
      {passport ? (
        <View style={styles.stack}>
          {tab === "overview" ? <OverviewTab passport={passport} onGo={setTab} /> : null}
          {tab === "portfolio" ? <PortfolioTab /> : null}
          {tab === "verification" ? <VerificationTab /> : null}
          {tab === "history" ? <HistoryTab privacy={passport.privacy} onPrivacy={(p) => setPassport({ ...passport, privacy: p })} /> : null}
          {tab === "reviews" ? <ReviewsTab /> : null}
          {tab === "documents" ? <DocumentsTab /> : null}
          {tab === "settings" ? (
            <PrivacyTab
              privacy={passport.privacy}
              onSaved={(p, isPublic) => setPassport({ ...passport, privacy: p, isPublic })}
            />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

function OverviewTab({ passport, onGo }: { passport: MyPassport; onGo: (tab: PassportTab) => void }) {
  const ind = passport.indicators
  async function share() {
    if (!passport.publicUrl) return
    if (!passport.isPublic) {
      showToast("Your Passport is private. Turn on public visibility in Privacy first.", "info")
      return
    }
    try {
      await Share.share({ message: `${passport.businessName} on LNKup: ${passport.publicUrl}`, url: passport.publicUrl })
    } catch {
      showToast("Sharing is not available right now.", "error")
    }
  }
  return (
    <>
      <Surface variant="hero" style={styles.card}>
        <View style={styles.identity}>
          {passport.logo ? (
            <Image source={{ uri: passport.logo }} style={styles.logo} accessibilityLabel={`${passport.businessName} logo`} />
          ) : (
            <View style={[styles.logo, styles.logoFallback]}>
              <Text style={styles.logoText}>{passport.businessName.slice(0, 2).toUpperCase()}</Text>
            </View>
          )}
          <View style={styles.flex}>
            <SectionTitle>{passport.businessName || "Your business"}</SectionTitle>
            <Meta>{[passport.city, passport.state].filter(Boolean).join(", ")}</Meta>
            <Meta>{passport.categories.map((c) => c.name).join(" · ")}</Meta>
          </View>
        </View>
        <View style={styles.row}>
          <Button label="Edit details" size="sm" onPress={() => router.push("/(vendor)/profile")} />
          <Button label={passport.isPublic ? "Share Passport" : "Passport is private"} size="sm" variant="secondary" onPress={() => void share()} />
        </View>
      </Surface>

      <Surface style={styles.card}>
        <SectionTitle>Profile completion</SectionTitle>
        <Text style={styles.big}>{passport.strength.score}%</Text>
        <View style={styles.meter} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: passport.strength.score }}>
          <View style={[styles.meterFill, { width: `${Math.max(0, Math.min(100, passport.strength.score))}%` }]} />
        </View>
        <Meta>{passport.strength.note}</Meta>
        {passport.requiredActions.length === 0 ? (
          <Body>Nothing urgent to do.</Body>
        ) : (
          passport.requiredActions.map((a) => (
            <Button key={a.key} label={a.label} variant="ghost" size="sm" onPress={() => onGo(a.target)} />
          ))
        )}
      </Surface>

      <Surface style={styles.card}>
        <SectionTitle>Reputation at a glance</SectionTitle>
        <Indicator label="Business verification" value={ind.verification.isVerified ? ind.verification.label : "Not verified yet"} />
        <Indicator label="Marketplace experience" value={ind.experience.label} />
        <Indicator label="Organizer feedback" value={ind.feedback.label} />
        <Indicator label="Documents" value={ind.documents.label} />
        {ind.isNewVendor ? <Meta>{ind.newVendorNote}</Meta> : null}
        {ind.achievements.length ? (
          <View style={styles.chips}>
            {ind.achievements.map((a) => (
              <Text key={a.slug} style={styles.chip} accessibilityHint={a.rule}>
                {a.label}
              </Text>
            ))}
          </View>
        ) : null}
      </Surface>

      {passport.invitations.length ? (
        <Surface style={styles.card}>
          <SectionTitle>Invitations</SectionTitle>
          {passport.invitations.map((inv) => (
            <View key={inv.id} style={styles.item}>
              <Body>{inv.eventTitle || "Event invitation"}</Body>
              <Meta>{[inv.organizerName, inv.eventDate].filter(Boolean).join(" · ")}</Meta>
            </View>
          ))}
          <Button label="See invitations" size="sm" variant="secondary" onPress={() => router.push("/(vendor)/invitations" as Href)} />
        </Surface>
      ) : null}
    </>
  )
}

function Indicator({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.indicator}>
      <Text style={styles.indicatorLabel}>{label}</Text>
      <Body>{value || "—"}</Body>
    </View>
  )
}

function PortfolioTab() {
  const { auth } = useAuth()
  const [items, setItems] = useState<PortfolioItem[] | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const uploading = useRef(false)

  const load = useCallback(async () => {
    setError(null)
    try {
      setItems((await fetchPortfolio(auth)).items)
    } catch (err) {
      setError(appErrorFromWp(err))
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  function patch(index: number, next: Partial<PortfolioItem>) {
    setItems((prev) => (prev ? prev.map((item, i) => (i === index ? { ...item, ...next } : item)) : prev))
    setDirty(true)
  }

  function move(index: number, delta: number) {
    setItems((prev) => {
      if (!prev) return prev
      const to = index + delta
      if (to < 0 || to >= prev.length) return prev
      const copy = [...prev]
      const [moved] = copy.splice(index, 1)
      copy.splice(to, 0, moved)
      return copy
    })
    setDirty(true)
  }

  async function add() {
    if (uploading.current) return
    if (dirty) {
      showToast("Save your changes before adding another photo.", "info")
      return
    }
    uploading.current = true
    try {
      const picked = await DocumentPicker.getDocumentAsync({ type: ["image/jpeg", "image/png", "image/webp"], copyToCacheDirectory: true, multiple: false })
      if (picked.canceled || !picked.assets?.[0]) return
      const asset = picked.assets[0]
      if (asset.size != null && asset.size > MAX_PORTFOLIO_IMAGE_BYTES) {
        showToast("Choose an image under 8 MB.", "error")
        return
      }
      setBusy(true)
      const next = await uploadPortfolioImage({ uri: asset.uri, fileName: asset.name || "photo.jpg", mimeType: asset.mimeType || "image/jpeg" }, {}, auth)
      setItems(next)
      showToast("Photo added. Add a caption so organizers know what they are seeing.", "success")
    } catch (err) {
      showToast(`Upload failed. ${appErrorFromWp(err).message}`, "error")
    } finally {
      uploading.current = false
      setBusy(false)
    }
  }

  async function save() {
    if (!items) return
    setBusy(true)
    try {
      setItems(await savePortfolio(items, auth))
      setDirty(false)
      showToast("Portfolio saved.", "success")
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(false)
    }
  }

  async function remove(item: PortfolioItem) {
    const ok = await confirmAction({ title: "Remove photo?", message: "It will no longer appear on your Passport.", confirmLabel: "Remove", destructive: true })
    if (!ok) return
    setBusy(true)
    try {
      setItems(await deletePortfolioItem(item.attachmentId, auth))
      setDirty(false)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(false)
    }
  }

  if (error && !items) return <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
  if (!items) return <LoadingPanel label="Loading portfolio…" variant="skeleton" count={2} withMedia />
  return (
    <View style={styles.stack}>
      <View style={styles.row}>
        <Button label="Add photo" size="sm" onPress={() => void add()} loading={busy && !dirty} />
        <Button label="Save changes" size="sm" variant="accent" disabled={!dirty || busy} onPress={() => void save()} />
      </View>
      <Meta>Your uploads are labeled as vendor content and are never shown as organizer endorsements.</Meta>
      {items.length === 0 ? (
        <EmptyPanel title="No photos yet" message="Show your booth, products and activations. Organizers approve faster when they can see your setup." icon="images-outline" />
      ) : (
        items.map((item, index) => (
          <Surface key={item.attachmentId} style={styles.card}>
            <Image source={{ uri: item.thumb }} style={styles.photo} accessibilityLabel={item.alt || item.caption || "Portfolio photo"} />
            {item.isCover ? <Text style={styles.chip}>Cover</Text> : null}
            <TextInput
              style={styles.input}
              value={item.caption}
              onChangeText={(caption) => patch(index, { caption })}
              placeholder="Caption"
              maxLength={200}
              accessibilityLabel="Caption"
            />
            <TextInput
              style={styles.input}
              value={item.alt}
              onChangeText={(alt) => patch(index, { alt })}
              placeholder="Describe the photo (alt text)"
              maxLength={200}
              accessibilityLabel="Alt text"
            />
            <View style={styles.row}>
              <Button label="Up" size="sm" variant="secondary" disabled={index === 0} onPress={() => move(index, -1)} accessibilityLabel="Move photo earlier" />
              <Button label="Down" size="sm" variant="secondary" disabled={index === items.length - 1} onPress={() => move(index, 1)} accessibilityLabel="Move photo later" />
              <Button
                label="Make cover"
                size="sm"
                variant="ghost"
                disabled={item.isCover}
                onPress={() => {
                  setItems((prev) => (prev ? prev.map((p, i) => ({ ...p, isCover: i === index })) : prev))
                  setDirty(true)
                }}
              />
              <Button label="Remove" size="sm" variant="danger" onPress={() => void remove(item)} />
            </View>
          </Surface>
        ))
      )}
    </View>
  )
}

function VerificationTab() {
  const { auth } = useAuth()
  const [data, setData] = useState<{ records: VerificationRecord[]; canSubmit: boolean; summary: string } | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await fetchVerification(auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    }
  }, [auth])

  useEffect(() => {
    void load()
  }, [load])

  async function request() {
    const note = await promptText({ title: "Request verification", message: "Upload supporting documents in Documents first. Never include full tax IDs or bank details.", placeholder: "Anything our team should know", minLength: 0 })
    if (note === null) return
    setBusy(true)
    try {
      await submitVerification("business_identity", note, auth)
      showToast("Submitted. We will notify you when it is reviewed.", "success")
      await load()
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(false)
    }
  }

  if (error && !data) return <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
  if (!data) return <LoadingPanel label="Loading verification…" />
  return (
    <Surface style={styles.card}>
      <SectionTitle>Verification</SectionTitle>
      <Meta>Business verification, document review and event requirements are separate. Being verified does not satisfy an event's insurance or permit requirements on its own.</Meta>
      {data.records.map((r) => (
        <View key={r.type} style={styles.item}>
          <Body>{r.label}</Body>
          <Meta>
            {r.statusLabel}
            {r.expiresAt ? ` · recheck by ${r.expiresAt.slice(0, 10)}` : ""}
          </Meta>
          {r.reason ? <Meta>{r.reason}</Meta> : null}
        </View>
      ))}
      {data.canSubmit ? <Button label="Request business verification" loading={busy} onPress={() => void request()} /> : null}
    </Surface>
  )
}

function HistoryTab({ privacy, onPrivacy }: { privacy: PassportPrivacy; onPrivacy: (p: PassportPrivacy) => void }) {
  const { auth } = useAuth()
  const [page, setPage] = useState(1)
  const [data, setData] = useState<HistoryPage | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await fetchMyHistory(page, auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    }
  }, [auth, page])

  useEffect(() => {
    void load()
  }, [load])

  async function toggle(eventId: number, show: boolean) {
    const hidden = privacy.hiddenEventIds.filter((id) => id !== eventId)
    if (!show) hidden.push(eventId)
    try {
      const res = await updatePrivacy({ hiddenEventIds: hidden }, auth)
      onPrivacy(res.privacy)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
  }

  if (error && !data) return <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
  if (!data) return <LoadingPanel label="Loading event history…" variant="skeleton" count={3} />
  if (data.items.length === 0) {
    return <EmptyPanel title="No event history yet" message="Applications, bookings and verified check-ins build your history here." icon="calendar-outline" />
  }
  return (
    <View style={styles.stack}>
      <Meta>Approvals and payments alone never count as attendance.</Meta>
      {data.items.map((item) => (
        <Surface key={item.eventId} style={styles.card}>
          <Body>{item.eventTitle}</Body>
          <Meta>{[item.eventDate, [item.city, item.state].filter(Boolean).join(", "), item.organizerName].filter(Boolean).join(" · ")}</Meta>
          <Text style={[styles.chip, item.confirmed && styles.chipStrong]}>{item.statusLabel}</Text>
          {item.evidence === "check_in" ? <Meta>Check-in verified</Meta> : null}
          {item.tourTitle ? <Meta>Tour: {item.tourTitle}</Meta> : null}
          {item.confirmed ? (
            <View style={styles.switchRow}>
              <Text style={styles.flex}>Show on public Passport</Text>
              <Switch
                value={!privacy.hiddenEventIds.includes(item.eventId)}
                onValueChange={(v) => void toggle(item.eventId, v)}
                accessibilityLabel={`Show ${item.eventTitle} on public Passport`}
              />
            </View>
          ) : null}
        </Surface>
      ))}
      <View style={styles.row}>
        <Button label="Newer" size="sm" variant="secondary" disabled={page <= 1} onPress={() => setPage((p) => Math.max(1, p - 1))} />
        <Button label="Older" size="sm" variant="secondary" disabled={data.total <= page * data.perPage} onPress={() => setPage((p) => p + 1)} />
      </View>
    </View>
  )
}

function ReviewsTab() {
  const { auth } = useAuth()
  const [page, setPage] = useState(1)
  const [data, setData] = useState<ReviewsPage | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await fetchMyReviews(page, auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    }
  }, [auth, page])

  useEffect(() => {
    void load()
  }, [load])

  async function run(id: number, task: () => Promise<void>, success: string) {
    setBusyId(id)
    try {
      await task()
      showToast(success, "success")
      await load()
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusyId(null)
    }
  }

  async function respond(reviewId: number, current: string | null) {
    const text = await promptText({ title: current ? "Edit your response" : "Respond publicly", message: "Your response appears under the review. It does not change the review.", initialValue: current ?? "", minLength: 2 })
    if (!text) return
    await run(reviewId, () => respondToReview(reviewId, text, auth), "Response saved.")
  }

  async function report(reviewId: number) {
    const reason = await promptText({ title: "Report this review", message: "Reviews are not removed just for being negative. Tell our team what is inaccurate or inappropriate.", minLength: 10 })
    if (!reason) return
    await run(reviewId, () => reportReview(reviewId, "other", reason, auth), "Reported. The review stays visible while our team checks it.")
  }

  async function appeal(disputeId: number) {
    const reason = await promptText({ title: "Appeal decision", message: "You can appeal once.", minLength: 10 })
    if (!reason) return
    await run(disputeId, () => appealDispute(disputeId, reason, auth), "Appeal submitted.")
  }

  if (error && !data) return <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
  if (!data) return <LoadingPanel label="Loading reviews…" variant="skeleton" count={3} />
  return (
    <View style={styles.stack}>
      <Surface variant="hero" style={styles.card}>
        <SectionTitle>Organizer feedback</SectionTitle>
        <Body>{data.summaryLabel || "No reviews yet"}</Body>
      </Surface>
      {data.items.length === 0 ? (
        <EmptyPanel title="No reviews yet" message="Organizers can review you after an event you were booked for. No reviews is never a negative." icon="star-outline" />
      ) : (
        data.items.map((r) => (
          <Surface key={r.id} style={styles.card}>
            <Text style={styles.rating}>{r.overallRating}/5</Text>
            <Meta>{[r.eventTitle, r.organizerName, r.createdAt.slice(0, 10)].filter(Boolean).join(" · ")}</Meta>
            {r.verifiedParticipation ? <Meta>Verified participation</Meta> : null}
            {r.underReview ? <Meta>Under review by LNKup</Meta> : null}
            {r.publicText ? <Body>{r.publicText}</Body> : null}
            {r.tags.length ? <Meta>{r.tags.join(" · ")}</Meta> : null}
            {r.response ? (
              <View style={styles.response}>
                <Text style={styles.indicatorLabel}>Your response</Text>
                <Body>{r.response}</Body>
              </View>
            ) : null}
            <View style={styles.row}>
              {r.canRespond ? <Button label={r.response ? "Edit response" : "Respond"} size="sm" variant="secondary" loading={busyId === r.id} onPress={() => void respond(r.id, r.response)} /> : null}
              {r.canReport ? <Button label="Report" size="sm" variant="ghost" onPress={() => void report(r.id)} /> : null}
            </View>
            {r.disputes.map((d) => (
              <View key={d.id} style={styles.row}>
                <Meta>Report: {d.status.replace(/_/g, " ")}</Meta>
                {d.canAppeal ? <Button label="Appeal" size="sm" variant="ghost" onPress={() => void appeal(d.id)} /> : null}
              </View>
            ))}
          </Surface>
        ))
      )}
      <View style={styles.row}>
        <Button label="Newer" size="sm" variant="secondary" disabled={page <= 1} onPress={() => setPage((p) => Math.max(1, p - 1))} />
        <Button label="Older" size="sm" variant="secondary" disabled={data.total <= page * data.perPage} onPress={() => setPage((p) => p + 1)} />
      </View>
    </View>
  )
}

function DocumentsTab() {
  return (
    <Surface style={styles.card}>
      <SectionTitle>Document Vault</SectionTitle>
      <Body>Upload once and reuse when an event asks for the same document. Organizers only see a document after you choose to share it for a specific event.</Body>
      <Button label="Open documents" onPress={() => router.push("/(vendor)/documents")} />
    </Surface>
  )
}

function PrivacyTab({ privacy, onSaved }: { privacy: PassportPrivacy; onSaved: (p: PassportPrivacy, isPublic: boolean) => void }) {
  const { auth } = useAuth()
  const [draft, setDraft] = useState(privacy)
  const [busy, setBusy] = useState(false)
  const rows: Array<[keyof Omit<PassportPrivacy, "hiddenEventIds">, string]> = [
    ["publicProfile", "Public Passport page (anyone with the link)"],
    ["showPortfolio", "Show portfolio photos publicly"],
    ["showHistory", "Show confirmed event history publicly"],
    ["showReviews", "Show organizer reviews publicly"],
    ["showCities", "Show cities and states served"],
    ["showSocials", "Show website and social links"],
  ]
  async function save() {
    setBusy(true)
    try {
      const { hiddenEventIds: _hidden, ...flags } = draft
      const res = await updatePrivacy(flags, auth)
      onSaved(res.privacy, res.isPublic)
      showToast("Privacy settings saved.", "success")
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(false)
    }
  }
  return (
    <Surface style={styles.card}>
      <SectionTitle>Visibility & privacy</SectionTitle>
      <Meta>Contact details, documents, payment amounts and organizer notes are never public.</Meta>
      {rows.map(([key, label]) => (
        <View key={key} style={styles.switchRow}>
          <Text style={styles.flex}>{label}</Text>
          <Switch value={Boolean(draft[key])} onValueChange={(v) => setDraft({ ...draft, [key]: v })} accessibilityLabel={label} />
        </View>
      ))}
      <Button label="Save privacy settings" loading={busy} onPress={() => void save()} />
    </Surface>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md, marginTop: spacing.md },
  card: { gap: spacing.sm, padding: spacing.lg },
  identity: { flexDirection: "row", gap: spacing.md, alignItems: "center" },
  logo: { width: 64, height: 64, borderRadius: radii.lg },
  logoFallback: { backgroundColor: colors.black, alignItems: "center", justifyContent: "center" },
  logoText: { color: colors.lime, fontWeight: "800", fontSize: typeScale.bodyLarge },
  flex: { flex: 1 },
  row: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm, alignItems: "center" },
  big: { fontSize: typeScale.display, fontWeight: "800", color: colors.black },
  meter: { height: 8, borderRadius: radii.pill, backgroundColor: colors.gray100, overflow: "hidden" },
  meterFill: { height: "100%", backgroundColor: colors.lime },
  indicator: { gap: 2 },
  indicatorLabel: { fontSize: typeScale.micro, fontWeight: "700", color: colors.gray500, textTransform: "uppercase" },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs },
  chip: {
    alignSelf: "flex-start",
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    borderRadius: radii.pill,
    borderWidth: 1,
    borderColor: colors.gray200,
    fontSize: typeScale.footnote,
    color: colors.black,
  },
  chipStrong: { backgroundColor: colors.lime, borderColor: colors.lime },
  item: { gap: 2, paddingVertical: spacing.xs },
  photo: { width: "100%", aspectRatio: 4 / 3, borderRadius: radii.md, backgroundColor: colors.gray100 },
  input: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    minHeight: 44,
    color: colors.black,
  },
  switchRow: { flexDirection: "row", alignItems: "center", gap: spacing.md, minHeight: 44 },
  rating: { fontSize: typeScale.bodyLarge, fontWeight: "800", color: colors.black },
  response: { borderLeftWidth: 3, borderLeftColor: colors.lime, paddingLeft: spacing.md, gap: 2 },
})
