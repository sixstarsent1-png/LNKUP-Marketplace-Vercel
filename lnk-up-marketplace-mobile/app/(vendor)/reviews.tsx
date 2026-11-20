import { useCallback, useEffect, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { getVendorDashboardUrl } from "@/lib/api/config"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorReviews, type VendorReviewsSnapshot } from "@/lib/api/vendor-reviews"
import { useAuth } from "@/lib/auth/auth-context"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, radii, spacing } from "@/lib/theme"

export default function VendorReviewsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [data, setData] = useState<VendorReviewsSnapshot | null>(null)

  const load = useCallback(
    async (mode: "initial" | "soft" = "initial") => {
      if (mode === "soft" && data) setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        setData(await fetchVendorReviews(auth))
      } catch (err) {
        setError(appErrorFromWp(err))
        if (mode !== "soft") setData(null)
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, data],
  )

  useEffect(() => {
    void load("initial")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [auth])

  const showLoader = loading && !data

  return (
    <Screen
      title="Reviews"
      subtitle="What organizers say"
      refreshing={refreshing}
      onRefresh={() => void load("soft")}
    >
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />

      {showLoader ? <LoadingPanel label="Loading reviews…" /> : null}

      {!showLoader && error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
      ) : null}

      {data ? (
        <View style={styles.stack}>
          <View style={styles.summary}>
            <Text style={styles.score}>{data.avgRating.toFixed(1)}</Text>
            <Text style={styles.meta}>
              {data.reviewCount} review{data.reviewCount === 1 ? "" : "s"}
              {data.label ? ` · ${data.label}` : ""}
            </Text>
            <Text style={styles.meta}>Reputation score {Math.round(data.score)}</Text>
          </View>

          {data.reviews.length === 0 ? (
            <EmptyPanel
              title="No reviews yet"
              message="Reviews appear after organizers rate completed events."
            />
          ) : (
            <View style={styles.list}>
              {data.reviews.map((review) => (
                <View key={review.id} style={styles.card}>
                  <Text style={styles.rating}>{review.overallRating.toFixed(1)} ★</Text>
                  {review.eventTitle ? (
                    <Text style={styles.title}>{review.eventTitle}</Text>
                  ) : null}
                  {review.publicText ? <Text style={styles.body}>{review.publicText}</Text> : null}
                  <Text style={styles.meta}>
                    {[review.organizerName, review.createdAt].filter(Boolean).join(" · ")}
                  </Text>
                </View>
              ))}
            </View>
          )}

          {data.tips.length > 0 ? (
            <View style={styles.tips}>
              <Text style={styles.section}>Tips</Text>
              {data.tips.map((tip) => (
                <Text key={tip} style={styles.body}>
                  · {tip}
                </Text>
              ))}
            </View>
          ) : null}

          <Pressable
            style={styles.outline}
            onPress={() =>
              void openExternalUrl(getVendorDashboardUrl("reputation"), "reputation dashboard")
            }
          >
            <Text style={styles.outlineText}>Manage on LNKup</Text>
          </Pressable>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  back: { marginBottom: spacing.sm },
  backText: { fontSize: 14, fontWeight: "600", color: colors.gray700 },
  stack: { gap: spacing.md },
  summary: {
    backgroundColor: colors.lime,
    borderRadius: radii.lg,
    padding: spacing.xl,
    gap: spacing.xs,
  },
  score: { fontSize: 36, fontWeight: "800", color: colors.black },
  meta: { fontSize: 13, color: colors.gray700 },
  list: { gap: spacing.md },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  rating: { fontSize: 14, fontWeight: "700", color: colors.black },
  title: { fontSize: 15, fontWeight: "700", color: colors.black },
  body: { fontSize: 13, color: colors.gray700, lineHeight: 18 },
  tips: {
    backgroundColor: colors.gray50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  section: { fontSize: 14, fontWeight: "700", color: colors.black },
  outline: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
    backgroundColor: colors.white,
  },
  outlineText: { fontWeight: "600", color: colors.black },
})
