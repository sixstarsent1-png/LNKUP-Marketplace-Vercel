import { Link, Stack } from "expo-router"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, spacing } from "@/lib/theme"

export default function NotFoundScreen() {
  return (
    <>
      <Stack.Screen options={{ title: "Not found", headerShown: true }} />
      <View style={styles.container}>
        <Text style={styles.title}>Screen not found</Text>
        <Link href="/" style={styles.link}>
          <Text style={styles.linkText}>Go home</Text>
        </Link>
      </View>
    </>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    padding: spacing.xl,
    backgroundColor: colors.white,
    gap: spacing.md,
  },
  title: {
    fontSize: 20,
    fontWeight: "700",
    color: colors.black,
  },
  link: {
    paddingVertical: spacing.sm,
  },
  linkText: {
    fontSize: 15,
    fontWeight: "600",
    color: colors.gray700,
    textDecorationLine: "underline",
  },
})
