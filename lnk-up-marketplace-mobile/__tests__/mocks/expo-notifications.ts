type PermissionResponse = { status: "granted" | "denied" | "undetermined"; canAskAgain: boolean }

export const AndroidImportance = { DEFAULT: 3, HIGH: 4 } as const

export const __state = {
  permission: { status: "undetermined", canAskAgain: true } as PermissionResponse,
  requestResult: { status: "granted", canAskAgain: true } as PermissionResponse,
  requestCalls: 0,
  channels: [] as string[],
  token: "ExponentPushToken[jest]",
}

export function __reset(): void {
  __state.permission = { status: "undetermined", canAskAgain: true }
  __state.requestResult = { status: "granted", canAskAgain: true }
  __state.requestCalls = 0
  __state.channels = []
}

export function setNotificationHandler(): void {}

export async function getPermissionsAsync(): Promise<PermissionResponse> {
  return __state.permission
}

export async function requestPermissionsAsync(): Promise<PermissionResponse> {
  __state.requestCalls += 1
  __state.permission = __state.requestResult
  return __state.requestResult
}

export async function getExpoPushTokenAsync(): Promise<{ data: string }> {
  return { data: __state.token }
}

export async function setNotificationChannelAsync(id: string): Promise<null> {
  __state.channels.push(id)
  return null
}

export async function setBadgeCountAsync(): Promise<boolean> {
  return true
}

export function addNotificationResponseReceivedListener() {
  return { remove: () => undefined }
}

export function addNotificationReceivedListener() {
  return { remove: () => undefined }
}

export async function getLastNotificationResponseAsync() {
  return null
}

export async function clearLastNotificationResponseAsync(): Promise<void> {}
