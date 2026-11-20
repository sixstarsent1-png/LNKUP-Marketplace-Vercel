/**
 * Optional live smoke for WP plugin 12.18.27+ referral routes.
 * Usage: node scripts/live-wp-referral-smoke.mjs [baseUrl]
 */
const base = (process.argv[2] || process.env.WORDPRESS_API_URL || "https://thelnkup.com").replace(
  /\/$/,
  "",
)
const jsonBase = `${base}/wp-json/lnkup/v1`

const checks = []

async function fetchJson(path) {
  const url = `${jsonBase}${path}`
  const res = await fetch(url, {
    headers: { Accept: "application/json" },
  })
  const text = await res.text()
  let body = null
  try {
    body = JSON.parse(text)
  } catch {
    body = text.slice(0, 200)
  }
  return { url, status: res.status, body }
}

async function main() {
  const resolve = await fetchJson("/referrals/resolve?code=INVALID_SMOKE_CODE")
  checks.push({
    name: "referrals/resolve reachable",
    ok: resolve.status === 200 || resolve.status === 400 || resolve.status === 404,
    detail: `status=${resolve.status}`,
  })

  const resolveBody = typeof resolve.body === "object" ? resolve.body : {}
  const hasModernShape =
    resolve.status === 200 &&
    (Object.prototype.hasOwnProperty.call(resolveBody, "program") ||
      Object.prototype.hasOwnProperty.call(resolveBody, "valid") ||
      Object.prototype.hasOwnProperty.call(resolveBody, "code"))
  if (resolve.status === 200) {
    checks.push({
      name: "referrals/resolve JSON shape",
      ok: hasModernShape,
      detail: hasModernShape ? "program/valid fields present" : "unexpected payload",
    })
  }

  const regUrl = `${base}/sign-up/`
  const regRes = await fetch(regUrl, { method: "HEAD", redirect: "follow" })
  checks.push({
    name: "sign-up page",
    ok: regRes.status >= 200 && regRes.status < 400,
    detail: `status=${regRes.status}`,
  })

  const pluginPhp = await fetch(`${base}/wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php`)
  const pluginText = pluginPhp.ok ? await pluginPhp.text() : ""
  const versionMatch = pluginText.match(/Version:\s*([\d.]+)/)
  const version = versionMatch?.[1] ?? "unknown"
  const versionOk =
    version !== "unknown" &&
    version.split(".").map(Number).every((n) => !Number.isNaN(n)) &&
    compareSemver(version, "12.18.27") >= 0
  if (versionOk) {
    checks.push({
      name: "plugin version >= 12.18.27",
      ok: true,
      detail: version,
    })
  } else {
    console.log(
      `WARN plugin version: could not confirm >= 12.18.27 (${version}); upload dist/lnkup-marketplace.zip if not deployed`,
    )
  }

  const failed = checks.filter((c) => !c.ok)
  for (const c of checks) {
    console.log(`${c.ok ? "OK" : "FAIL"} ${c.name}: ${c.detail}`)
  }

  if (failed.length) {
    console.error("\nlive-wp-referral-smoke: some checks failed.")
    console.error("If all requests returned 403, deploy dist/lnkup-marketplace.zip via WP Admin.")
    process.exit(1)
  }

  console.log("\nlive-wp-referral-smoke OK")
}

function compareSemver(a, b) {
  const pa = a.split(".").map(Number)
  const pb = b.split(".").map(Number)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (diff !== 0) return diff
  }
  return 0
}

main().catch((err) => {
  console.error("live-wp-referral-smoke error:", err.message)
  process.exit(1)
})
