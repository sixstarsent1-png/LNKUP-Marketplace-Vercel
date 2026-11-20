import { useCallback, useRef, useState } from "react"
import { Platform, Pressable, Share, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { useFocusEffect } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  ensureOrganizerRecruitCode,
  fetchOrganizerRecruitDashboard,
  type OrganizerRecruitDashboard,
} from "@/lib/api/organizer-recruit-referrals"
import { useAuth } from "@/lib/auth/auth-context"
import { copyToClipboard } from "@/lib/clipboard"
import { colors, radii, spacing } from "@/lib/theme"

function money(n: number): string {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: "USD",
    }).format(n || 0)
  } catch {
    return `$${(n || 0).toFixed(2)}`
  }
}

function formatDate(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.includes("T") ? iso : iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  })
}

function referralEarningsLabel(r: {
  lifecycle?: string
  status?: string
  earned_total: number
  expires_at: string
}): string {
  const lifecycle =
    r.lifecycle ||
    (r.status !== "active" ? "ended" : r.earned_total > 0 ? "earning" : "awaiting_payment")
  if (lifecycle === "awaiting_payment") {
    return "Signed up · awaiting first booth payment"
  }
  if (lifecycle === "ended" || r.status !== "active") {
    return `Ended ${formatDate(r.expires_at)} · ${money(r.earned_total)}`
  }
  return `Earns until ${formatDate(r.expires_at)} · ${money(r.earned_total)}`
}

function rewardCopy(data: OrganizerRecruitDashboard): string {
  const percent = Number(data.percent)
  const months = Number(data.months)
  if (percent > 0 && months > 0) {
    return `Earn LNKup credit (${percent}% of LNKup’s fee for ${months} months). Credits apply to booth fees at checkout — not a bank withdrawal.`
  }
  if (percent > 0) {
    return `Earn LNKup credit (${percent}% of LNKup’s fee). Credits apply to booth fees at checkout — not a bank withdrawal.`
  }
  return "Earn LNKup credit toward booth fees. Credits apply to booth fees at checkout — not a bank withdrawal."
}

/**
 * Shared Referral Invites screen (vendor + organizer).
 */
