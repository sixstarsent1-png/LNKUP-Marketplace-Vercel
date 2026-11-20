import { Image, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, fonts } from "@/lib/theme"

type ProfileMarkProps = {
  name: string
  logoUrl?: string
  size?: number
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2)
  const letters = parts.map((part) => part[0] ?? "").join("")
  return (letters || "LN").toUpperCase()
}

export function ProfileMark({ name, logoUrl, size = 56 }: ProfileMarkProps) {
  const radius = size / 2
  return (
    <View
      style={[
        styles.avatar,
        { width: size, height: size, borderRadius: radius },
      ]}
    >
      {logoUrl ? (
        <Image
          source={{ uri: logoUrl }}
          style={{ width: size, height: size }}
          accessibilityLabel={`${name} logo`}
        />
      ) : (
        <Text style={[styles.letters, { fontSize: Math.max(14, size * 0.28) }]}>{initials(name)}</Text>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  avatar: {
    backgroundColor: colors.black,
    alignItems: "center",
    justifyContent: "center",
    overflow: "hidden",
  },
  letters: {
    color: colors.lime,
    fontFamily: fonts.display,
  },
})
