const NAMED_ENTITIES: Record<string, string> = {
  amp: "&",
  lt: "<",
  gt: ">",
  quot: '"',
  apos: "'",
  nbsp: " ",
  ndash: "–",
  mdash: "—",
  hellip: "…",
  lsquo: "‘",
  rsquo: "’",
  ldquo: "“",
  rdquo: "”",
  bull: "•",
  middot: "·",
  copy: "©",
  reg: "®",
  trade: "™",
}

const ENTITY_PATTERN = /&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi

function decodeOnce(text: string): string {
  return text.replace(ENTITY_PATTERN, (match, body: string) => {
    if (body[0] === "#") {
      const code = body[1] === "x" || body[1] === "X" ? parseInt(body.slice(2), 16) : parseInt(body.slice(1), 10)
      return Number.isFinite(code) && code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : match
    }
    return NAMED_ENTITIES[body.toLowerCase()] ?? match
  })
}

/**
 * WordPress sends titles as "R&amp;B" or "R&#038;B"; the app renders plain text.
 * Decodes twice to cover double-encoded values ("&amp;amp;").
 */
export function decodeEntities(text: string): string {
  if (!text || text.indexOf("&") === -1) return text
  return decodeOnce(decodeOnce(text))
}

function isHtmlKey(key: string): boolean {
  return key === "html" || key.endsWith("_html")
}

/** Decodes every string in a JSON payload except fields that carry HTML markup. */
export function decodeEntitiesDeep<T>(value: T): T {
  if (typeof value === "string") return decodeEntities(value) as T
  if (Array.isArray(value)) return value.map((item) => decodeEntitiesDeep(item)) as T
  if (value && typeof value === "object") {
    const out: Record<string, unknown> = {}
    for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
      out[key] = isHtmlKey(key) ? item : decodeEntitiesDeep(item)
    }
    return out as T
  }
  return value
}
