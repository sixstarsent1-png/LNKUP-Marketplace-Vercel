/**
 * Static guards for the Partnership OS foundation (no WordPress needed).
 *
 * Run: node scripts/partnerships-foundation-verify.mjs
 * Runtime behaviour is covered by scripts/e2e/php/partnerships-test.php on wp-env.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const plugin = 'wp-content/plugins/lnkup-marketplace';
const moduleDir = join(plugin, 'includes/partnerships');
const read = (p) => readFileSync(p, 'utf8');
const moduleFiles = readdirSync(moduleDir).filter((f) => f.endsWith('.php')).map((f) => join(moduleDir, f));
const adminFile = join(plugin, 'admin/class-partnerships-admin.php');
const all = [...moduleFiles, adminFile].map((p) => ({ p, src: read(p) }));
const rest = read(join(moduleDir, 'class-partnerships-rest.php'));
const bootstrap = read(join(plugin, 'lnkup-marketplace.php'));
const rewards = read(join(plugin, 'includes/class-connector-rewards.php'));
const tables = read(join(moduleDir, 'class-partner-tables.php'));

const routeBlocks = rest.split('register_rest_route(').slice(1);
const unguarded = routeBlocks.filter((b) => {
  const body = b.split(/register_rest_route\(|\n\t}\n/)[0];
  return !/permission_callback|\$admin|\$connector/.test(body);
});

const checks = [
  ['every partnership REST route has a permission_callback', routeBlocks.length >= 15 && unguarded.length === 0],
  ['only the public application route uses __return_true', (rest.match(/__return_true/g) || []).length === 1 && /'\/partners\/apply'[\s\S]{0,400}__return_true/.test(rest)],
  ['the public application route is rate limited and honeypotted', /rate_limited\(/.test(read(join(moduleDir, 'class-partner-applications.php'))) && /HONEYPOT/.test(read(join(moduleDir, 'class-partner-applications.php')))],
  ['public terms never hardcode a commission rate', ['page-templates/connector-interest.php', 'template-parts/connector/hero.php', 'template-parts/connector/how-it-works.php'].every((f) => !/10% commission/.test(read(`wp-content/themes/lnkup-theme/${f}`)))],
  ['no direct Stripe calls in the partnership module', all.every(({ src }) => !/\\Stripe\\|LNKup_Stripe_Client|stripe\.com/i.test(src))],
  ['partnership settings never write lnkup_settings (would reset Stripe keys)', all.every(({ src }) => !/update_option\(\s*'lnkup_settings'/.test(src))],
  ['all module files declare strict types', moduleFiles.every((p) => /declare\(strict_types=1\);/.test(read(p)))],
  ['every module file is loaded by the bootstrap', moduleFiles.every((p) => bootstrap.includes(p.replace(/\\/g, '/').replace(`${plugin}/`, '')))],
  ['reward idempotency key gets a unique index', /ADD UNIQUE KEY idempotency_key/.test(tables)],
  ['idempotency key column is nullable', /idempotency_key varchar\(191\) NULL DEFAULT NULL/.test(tables)],
  ['mark_paid only moves approved rewards', /transition\(\s*\$reward_id,\s*'approved'/.test(rewards)],
  ['approve is gated by the hold period', /lnkup_reward_on_hold/.test(rewards)],
  ['connector reversals are proportional', /reverse_for_payment\( int \$payment_id, string \$why = 'refund', float \$ratio = 1\.0 \)/.test(rewards)],
  ['admin actions verify a nonce', /check_admin_referer\( self::NONCE \)/.test(read(adminFile))],
  ['CSV export neutralizes formula injection', /csv_cell/.test(read(join(moduleDir, 'class-partnerships.php')))],
];

let failed = 0;
for (const [name, ok] of checks) {
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}`);
  if (!ok) failed += 1;
}
if (failed) {
  console.error(`partnerships-foundation-verify: ${failed} check(s) failed`);
  process.exit(1);
}
console.log('partnerships-foundation-verify: all checks passed');