export function ReferralInvitesScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [minting, setMinting] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [data, setData] = useState<OrganizerRecruitDashboard | null>(null)
  const [copiedKind, setCopiedKind] = useState<"organizer" | "vendor" | null>(null)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        setData(await fetchOrganizerRecruitDashboard(auth))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  const loadedOnce = useRef(false)
  useFocusEffect(
    useCallback(() => {
      void load(loadedOnce.current ? "refresh" : "initial")
      loadedOnce.current = true
    }, [load]),
  )

  const organizerUrl = data?.share_url_organizer || data?.share_url || ""
  const vendorUrl = data?.share_url_vendor || ""

  async function handleShare(kind: "organizer" | "vendor") {
    const url = kind === "vendor" ? vendorUrl : organizerUrl
    if (!url) return
    const fallback = kind === "vendor" ? "Invite a vendor on LNKup" : "Invite an organizer on LNKup"
    const headline = data?.headline || fallback
    try {
      if (Platform.OS === "ios") {
        await Share.share({ message: headline, url })
      } else {
        await Share.share({ message: `${headline}\n${url}` })
      }
    } catch {
      // user cancelled
    }
  }

  async function handleCopy(kind: "organizer" | "vendor") {
    const url = kind === "vendor" ? vendorUrl : organizerUrl
    if (!url) return
    const ok = await copyToClipboard(url)
    if (!ok) return
    setCopiedKind(kind)
    setTimeout(() => {
      setCopiedKind((current) => (current === kind ? null : current))
    }, 1600)
  }

  async function handleGetLinks() {
    if (minting) return
    setMinting(true)
    setError(null)
    try {
      await ensureOrganizerRecruitCode(auth)
      await load("refresh")
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setMinting(false)
    }
  }

  const showShareUi = Boolean(data?.enabled && data.code)

  return (
    <Screen
      title="Referral Invites"
      subtitle="Invite organizers or vendors · earn LNKup credit toward booth fees"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      {loading && !data ? <LoadingPanel label="Loading invites…" /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("initial")} />
      ) : null}

      {data && !data.enabled ? (
        <EmptyPanel
          title="Referral Invites aren’t open yet"
          message="This program isn’t available on your account right now. Check back soon."
        />
      ) : null}

      {data && data.enabled ? (
        <View style={styles.stack} testID="referral-invites-screen">
          {error ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
          ) : null}

          <View style={styles.hero}>
            <Text style={styles.eyebrow}>Referral Invites</Text>
            <Text style={styles.heroTitle}>
              {data.headline || "Invite organizers or vendors"}
            </Text>
            <Text style={styles.heroBody}>{rewardCopy(data)}</Text>
            {data.message ? <Text style={styles.heroNote}>{data.message}</Text> : null}
          </View>

          <View style={styles.card}>
            <Text style={styles.label}>Your invite code</Text>
            <Text style={styles.code} testID="referral-invite-code">
              {data.code || "—"}
            </Text>

            {!data.code && !error ? (
              <Pressable
                style={styles.primary}
                onPress={() => void handleGetLinks()}
                disabled={minting}
                testID="referral-get-links"
              >
                <Text style={styles.primaryText}>
                  {minting ? "Getting links…" : "Get invite links"}
                </Text>
              </Pressable>
            ) : null}

            {showShareUi && organizerUrl ? (
              <View style={styles.linkBlock}>
                <Text style={styles.linkLabel}>Invite organizer</Text>
                <Text style={styles.url} numberOfLines={3} selectable>
                  {organizerUrl}
                </Text>
                <View style={styles.actions}>
                  <Pressable
                    style={styles.primary}
                    onPress={() => void handleShare("organizer")}
                    testID="referral-share-organizer"
                  >
                    <Text style={styles.primaryText}>Share</Text>
                  </Pressable>
                  <Pressable
                    style={styles.secondary}
                    onPress={() => void handleCopy("organizer")}
                    testID="referral-copy-organizer"
                  >
                    <Text style={styles.secondaryText}>
                      {copiedKind === "organizer" ? "Copied" : "Copy"}
                    </Text>
                  </Pressable>
                </View>
              </View>
            ) : null}

            {showShareUi && vendorUrl ? (
              <View style={styles.linkBlock}>
                <Text style={styles.linkLabel}>Invite vendor</Text>
                <Text style={styles.url} numberOfLines={3} selectable>
                  {vendorUrl}
                </Text>
                <View style={styles.actions}>
                  <Pressable
                    style={styles.primary}
                    onPress={() => void handleShare("vendor")}
                    testID="referral-share-vendor"
                  >
                    <Text style={styles.primaryText}>Share</Text>
                  </Pressable>
                  <Pressable
                    style={styles.secondary}
                    onPress={() => void handleCopy("vendor")}
                    testID="referral-copy-vendor"
                  >
                    <Text style={styles.secondaryText}>
                      {copiedKind === "vendor" ? "Copied" : "Copy"}
                    </Text>
                  </Pressable>
                </View>
              </View>
            ) : null}

            <Text style={styles.earnings}>
              {money(data.earnings_total)} earned · {data.active_count} active
            </Text>
          </View>

          <Text style={styles.sectionTitle}>Your referrals</Text>
          {(data.referrals?.length ?? 0) === 0 ? (
            <EmptyPanel
              title="No referrals yet"
              message="Share an organizer or vendor link. Recruits appear here as soon as they sign up — before their first booth payment."
            />
          ) : (
            data.referrals.map((r) => (
              <View key={r.id} style={styles.refCard}>
                <View style={styles.refTop}>
                  <Text style={styles.refName}>{r.referred_name}</Text>
                  <Text
                    style={[
                      styles.badge,
                      r.status === "active" ? styles.badgeActive : styles.badgeEnded,
                    ]}
                  >
                    {r.status === "active" ? "Active" : "Ended"}
                  </Text>
                </View>
                <Text style={styles.refMeta}>
                  {r.target_role === "vendor" ? "Vendor" : "Organizer"}
                  {" · "}
                  {referralEarningsLabel(r)}
                </Text>
              </View>
            ))
          )}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  hero: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.sm,
  },
  eyebrow: {
    color: colors.lime,
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 0.8,
    textTransform: "uppercase",
  },
  heroTitle: {
    color: colors.white,
    fontSize: 20,
    fontWeight: "800",
    lineHeight: 26,
  },
  heroBody: {
    color: colors.gray400,
    fontSize: 14,
    lineHeight: 20,
  },
  heroNote: {
    color: colors.gray500,
    fontSize: 13,
    lineHeight: 18,
    marginTop: spacing.xs,
  },
  card: {
    backgroundColor: colors.gray50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  label: {
    fontSize: 12,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.6,
  },
  code: {
    fontSize: 22,
    fontWeight: "800",
    color: colors.black,
    letterSpacing: 1,
  },
  linkBlock: {
    marginTop: spacing.sm,
    gap: spacing.xs,
    paddingTop: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
  },
  linkLabel: {
    fontSize: 13,
    fontWeight: "700",
    color: colors.black,
  },
  url: {
    fontSize: 12,
    color: colors.gray500,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  primary: {
    flex: 1,
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  primaryText: { color: colors.white, fontWeight: "800", fontSize: 14 },
  secondary: {
    flex: 1,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  secondaryText: { color: colors.black, fontWeight: "700", fontSize: 14 },
  earnings: { marginTop: spacing.xs, fontSize: 13, color: colors.gray700, fontWeight: "600" },
  sectionTitle: {
    fontSize: 16,
    fontWeight: "800",
    color: colors.black,
    marginTop: spacing.sm,
  },
  refCard: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  refTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 8 },
  refName: { fontSize: 15, fontWeight: "700", color: colors.black, flex: 1 },
  badge: {
    fontSize: 10,
    fontWeight: "800",
    textTransform: "uppercase",
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 999,
    overflow: "hidden",
  },
  badgeActive: { backgroundColor: colors.green50, color: colors.green700 },
  badgeEnded: { backgroundColor: colors.gray100, color: colors.gray500 },
  refMeta: { fontSize: 13, color: colors.gray500 },
})
