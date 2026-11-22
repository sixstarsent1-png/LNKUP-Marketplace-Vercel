import { useEffect, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"
import { OpportunityCard } from "@/components/opportunity-card"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { AppIcon } from "@/components/ui/app-icon"
import type { WpAuth } from "@/lib/api/wp-client"
import { isAbortError } from "@/lib/api/wp-client"
import {
  VENDOR_MATCH_SECTION_TITLES,
  fetchVendorMatchSection,
  type MatchedOpportunity,
  type MatchedTour,
  type VendorMatchSection,
  type VendorMatchSectionResult,
} from "@/lib/api/match"
import { colors, radii, spacing } from "@/lib/theme"

const SECTIONS: readonly VendorMatchSection[] = ["recommended", "preferred_markets", "deadlines", "previous_organizers", "tours", "series"]

type Props = {
  auth?: WpAuth
  savedIds: Set<number>
  onToggleSave: (opportunityId: number) => void
}

/**
 * Personalized sections from the WordPress matching engine. Sections that fail
 * or come back empty are hidden; nothing here blocks the main search list.
 */
export function MatchRails({ auth, savedIds, onToggleSave }: Props) {
  const [results, setResults] = useState<Partial<Record<VendorMatchSection, VendorMatchSectionResult>>>({})
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    if (!auth) return
    const controller = new AbortController()
    Promise.allSettled(SECTIONS.map((section) => fetchVendorMatchSection(section, auth, controller.signal))).then(
      (settled) => {
        if (controller.signal.aborted) return
        const next: Partial<Record<VendorMatchSection, VendorMatchSectionResult>> = {}
        settled.forEach((result, index) => {
          if (result.status === "fulfilled") {
            next[SECTIONS[index]] = result.value
          } else if (!isAbortError(result.reason)) {
            console.warn("[MatchRails]", SECTIONS[index], result.reason)
          }
        })
        setResults(next)
        setLoaded(true)
      },
    )
    return () => controller.abort()
  }, [auth])

  if (!auth || !loaded) return null

  if (Object.values(results).some((r) => r?.status === "needs_profile")) {
    return (
      <View testID="match-needs-profile">
        <Surface>
          <SectionTitle>Get matched to the right events</SectionTitle>
          <Meta>Add your categories and the cities you serve so LNKup can recommend events for you.</Meta>
          <Pressable onPress={() => router.push("/(vendor)/profile" as Href)} accessibilityRole="link">
            <Body style={styles.link}>Complete matching preferences</Body>
          </Pressable>
        </Surface>
      </View>
    )
  }

  return (
    <View style={styles.wrap} testID="match-rails">
      {SECTIONS.map((section) => {
        const result = results[section]
        if (!result || result.status !== "ready" || result.items.length === 0) return null
        return (
          <View key={section} style={styles.section} testID={`match-rail-${section}`}>
            <SectionTitle>{VENDOR_MATCH_SECTION_TITLES[section]}</SectionTitle>
            {result.items.slice(0, 3).map((item) =>
              item.kind === "tour" ? (
                <TourRow key={`tour-${item.id}`} tour={item} />
              ) : (
                <OpportunityRow
                  key={`opp-${item.opportunity.opportunityId ?? item.opportunity.id}`}
                  item={item}
                  saved={item.opportunity.opportunityId ? savedIds.has(item.opportunity.opportunityId) : false}
                  onToggleSave={onToggleSave}
                />
              ),
            )}
          </View>
        )
      })}
    </View>
  )
}

function OpportunityRow({
  item,
  saved,
  onToggleSave,
}: {
  item: MatchedOpportunity
  saved: boolean
  onToggleSave: (opportunityId: number) => void
}) {
  const id = item.opportunity.opportunityId
  return (
    <View style={styles.row}>
      {item.match ? (
        <View style={styles.reasons} accessibilityLabel={`${item.match.bandLabel}. ${item.match.reasons.join(". ")}`}>
          <View style={[styles.band, item.match.band === "strong" && styles.bandStrong]}>
            <Meta style={styles.bandText}>{item.match.bandLabel}</Meta>
          </View>
          {item.match.reasons.slice(0, 2).map((reason) => (
            <Meta key={reason} style={styles.reason}>
              {reason}
            </Meta>
          ))}
          {item.moreDates > 0 ? <Meta style={styles.reason}>+{item.moreDates} more dates</Meta> : null}
        </View>
      ) : null}
      <OpportunityCard
        item={item.opportunity}
        saved={saved}
        onToggleSave={id ? () => onToggleSave(id) : undefined}
        onPress={id ? () => router.push(`/opportunity/${id}` as Href) : undefined}
        onApply={id ? () => router.push(`/apply/${id}` as Href) : undefined}
      />
    </View>
  )
}

function TourRow({ tour }: { tour: MatchedTour }) {
  return (
    <Pressable
      onPress={() => router.push(`/tour/${tour.id}` as Href)}
      accessibilityRole="button"
      accessibilityLabel={`Open ${tour.title}`}
      testID="match-tour-row"
    >
      <Surface>
        <View style={styles.tourHeader}>
          <AppIcon name="git-network-outline" size={18} color={colors.gray700} />
          <Meta>{tour.type === "series" ? "Recurring market" : "Tour"}</Meta>
        </View>
        <SectionTitle>{tour.title}</SectionTitle>
        {tour.reasons[0] ? <Body>{tour.reasons[0]}</Body> : null}
        {tour.places.length ? <Meta>{tour.places.slice(0, 3).join(" · ")}</Meta> : null}
        <Meta>{tour.nextDate ? `Next: ${tour.nextDate}` : "Dates TBA"}</Meta>
      </Surface>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.xl, marginBottom: spacing.lg },
  section: { gap: spacing.md },
  row: { gap: spacing.sm },
  reasons: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: spacing.sm },
  band: {
    backgroundColor: colors.gray100,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: 2,
  },
  bandStrong: { backgroundColor: colors.lime },
  bandText: { color: colors.black },
  reason: { color: colors.gray700 },
  link: { textDecorationLine: "underline" },
  tourHeader: { flexDirection: "row", alignItems: "center", gap: spacing.xs },
})
