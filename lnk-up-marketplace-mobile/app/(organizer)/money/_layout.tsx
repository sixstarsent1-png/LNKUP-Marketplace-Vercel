import { Stack } from "expo-router"

import { colors } from "@/lib/theme"

export default function OrganizerMoneyLayout() {
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: colors.white },
      }}
    >
      <Stack.Screen name="index" />
      <Stack.Screen name="cashout" />
      <Stack.Screen name="payout" />
      <Stack.Screen name="event" />
    </Stack>
  )
}
