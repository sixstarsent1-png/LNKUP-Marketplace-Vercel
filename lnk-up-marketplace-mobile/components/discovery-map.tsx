import { useCallback, useEffect, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { MapPinPreview } from "@/components/map-pin-preview"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { fetchMapPins, type MarketplaceMapPin, type MapPinsQuery } from "@/lib/api/map-pins"
import type { WpAuth } from "@/lib/api/wp-client"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

/**
 * Web / non-native fallback: pin list instead of MapView (react-native-maps is native-only).
 */
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
  const [pins, setPins] = useState<MarketplaceMapPin[]>([])
  const [loading, setLoading] = useState(true)
  const [unavailable, setUnavailable] = useState(false)
  const [error, setError] = useState(false)
  const [selected, setSelected] = useState<MarketplaceMapPin | null>(null)
  const openedRef = useRef(false)

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
        surface: "web_fallback",
      },
    })
  }, [loading, pins.length, auth, query.city, query.state, query.lat, query.lng])

  function handleSelect(pin: MarketplaceMapPin) {
    void trackMobileEvent({
      event: "map_pin_clicked",
      source: "mobile_vendor_find",
      auth,
      metadata: {
        opportunity_id: pin.opportunityId,
        surface: "web_fallback",
      },
    })
    setSelected(pin)
  }

  if (loading) {
    return (
      <View style={styles.panel} testID={`${testID}-loading`}>
        <LoadingPanel label="Loading map…" />
      </View>
    )
  }

  if (error) {
    return (
      <View style={styles.panel} testID={`${testID}-error`}>
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
      <View style={styles.panel} testID={`${testID}-empty`}>
        <EmptyPanel
          title="No map pins"
          message="No located opportunities match these filters. Try List view, clear filters, or search Near me."
        />
      </View>
    )
  }

  return (
    <View style={styles.wrap} testID={testID}>
      <Text style={styles.hint}>
        Map tiles are available in the iOS/Android app. Showing pin locations below.
      </Text>
      {pins.slice(0, 25).map((pin) => (
        <Pressable
          key={pin.id}
          style={styles.row}
          onPress={() => handleSelect(pin)}
          testID={`${testID}-pin-${pin.opportunityId}`}
        >
          <Text style={styles.rowTitle} numberOfLines={1}>
            {pin.title}
          </Text>
          <Text style={styles.rowMeta} numberOfLines={1}>
            {[pin.city, pin.state].filter(Boolean).join(", ") || "Location set"}
          </Text>
        </Pressable>
      ))}
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
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    backgroundColor: colors.white,
    padding: spacing.md,
    gap: spacing.sm,
  },
  hint: {
    fontSize: typeScale.caption,
    color: colors.gray500,
    marginBottom: spacing.xs,
  },
  row: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    padding: spacing.md,
    gap: 2,
    backgroundColor: colors.gray50,
  },
  rowTitle: {
    fontSize: typeScale.body,
    fontWeight: "600",
    color: colors.black,
  },
  rowMeta: {
    fontSize: typeScale.caption,
    color: colors.gray500,
  },
  preview: {
    marginTop: spacing.sm,
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
