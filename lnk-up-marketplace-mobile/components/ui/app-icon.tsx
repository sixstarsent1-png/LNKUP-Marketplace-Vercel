import Ionicons from "@expo/vector-icons/Ionicons"
import type { ComponentProps } from "react"

import { colors } from "@/lib/theme"

export type AppIconName = ComponentProps<typeof Ionicons>["name"]

export function AppIcon({
  name,
  size = 20,
  color = colors.black,
}: {
  name: AppIconName
  size?: number
  color?: string
}) {
  return <Ionicons name={name} size={size} color={color} />
}
