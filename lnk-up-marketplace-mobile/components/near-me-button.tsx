import { useState } from "react"
import { Pressable, StyleSheet, TextInput, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import * as Location from "expo-location"

import { showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing } from "@/lib/theme"

const DEFAULT_RADIUS = 50

type NearMeButtonProps = {
  onLocation: (lat: number, lng: number, radiusMiles: number) => void
  onCityFallback: (city: string) => void
}

export function NearMeButton({ onLocation, onCityFallback }: NearMeButtonProps) {
  const [busy, setBusy] = useState(false)
  const [showFallback, setShowFallback] = useState(false)
  const [cityDraft, setCityDraft] = useState("")

  async function handlePress() {
    setBusy(true)
    try {
      const { status } = await Location.requestForegroundPermissionsAsync()
      if (status !== "granted") {
        setShowFallback(true)
        return
      }
      const position = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Balanced,
      })
      onLocation(position.coords.latitude, position.coords.longitude, DEFAULT_RADIUS)
      setShowFallback(false)
    } catch {
      setShowFallback(true)
      showToast("We couldn't get your location. Enter a city instead to search nearby markets.", "info")
    } finally {
      setBusy(false)
    }
  }

  return (
    <View style={styles.wrap}>
      <Pressable
        style={[styles.btn, busy && styles.btnBusy]}
        onPress={() => void handlePress()}
        disabled={busy}
        accessibilityRole="button"
        accessibilityLabel="Search near me"
      >
        <Text style={styles.btnText}>{busy ? "Locating…" : "Near me"}</Text>
      </Pressable>

      {showFallback ? (
        <View style={styles.fallback}>
          <Text style={styles.fallbackLabel}>Enter a city</Text>
          <View style={styles.fallbackRow}>
            <TextInput
              value={cityDraft}
              onChangeText={setCityDraft}
              placeholder="City name"
              placeholderTextColor={colors.gray400}
              style={styles.input}
              returnKeyType="search"
              onSubmitEditing={() => {
                const city = cityDraft.trim()
                if (!city) return
                onCityFallback(city)
                setShowFallback(false)
              }}
            />
            <Pressable
              style={styles.go}
              onPress={() => {
                const city = cityDraft.trim()
                if (!city) return
                onCityFallback(city)
                setShowFallback(false)
              }}
            >
              <Text style={styles.goText}>Go</Text>
            </Pressable>
          </View>
        </View>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    gap: spacing.sm,
  },
  btn: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    backgroundColor: colors.lime,
    alignItems: "center",
  },
  btnBusy: {
    opacity: 0.7,
  },
  btnText: {
    fontWeight: "700",
    color: colors.black,
    fontSize: 13,
  },
  fallback: {
    gap: spacing.xs,
  },
  fallbackLabel: {
    fontSize: 12,
    fontWeight: "600",
    color: colors.gray500,
  },
  fallbackRow: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  input: {
    flex: 1,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: 14,
    color: colors.black,
    backgroundColor: colors.gray50,
  },
  go: {
    backgroundColor: colors.black,
    borderRadius: radii.md,
    paddingHorizontal: spacing.lg,
    justifyContent: "center",
  },
  goText: {
    color: colors.white,
    fontWeight: "700",
  },
})
