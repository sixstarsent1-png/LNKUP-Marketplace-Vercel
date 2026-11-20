import { Platform } from "react-native"
import AsyncStorage from "@react-native-async-storage/async-storage"
import * as SecureStore from "expo-secure-store"

/**
 * SecureStore works on iOS/Android only. On web (and when the native
 * module is missing), fall back to AsyncStorage so login/session still works.
 */
function useNativeSecureStore(): boolean {
  return Platform.OS !== "web"
}

export async function getItemAsync(key: string): Promise<string | null> {
  if (useNativeSecureStore()) {
    return SecureStore.getItemAsync(key)
  }
  return AsyncStorage.getItem(key)
}

export async function setItemAsync(key: string, value: string): Promise<void> {
  if (useNativeSecureStore()) {
    await SecureStore.setItemAsync(key, value)
    return
  }
  await AsyncStorage.setItem(key, value)
}

export async function deleteItemAsync(key: string): Promise<void> {
  if (useNativeSecureStore()) {
    await SecureStore.deleteItemAsync(key)
    return
  }
  await AsyncStorage.removeItem(key)
}
