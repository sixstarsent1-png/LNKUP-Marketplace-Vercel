import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router } from "expo-router"

import { AppIcon } from "@/components/ui/app-icon"
import { colors, fonts, layout, radii, typeScale } from "@/lib/theme"

type NotificationBellProps = {
  unreadCount: number
  href: string
}

export function NotificationBell({ unreadCount, href }: NotificationBellProps) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={
        unreadCount > 0 ? `Notifications, ${unreadCount} unread` : "Notifications"
      }
      onPress={() => router.push(href as never)}
      style={({ pressed }) => [styles.btn, pressed && styles.pressed]}
    >
      <AppIcon name="notifications-outline" size={20} color={colors.black} />
      {unreadCount > 0 ? (
        <View style={styles.badge}>
          <Text style={styles.badgeText}>{unreadCount > 9 ? "9+" : String(unreadCount)}</Text>
        </View>
      ) : null}
    </Pressable>
  )
}

const styles = StyleSheet.create({
  btn: {
    width: layout.hitTargetMin,
    height: layout.hitTargetMin,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  badge: {
    position: "absolute",
    top: -4,
    right: -4,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    backgroundColor: colors.black,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 4,
  },
  badgeText: {
    color: colors.lime,
    fontSize: typeScale.nano,
    fontFamily: fonts.bodyBold,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
})
