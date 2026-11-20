import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"

import { MapPinPreview } from "@/components/map-pin-preview"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import {
  defaultMapRegion,
  fetchMapPins,
  type MarketplaceMapPin,
  type MapPinsQuery,
} from "@/lib/api/map-pins"
import type { WpAuth } from "@/lib/api/wp-client"
import { useBreakpoint } from "@/lib/layout/use-breakpoint"
import { colors, radii, shadow, spacing } from "@/lib/theme"

type MapsModule = {
  default: React.ComponentType<{
    style?: object
    initialRegion?: {
      latitude: number
      longitude: number
      latitudeDelta: number
      longitudeDelta: number
    }
    accessible?: boolean
    accessibilityLabel?: string
    children?: React.ReactNode
  }>
  Marker: React.ComponentType<{
    coordinate: { latitude: number; longitude: number }
    title?: string
    description?: string
    onPress?: () => void
  }>
}

function loadMapsModule(): MapsModule | null {
  try {
    // Lazy require — missing native module must not crash OTA on older binaries.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require("react-native-maps") as MapsModule
  } catch {
    return null
  }
}

export function DiscoveryMap({
  query,
  auth,
  refreshToken = 0,
  testID = "discovery-map",
}: {
  query: MapPinsQuery
  auth?: WpAuth
  refreshToken?: number
  testID?: string
}) {
  const { mapHeight } = useBreakpoint()
  const maps = useMemo(() => loadMapsModule(), [])
  const [pins, setPins] = useState<MarketplaceMapPin[]>([])
  const [loading, setLoading] = useState(true)
  const [unavailable, setUnavailable] = useState(false)
  const [error, setError] = useState(false)
  const [selected, setSelected] = useState<MarketplaceMapPin | null>(null)
  const openedRef = useRef(false)

  const region = useMemo(() => defaultMapRegion(pins), [pins])

  const load = useCallback(async () => {
    setLoading(true)
    setError(false)
    setSelected(null)
    try {
      const result = await fetchMapPins(query, auth)
      setPins(result.pins)
      setUnavailable(result.unavailable && result.pins.length === 0)
    } catch {
      setPins([])
      setUnavailable(true)
      setError(true)
    } finally {
      setLoading(false)
    }
  }, [auth, query])

  useEffect(() => {
    void load()
  }, [load, refreshToken])

  useEffect(() => {
    if (openedRef.current || loading) return
    openedRef.current = true
    void trackMobileEvent({
      event: "map_view_opened",
      source: "mobile_vendor_find",
      auth,
      metadata: {
        pin_count: pins.length,
        has_city: Boolean(query.city),
        has_state: Boolean(query.state),
        has_near_me: query.lat != null && query.lng != null,
        maps_native: Boolean(maps),
      },
    })
  }, [loading, pins.length, auth, query.city, query.state, query.lat, query.lng, maps])

  function handleSelect(pin: MarketplaceMapPin) {
    void trackMobileEvent({
      event: "map_pin_clicked",
      source: "mobile_vendor_find",
      auth,
      metadata: {
        opportunity_id: pin.opportunityId,
      },
    })
    setSelected(pin)
  }

  if (!maps) {
    return (
      <View style={[styles.panel, { minHeight: mapHeight }]} testID={`${testID}-unsupported`}>
        <EmptyPanel
          title="Map needs an app update"
          message="Map tiles require a newer LNKup build from the App Store / Play Store. List view still works."
        />
      </View>
    )
  }

  if (loading) {
    return (
      <View style={[styles.panel, { minHeight: mapHeight }]} testID={`${testID}-loading`}>
        <LoadingPanel label="Loading map…" />
      </View>
    )
  }

  if (error) {
    return (
      <View style={[styles.panel, { minHeight: mapHeight }]} testID={`${testID}-error`}>
        <ErrorPanel
          title="Map unavailable"
          message="We couldn’t load map pins. List view still works."
          onAction={() => void load()}
        />
      </View>
    )
  }

  if (unavailable || pins.length === 0) {
    return (
      <View style={[styles.panel, { minHeight: mapHeight }]} testID={`${testID}-empty`}>
        <EmptyPanel
          title="No map pins"
          message="No located opportunities match these filters. Try List view, clear filters, or search Near me."
        />
      </View>
    )
  }

  const MapView = maps.default
  const Marker = maps.Marker

  return (
    <View style={[styles.wrap, { height: mapHeight }]} testID={testID}>
      <MapView style={styles.map} initialRegion={region} accessible accessibilityLabel="Discovery map">
        {pins.map((pin) => (
          <Marker
            key={pin.id}
            coordinate={{ latitude: pin.lat, longitude: pin.lng }}
            title={pin.title}
            description={[pin.city, pin.state].filter(Boolean).join(", ")}
            onPress={() => handleSelect(pin)}
          />
        ))}
      </MapView>
      {selected ? (
        <View style={styles.preview}>
          <MapPinPreview pin={selected} onClose={() => setSelected(null)} />
        </View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    height: 360,
    borderRadius: radii.lg,
    overflow: "hidden",
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.gray50,
    ...shadow("md"),
  },
  map: {
    ...StyleSheet.absoluteFill,
  },
  preview: {
    position: "absolute",
    left: spacing.md,
    right: spacing.md,
    bottom: spacing.md,
  },
  panel: {
    minHeight: 280,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    justifyContent: "center",
    padding: spacing.md,
  },
})
