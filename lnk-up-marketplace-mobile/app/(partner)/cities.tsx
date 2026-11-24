import { useCallback } from "react"
import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta, SectionTitle } from "@/components/ui/typography"
import {
  fetchPartnerSupply,
  fetchPartnerTerritories,
  supplySignalLabel,
  territoryScopeLabel,
  type PartnerSupply,
  type PartnerSupplyCity,
  type PartnerSupplySignal,
  type PartnerTerritories,
} from "@/lib/api/partner"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { useFocusResource } from "@/lib/hooks/use-focus-resource"
import { colors, radii, spacing, typeScale, type StatusTone } from "@/lib/theme"

type CitiesData = {
  territories: PartnerTerritories | null
  supply: PartnerSupply | null
}

const SIGNAL_TONES: Record<PartnerSupplySignal, StatusTone> = {
  recruit_organizers: "approved",
  recruit_vendors: "review",
  balanced: "neutral",
  insufficient_data: "neutral",
}

function SupplyRow({ city, index }: { city: PartnerSupplyCity; index: number }) {
  return (
    <View style={styles.row} testID={`partner-supply-row-${index}`}>
      <View style={styles.rowTop}>
        <Text style={styles.name} numberOfLines={1}>
          {city.label}
        </Text>
        <StatusBadge label={supplySignalLabel(city.signal)} tone={SIGNAL_TONES[city.signal]} />
      </View>
      <Meta>
        {city.activeVendors} active vendors · {city.openSlots} open booths · {city.liveOrganizers} organizers live
      </Meta>
    </View>
  )
}

export default function PartnerCitiesScreen() {
  const { auth } = useAuth()
  const fetcher = useCallback(async (): Promise<CitiesData> => {
    const [territories, supply] = await Promise.allSettled([fetchPartnerTerritories(auth), fetchPartnerSupply(auth)])
    if (territories.status === "rejected" && supply.status === "rejected") throw territories.reason
    return {
      territories: territories.status === "fulfilled" ? territories.value : null,
      supply: supply.status === "fulfilled" ? supply.value : null,
    }
  }, [auth])
  const { data, loading, refreshing, error, reload } = useFocusResource(fetcher)
  const snapshot = data?.supply?.snapshotDate ? formatShortDate(data.supply.snapshotDate) : ""

  return (
    <Screen
      title="My cities"
      subtitle="Your markets and where LNKup needs organizers"
      refreshing={refreshing}
      onRefresh={() => void reload("refresh")}
    >
      {loading && !data ? <LoadingPanel label="Loading your cities…" variant="skeleton" count={2} /> : null}
      {error && !data ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void reload("initial")} />
      ) : null}

      {data ? (
        <View style={styles.stack} testID="partner-cities">
          {error ? (
            <InlineBanner tone="error" message={error.message} actionLabel="Retry" onAction={() => void reload("refresh")} />
          ) : null}

          <SectionTitle>Your markets</SectionTitle>
          {data.territories ? (
            <View style={styles.card}>
              {data.territories.homeMarket ? <Text style={styles.body}>Home market: {data.territories.homeMarket}</Text> : null}
              {data.territories.items.length ? (
                data.territories.items.map((t) => (
                  <Meta key={`${t.scope}-${t.value}`}>
                    {territoryScopeLabel(t.scope)}: {t.value}
                  </Meta>
                ))
              ) : (
                <Meta>
                  No territories assigned yet. Your partner manager assigns cities as your market grows; referrals
                  anywhere still count.
                </Meta>
              )}
            </View>
          ) : (
            <InlineBanner tone="error" message="Your assigned markets couldn't load. Pull to refresh." />
          )}

          <SectionTitle>Vendor supply in your cities</SectionTitle>
          <Meta>
            Active LNKup vendors who serve each city and the open booths there{snapshot ? ` (as of ${snapshot})` : ""}.
            Cities with many vendors and few booths are the best places to bring new organizers.
          </Meta>
          {!data.supply ? (
            <InlineBanner tone="error" message="Vendor supply numbers aren't available right now. Pull to refresh." />
          ) : data.supply.items.length === 0 ? (
            <EmptyPanel
              title="No supply numbers yet"
              message="They appear after the next daily update once vendors or events exist in your cities."
              icon="map-outline"
            />
          ) : (
            data.supply.items.map((city, index) => <SupplyRow key={city.label} city={city} index={index} />)
          )}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  card: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  body: { fontSize: typeScale.callout, color: colors.black },
  row: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 4,
    backgroundColor: colors.white,
  },
  rowTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: spacing.sm },
  name: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black, flex: 1 },
})
