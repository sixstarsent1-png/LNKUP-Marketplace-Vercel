import { useCallback, useEffect, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchVendorGrowth,
  type VendorGrowthRecommendation,
  type VendorGrowthSnapshot,
} from "@/lib/api/vendor-growth"
import { fetchVendorReviews, type VendorReviewsSnapshot } from "@/lib/api/vendor-reviews"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

function recommendationHref(actionKey: string): Href {
  switch (actionKey) {
    case "profile_incomplete":
      return "/(vendor)/profile"
    case "renew_documents":
    case "improve_approval":
      return "/(vendor)/documents"
    case "expand_city":
    case "add_category":
    case "strong_match":
      return "/(vendor)/find"
    default:
      return "/(vendor)/find"
  }
}

function recommendationCta(actionKey: string): string {
  switch (actionKey) {
    case "profile_incomplete":
      return "Open profile"
    case "renew_documents":
    case "improve_approval":
      return "Open documents"
    case "expand_city":
    case "add_category":
    case "strong_match":
      return "Find events"
    default:
      return "Continue"
  }
}

export default function VendorGrowthScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [data, setData] = useState<VendorGrowthSnapshot | null>(null)
  const [reputation, setReputation] = useState<VendorReviewsSnapshot | null>(null)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const growth = await fetchVendorGrowth(auth)
        setData(growth)
        try {
          setReputation(await fetchVendorReviews(auth))
        } catch {
          setReputation(null)
        }
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

  const showEmpty = Boolean(data?.emptyState && (data.applicationsSubmitted ?? 0) === 0)
  const incomplete = data?.profileIncomplete.slice(0, 4) ?? []
  const strength = Math.round(data?.profileStrength ?? 0)
  const score = Math.round(data?.vendorScore ?? 0)

  return (
    <Screen
      title="Growth"
      subtitle="Score, profile strength, and next moves"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />

      <Pressable
        style={styles.recruitCard}
        onPress={() => router.push("/(vendor)/referrals" as Href)}
        accessibilityRole="button"
        testID="growth-referral-cta"
      >
        <Text style={styles.recruitEyebrow}>Referral Invites</Text>
        <Text style={styles.recruitTitle}>
          Invite organizers or vendors — earn LNKup credit toward booth fees
        </Text>
        <Text style={styles.recruitCta}>Open invites →</Text>
      </Pressable>

      {loading ? <LoadingPanel label="Loading growth…" variant="skeleton" count={3} /> : null}

      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {!loading && !error && data ? (
        <View style={styles.stack}>
          <View style={styles.scoreStrip}>
            <View style={styles.scoreRow}>
              <View style={styles.scoreBlock}>
                <Text style={styles.scoreValue}>{score}</Text>
                <Text style={styles.scoreLabel}>Vendor score</Text>
              </View>
              <View style={styles.scoreMeta}>
                <Text style={styles.strengthLine}>Profile {strength}%</Text>
                {reputation?.label ? (
                  <Text style={styles.repLabel}>{reputation.label}</Text>
                ) : reputation ? (
                  <Text style={styles.repLabel}>Reputation {Math.round(reputation.score)}</Text>
                ) : null}
                <Text style={styles.repMeta}>
                  {reputation
                    ? `${reputation.reviewCount} review${reputation.reviewCount === 1 ? "" : "s"} · ${data.completedEvents} events`
                    : `${data.completedEvents} events completed`}
                </Text>
              </View>
            </View>
            <Pressable
              style={styles.scoreCta}
              onPress={() => router.push("/(vendor)/reviews" as Href)}
              accessibilityRole="button"
            >
              <Text style={styles.scoreCtaText}>View reviews</Text>
            </Pressable>
          </View>

          <View style={styles.kpiRow}>
            <Kpi label="Acceptance" value={`${Math.round(data.applicationSuccessRate)}%`} />
            <Kpi label="Completed" value={String(data.completedEvents)} />
          </View>
          <View style={styles.kpiRow}>
            <Kpi label="Applications" value={String(data.applicationsSubmitted)} />
            <Kpi label="Cities" value={String(data.citiesServed)} />
          </View>

          {showEmpty ? (
            <EmptyPanel
              title="Growth starts with applications"
              message="Apply to events and complete bookings to unlock insights."
              actionLabel="Find events"
              onAction={() => router.push("/(vendor)/find" as Href)}
            />
          ) : null}

          <View style={styles.card}>
            <Text style={styles.section}>Profile strength</Text>
            <View style={styles.progressTrack}>
              <View style={[styles.progressFill, { width: `${Math.min(100, Math.max(0, strength))}%` }]} />
            </View>
            <Text style={styles.progressMeta}>{strength}% complete</Text>
            {incomplete.length > 0 ? (
              <View style={styles.listGap}>
                {incomplete.map((item) => (
                  <Text key={item} style={styles.bodyLine}>
                    · {item}
                  </Text>
                ))}
              </View>
            ) : (
              <Text style={styles.bodyLine}>Profile looks solid — keep applying.</Text>
            )}
            {incomplete.length > 0 ? (
              <View style={styles.inlineActions}>
                <Pressable
                  style={styles.secondaryBtn}
                  onPress={() => router.push("/(vendor)/profile" as Href)}
                >
                  <Text style={styles.secondaryBtnText}>Complete profile</Text>
                </Pressable>
                <Pressable
                  style={styles.secondaryBtn}
                  onPress={() => router.push("/(vendor)/documents" as Href)}
                >
                  <Text style={styles.secondaryBtnText}>Upload documents</Text>
                </Pressable>
              </View>
            ) : null}
          </View>

          {!showEmpty && data.recommendations.length > 0 ? (
            <View style={styles.card}>
              <Text style={styles.section}>Recommendations</Text>
              <View style={styles.listGap}>
                {data.recommendations.map((rec) => (
                  <RecommendationCard key={rec.id} recommendation={rec} />
                ))}
              </View>
            </View>
          ) : null}

          {!showEmpty && data.insights.length > 0 ? (
            <View style={styles.card}>
              <Text style={styles.section}>Insights</Text>
              {data.insights.map((line) => (
                <Text key={line} style={styles.bodyLine}>
                  · {line}
                </Text>
              ))}
            </View>
          ) : null}

          <View style={styles.card}>
            <Text style={styles.section}>Milestones</Text>
            <View style={styles.listGap}>
              {data.milestones.map((m) => (
                <View key={m.id} style={styles.milestoneRow}>
                  <Text style={[styles.milestoneMark, m.done && styles.milestoneMarkDone]}>
                    {m.done ? "✓" : "○"}
                  </Text>
                  <Text style={[styles.milestoneLabel, !m.done && styles.milestonePending]}>
                    {m.label}
                  </Text>
                </View>
              ))}
            </View>
          </View>
        </View>
      ) : null}
    </Screen>
  )
}

