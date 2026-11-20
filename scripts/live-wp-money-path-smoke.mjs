/**
 * Optional live probe that the money-path REST routes exist on a WP host.
 * Does not create Connect accounts or charges (operator-owned).
 *
 * Usage: node scripts/live-wp-money-path-smoke.mjs [baseUrl]
 */
const base = (process.argv[2] || process.env.WORDPRESS_API_URL || "https://thelnkup.com").replace(
  /\/$/,
  "",
)
const jsonBase = `${base}/wp-json/lnkup/v1`

const checks = []

async function probe(path) {
  const url = `${jsonBase}${path}`
  const res = await fetch(url, { headers: { Accept: "application/json" } })
  const text = await res.text()
  let body = null
  try {
    body = JSON.parse(text)
  } catch {
    body = text.slice(0, 160)
  }
  return { url, status: res.status, body }
}

function isAuthGated(status) {
  return status === 401 || status === 403
}

async function main() {
  const routes = [
    { name: "GET connect/status", path: "/financial/connect/status" },
    { name: "GET organizer-wallet", path: "/financial/organizer-wallet" },
    { name: "GET organizer-balances", path: "/financial/organizer-balances" },
    { name: "GET organizer-payouts", path: "/financial/organizer-payouts" },
    { name: "GET tax-center", path: "/financial/tax-center" },
    { name: "GET notifications/inbox", path: "/notifications/inbox" },
  ]

  for (const route of routes) {
    const result = await probe(route.path)
    checks.push({
      name: `${route.name} registered`,
      ok: isAuthGated(result.status) || result.status === 200,
      detail: `status=${result.status}`,
    })
  }

  const webhook = await fetch(`${jsonBase}/stripe-webhook`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: "{}",
  })
  checks.push({
    name: "POST stripe-webhook reachable",
    ok: webhook.status !== 404,
    detail: `status=${webhook.status} (unsigned body expected 400/401)`,
  })

  const failed = checks.filter((c) => !c.ok)
  for (const c of checks) {
    console.log(`${c.ok ? "OK" : "FAIL"} ${c.name}: ${c.detail}`)
  }

  if (failed.length > 0) {
    console.error(`\nLive money-path smoke failed (${failed.length}).`)
    process.exit(1)
  }

  console.log("\nLive money-path smoke passed (routes registered / auth-gated).")
  console.log("Still required: operator Connect onboard → vendor pay → settle → cash out.")
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
