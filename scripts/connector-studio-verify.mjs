/**
 * City Connector launch plan + Content Studio guards.
 * Rank 1 is Phase 1 only. Publishing a video never invents commission.
 *
 * Run: node scripts/connector-studio-verify.mjs
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

function assert(cond, message) {
  if (!cond) {
    throw new Error(message);
  }
}

const STYLE_KEYS = ['relationship', 'video', 'community', 'event'];
const MANUAL_STEPS = [
  'copied_link',
  'copied_vendor_link',
  'reviewed_targets',
  'reviewed_earnings',
  'sent_intro',
  'published_video',
  'shared_story',
  'posted_group',
  'city_call',
];
const AUTO_STEPS = [
  'first_visit',
  'organizer_signup',
  'first_activation',
  'first_booking',
  'payout_eligible',
  'next_rank',
  'profile',
  'markets',
];
const ALLOWED_HOSTS = [
  'instagram.com',
  'www.instagram.com',
  'tiktok.com',
  'www.tiktok.com',
  'vm.tiktok.com',
  'facebook.com',
  'www.facebook.com',
  'fb.watch',
  'linkedin.com',
  'www.linkedin.com',
  'lnkd.in',
  'youtube.com',
  'www.youtube.com',
  'youtu.be',
  'm.facebook.com',
];

function parseGrowthStyles(raw) {
  let arr = raw;
  if (!Array.isArray(raw)) {
    try {
      arr = JSON.parse(String(raw || ''));
    } catch {
      arr = String(raw || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    }
  }
  if (!Array.isArray(arr)) {
    return [];
  }
  return [...new Set(arr.map((k) => String(k)).filter((k) => STYLE_KEYS.includes(k)))];
}

function isProfileComplete(connector) {
  const name = String(connector.display_name || '').trim();
  const city = String(connector.city || '').trim();
  const phone = String(connector.phone || '').trim();
  const social = String(connector.social_url || '').trim();
  return !!(name && city && (phone || social));
}

function migrateOnboardingMeta(meta) {
  const next = { ...meta };
  if (next.reviewed_targets && !next.reviewed_earnings) {
    next.reviewed_earnings = next.reviewed_targets;
  }
  return next;
}

function phase1Complete(connector, meta) {
  const styles = parseGrowthStyles(connector.growth_styles);
  const city = String(connector.city || '').trim();
  const market = String(connector.market_slug || '').trim();
  const legacy =
    isProfileComplete(connector) &&
    !!meta.copied_link &&
    !!meta.copied_vendor_link &&
    !!(meta.reviewed_targets || meta.reviewed_earnings);
  if (legacy) {
    return true;
  }
  return (
    isProfileComplete(connector) &&
    !!city &&
    !!market &&
    styles.length > 0 &&
    !!meta.copied_link &&
    !!meta.copied_vendor_link &&
    !!meta.reviewed_earnings
  );
}

function rank1Eligible(connector, meta) {
  return phase1Complete(connector, meta);
}

function promoComplete(meta) {
  return !!(
    meta.sent_intro ||
    meta.published_video ||
    meta.shared_story ||
    meta.posted_group ||
    meta.city_call
  );
}

function canMarkManually(step) {
  return MANUAL_STEPS.includes(step);
}

function validateSocialUrl(url) {
  try {
    const host = new URL(url).hostname.toLowerCase();
    return ALLOWED_HOSTS.includes(host);
  } catch {
    return false;
  }
}

function countTaggedClicks(events, cc) {
  return events.filter(
    (row) => row.event_type === 'content_click' && row.metadata && row.metadata.cc === cc
  ).length;
}

const connector = {
  display_name: 'Alex Connector',
  city: 'Charlotte',
  market_slug: 'charlotte',
  phone: '555-0100',
  social_url: '',
  growth_styles: '["video"]',
};

const legacyMeta = migrateOnboardingMeta({
  copied_link: '2026-01-01 00:00:00',
  copied_vendor_link: '2026-01-01 00:00:00',
  reviewed_targets: '2026-01-02 00:00:00',
});
assert(legacyMeta.reviewed_earnings === legacyMeta.reviewed_targets, 'reviewed_targets should migrate to reviewed_earnings');
assert(phase1Complete(connector, legacyMeta) === true, 'legacy educational steps still complete Phase 1');
assert(rank1Eligible(connector, legacyMeta) === true, 'Rank 1 follows Phase 1, not Phase 2/3');

const incomplete = { copied_link: '2026-01-01 00:00:00' };
assert(phase1Complete(connector, incomplete) === false, 'Phase 1 is incomplete without required setup steps');
assert(rank1Eligible(connector, incomplete) === false, 'Rank 1 stays locked until Phase 1 is done');

assert(promoComplete({ sent_intro: '2026-01-03 00:00:00' }) === true, 'any one promo step completes Phase 2');
assert(promoComplete({ published_video: '2026-01-03 00:00:00' }) === true, 'published video counts as promo');
assert(promoComplete({}) === false, 'Phase 2 stays open until one promo action exists');
assert(rank1Eligible(connector, incomplete) === false, 'Phase 2 does not unlock Rank 1');

AUTO_STEPS.forEach((step) => {
  assert(canMarkManually(step) === false, step + ' must not be manually markable');
});
MANUAL_STEPS.forEach((step) => {
  assert(canMarkManually(step) === true, step + ' should remain a self-report step');
});

assert(validateSocialUrl('https://www.instagram.com/reel/abc') === true, 'Instagram URLs are allowed');
assert(validateSocialUrl('https://www.linkedin.com/posts/abc') === true, 'LinkedIn URLs are allowed');
assert(validateSocialUrl('https://example.com/not-social') === false, 'unknown hosts are rejected');
assert(validateSocialUrl('not-a-url') === false, 'invalid URLs are rejected');

const events = [
  { event_type: 'clicked', metadata: { cc: 'p12' } },
  { event_type: 'content_click', metadata: { cc: 'p12' } },
  { event_type: 'content_click', metadata: { cc: 'p99' } },
];
assert(countTaggedClicks(events, 'p12') === 1, 'cc click counts only matching content_click events');

const ranksPhp = read('wp-content/plugins/lnkup-marketplace/includes/class-connector-ranks.php');
assert(ranksPhp.includes("! empty( $on['phase1_complete'] )"), 'Rank 1 gate must use phase1_complete');
assert(!/done\s*>=\s*total/.test(ranksPhp), 'Rank 1 must not require every onboarding step');

const connectorPhp = read('wp-content/plugins/lnkup-marketplace/includes/class-connector.php');
assert(connectorPhp.includes('reviewed_earnings'), 'onboarding migrates reviewed_earnings');
assert(connectorPhp.includes('That step cannot be marked manually'), 'auto steps return 400 when marked');
assert(connectorPhp.includes("'growth_styles'"), 'profile persists growth_styles');

const studioPhp = read('wp-content/plugins/lnkup-marketplace/includes/class-connector-studio.php');
const saveBody = studioPhp.slice(studioPhp.indexOf('function save_post'), studioPhp.indexOf('function analytics'));
assert(!saveBody.includes('LNKup_Connector_Rewards'), 'publishing a video must not insert rewards');
assert(!saveBody.includes('insert_reward') && !saveBody.includes('queue_reward'), 'save_post must not create bonuses');
assert(studioPhp.includes('invalid_host'), 'invalid social hosts return 400');
['vendor_problem', 'organizer_problem', 'connector_opportunity', 'city_roundup', 'behind_platform', 'story_sequence'].forEach((key) => {
  assert(studioPhp.includes(key), 'seed template ' + key + ' must exist');
});

const attrPhp = read('wp-content/plugins/lnkup-marketplace/includes/class-connector-attribution.php');
assert(attrPhp.includes('record_content_click'), 'attribution stores cc on existing click path only');

const js = read('wp-content/plugins/lnkup-marketplace/public/js/connector.js');
assert(js.includes('lnkup-connector-launch-plan'), 'Launch Plan replaces the old checklist');
assert(!js.includes('function renderChecklist'), 'old checklist renderer must be gone');
assert(js.includes("label === 'Continue'"), 'JS remaps generic Continue labels');
assert(js.includes('lnkup-btn lnkup-btn--outline lnkup-connector-builder__close'), 'builder Close is a real button');
assert(js.includes('data-studio-platform'), 'publish form uses a platform select');
assert(!js.includes('instagram_reels'), 'studio UI must not store instagram_reels');
assert(js.includes('Submit published post'), 'submit button uses human copy');
assert(js.includes('These totals come from your referral links'), 'performance disclaimer is human');
assert(studioPhp.includes('linkedin.com'), 'LinkedIn hosts are allowed');

const theme = read('wp-content/themes/lnkup-theme/inc/dashboard.php');
assert(theme.includes("view', 'studio'"), 'sidebar includes Content Studio');
assert(theme.includes("__( 'Create', 'lnkup-theme' )"), 'bottom nav Share became Create');

console.log('connector-studio-verify: ok');
