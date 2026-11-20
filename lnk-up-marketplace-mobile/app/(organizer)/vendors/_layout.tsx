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
      <Stack.Screen name="[profileId]" />
    </Stack>
  )
}
