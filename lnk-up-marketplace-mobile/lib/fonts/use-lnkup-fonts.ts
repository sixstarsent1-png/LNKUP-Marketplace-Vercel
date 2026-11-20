import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
} from "@expo-google-fonts/inter"
import {
  Sora_600SemiBold,
  Sora_700Bold,
} from "@expo-google-fonts/sora"
import { useFonts } from "expo-font"

export function useLnkupFonts(): boolean {
  const [loaded] = useFonts({
    Sora_600SemiBold,
    Sora_700Bold,
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  })
  return loaded
}