function RecommendationCard({ recommendation }: { recommendation: VendorGrowthRecommendation }) {
  return (
    <View style={styles.recCard}>
      <Text style={styles.recTitle}>{recommendation.title}</Text>
      {recommendation.reason ? <Text style={styles.recReason}>{recommendation.reason}</Text> : null}
      <Pressable
        onPress={() => router.push(recommendationHref(recommendation.actionKey))}
        accessibilityRole="button"
      >
        <Text style={styles.recCta}>{recommendationCta(recommendation.actionKey)} ›</Text>
      </Pressable>
    </View>
  )
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.kpi}>
      <Text style={styles.kpiValue}>{value}</Text>
      <Text style={styles.kpiLabel}>{label}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  back: { marginBottom: spacing.sm },
  backText: { fontSize: typeScale.body, fontWeight: "600", color: colors.gray700 },
  recruitCard: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.xs,
    marginBottom: spacing.md,
  },
  recruitEyebrow: {
    color: colors.lime,
    fontSize: typeScale.micro,
    fontWeight: "800",
    letterSpacing: 0.6,
    textTransform: "uppercase",
  },
  recruitTitle: {
    color: colors.white,
    fontSize: typeScale.bodyLarge,
    fontWeight: "800",
    lineHeight: 22,
  },
  recruitCta: {
    color: colors.lime,
    fontSize: typeScale.footnote,
    fontWeight: "700",
    marginTop: 4,
  },
  stack: { gap: spacing.md },
  scoreStrip: {
    backgroundColor: colors.lime,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.md,
  },
  scoreRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.lg,
  },
  scoreBlock: {
    minWidth: 72,
  },
  scoreValue: {
    fontSize: typeScale.hero,
    fontWeight: "800",
    color: colors.black,
  },
  scoreLabel: {
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray700,
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  scoreMeta: {
    flex: 1,
    gap: 2,
  },
  strengthLine: {
    fontSize: typeScale.bodyLarge,
    fontWeight: "800",
    color: colors.black,
  },
  repLabel: {
    fontSize: typeScale.body,
    fontWeight: "600",
    color: colors.gray700,
  },
  repMeta: {
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
  scoreCta: {
    alignSelf: "flex-start",
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm + 2,
  },
  scoreCtaText: {
    color: colors.white,
    fontWeight: "700",
    fontSize: typeScale.body,
  },
  kpiRow: { flexDirection: "row", gap: spacing.md },
  kpi: {
    flex: 1,
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    alignItems: "center",
  },
  kpiValue: { fontSize: typeScale.title, fontWeight: "800", color: colors.black },
  kpiLabel: { fontSize: typeScale.caption, color: colors.gray500, marginTop: 2 },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  section: { fontSize: typeScale.bodyLarge, fontWeight: "700", color: colors.black },
  progressTrack: {
    height: 8,
    borderRadius: 999,
    backgroundColor: colors.gray100,
    overflow: "hidden",
  },
  progressFill: {
    height: "100%",
    backgroundColor: colors.black,
    borderRadius: 999,
  },
  progressMeta: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray700,
  },
  listGap: { gap: spacing.sm },
  bodyLine: { fontSize: typeScale.footnote, color: colors.gray700, lineHeight: 18 },
  inlineActions: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  secondaryBtn: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    backgroundColor: colors.gray50,
  },
  secondaryBtnText: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
  recCard: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: spacing.xs,
    backgroundColor: colors.gray50,
  },
  recTitle: {
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.black,
  },
  recReason: {
    fontSize: typeScale.footnote,
    color: colors.gray700,
    lineHeight: 18,
  },
  recCta: {
    marginTop: 2,
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
  milestoneRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  milestoneMark: {
    width: 18,
    fontSize: typeScale.body,
    fontWeight: "700",
    color: colors.gray400,
  },
  milestoneMarkDone: {
    color: colors.black,
  },
  milestoneLabel: {
    flex: 1,
    fontSize: typeScale.body,
    fontWeight: "600",
    color: colors.black,
  },
  milestonePending: {
    color: colors.gray500,
    fontWeight: "500",
  },
})
