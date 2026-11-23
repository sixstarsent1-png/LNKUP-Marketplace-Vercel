import { Stack } from "expo-router"

import { colors } from "@/lib/theme"

export default function OrganizerVendorsLayout() {
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: colors.white },
      }}
    >
      <Stack.Screen name="index" />
      <Stack.Screen name="find" />
      <Stack.Screen
        name="[profileId]"
        getId={({ params }) => {
          const p = params as { profileId?: string; applicationId?: string } | undefined
          return `${p?.profileId ?? ""}:${p?.applicationId ?? ""}`
        }}
      />
    </Stack>
  )
}
