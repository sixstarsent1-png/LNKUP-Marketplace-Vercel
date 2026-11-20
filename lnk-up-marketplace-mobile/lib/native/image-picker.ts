import { Platform } from "react-native"

export type PickedImage = { uri: string; fileName: string; mimeType: string; size?: number }

type ImagePickerModule = {
  requestMediaLibraryPermissionsAsync: () => Promise<{ granted: boolean }>
  launchImageLibraryAsync: (options: Record<string, unknown>) => Promise<{
    canceled: boolean
    assets?: Array<{ uri: string; fileName?: string | null; mimeType?: string; fileSize?: number }>
  }>
}

/** expo-image-picker ships in the 1.1.0 binary; older binaries fall back to the document picker. */
function imagePicker(): ImagePickerModule | null {
  if (Platform.OS === "web") return null
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require("expo-image-picker") as ImagePickerModule
  } catch {
    return null
  }
}

export function hasPhotoLibraryPicker(): boolean {
  return imagePicker() !== null
}

/**
 * Pick one photo with square cropping from the camera roll. Returns null when cancelled, denied,
 * or when this binary does not include the picker (callers then use the document picker).
 */
export async function pickSquarePhoto(): Promise<PickedImage | null> {
  const mod = imagePicker()
  if (!mod) return null
  const permission = await mod.requestMediaLibraryPermissionsAsync()
  if (!permission.granted) return null
  const result = await mod.launchImageLibraryAsync({
    mediaTypes: ["images"],
    allowsEditing: true,
    aspect: [1, 1],
    quality: 0.85,
  })
  const asset = result.assets?.[0]
  if (result.canceled || !asset) return null
  return {
    uri: asset.uri,
    fileName: asset.fileName || "photo.jpg",
    mimeType: asset.mimeType || "image/jpeg",
    size: asset.fileSize,
  }
}
