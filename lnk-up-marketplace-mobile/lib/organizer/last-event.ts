import AsyncStorage from "@react-native-async-storage/async-storage"

/** The organizer event last opened, so switching back to the organizer workspace resumes there. */
const KEY_PREFIX = "lnkup.organizer.lastEvent:"

export type LastEvent = { id: string; title: string }

export async function rememberLastEvent(userKey: string, event: LastEvent): Promise<void> {
  if (!userKey || !event.id) return
  try {
    await AsyncStorage.setItem(`${KEY_PREFIX}${userKey}`, JSON.stringify(event))
  } catch {
    // Storage is best-effort; resuming is a convenience.
  }
}

export async function loadLastEvent(userKey: string): Promise<LastEvent | null> {
  if (!userKey) return null
  try {
    const raw = await AsyncStorage.getItem(`${KEY_PREFIX}${userKey}`)
    const parsed = raw ? (JSON.parse(raw) as Partial<LastEvent>) : null
    return parsed?.id ? { id: String(parsed.id), title: String(parsed.title ?? "") } : null
  } catch {
    return null
  }
}
