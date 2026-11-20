import AsyncStorage from "@react-native-async-storage/async-storage"

const KEY = "lnkup_pending_stripe_application_id"

export async function stashStripeRedirectApplicationId(applicationId: string): Promise<void> {
  if (!applicationId) return
  await AsyncStorage.setItem(KEY, applicationId)
}

export async function consumeStripeRedirectApplicationId(): Promise<string | null> {
  try {
    const value = await AsyncStorage.getItem(KEY)
    if (value) await AsyncStorage.removeItem(KEY)
    return value
  } catch {
    return null
  }
}
