const CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"

/** Encode a UTF-8 string to base64 without Node Buffer. */
export function encodeBase64(value: string): string {
  const bytes = new TextEncoder().encode(value)
  let output = ""
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i] ?? 0
    const b = bytes[i + 1]
    const c = bytes[i + 2]
    const triplet = (a << 16) | ((b ?? 0) << 8) | (c ?? 0)
    output += CHARS[(triplet >> 18) & 63]
    output += CHARS[(triplet >> 12) & 63]
    output += b === undefined ? "=" : CHARS[(triplet >> 6) & 63]
    output += c === undefined ? "=" : CHARS[triplet & 63]
  }
  return output
}
