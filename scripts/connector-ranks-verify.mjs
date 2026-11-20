/**
 * City Connector rank rules (mirrors LNKup_Connector_Ranks).
 * Dashboard JS must not copy these thresholds — GET /connector/rank is SoR.
 *
 * Run: node scripts/connector-ranks-verify.mjs
 */

const KEYS = ['city_connector', 'market_builder', 'city_captain', 'city_director', 'regional_partner'];

const DEFS = {
  city_connector: { activated: 0, bookings: 0, gmv: 0, recurring: 0, cities: 0, bonus: 0, onboarding: true },
  market_builder: { activated: 3, bookings: 15, gmv: 3000, recurring: 0, cities: 0, bonus: 100, onboarding: true },
  city_captain: { activated: 10, bookings: 60, gmv: 15000, recurring: 3, cities: 0, bonus: 250, onboarding: true },
  city_director: { activated: 25, bookings: 200, gmv: 50000, recurring: 8, cities: 0, bonus: 500, onboarding: true },
  regional_partner: { activated: 50, bookings: 500, gmv: 125000, recurring: 15, cities: 2, bonus: 1000, onboarding: true },
};

function rankIndex(key) {
  const i = KEYS.indexOf(key);
  return i === -1 ? -1 : i;
}

function boothGmv(payment) {
  const spot = Number(payment.vendor_spot_fee || 0);
  if (spot > 0) {
    return spot;
  }
  return Math.max(0, Number(payment.vendor_fee || 0));
}

function isTestPayment(payment, liveSite = true) {
  if (payment.test_mode) {
    return true;
  }
  const ids = [payment.intent_id, payment.charge_id, payment.provider_ref].filter(Boolean);
  if (ids.some((id) => String(id).startsWith('sim_'))) {
    return true;
  }
  if (liveSite && payment.livemode === false) {
    return true;
  }
  return false;
}

function isQualifyingPayment(payment, liveSite = true) {
  if (['refunded', 'failed', 'cancelled', 'disputed', 'chargeback'].includes(payment.status)) {
    return false;
  }
  if (payment.disputed || payment.chargeback) {
    return false;
  }
  if (isTestPayment(payment, liveSite)) {
    return false;
  }
  if (boothGmv(payment) <= 0) {
    return false;
  }
  if (payment.hold_elapsed === false) {
    return false;
  }
  return true;
}

function attributionWinner(orgReferred, vendorReferred) {
  if (orgReferred) {
    return 'organizer';
  }
  if (vendorReferred) {
    return 'vendor';
  }
  return '';
}

function countLedger(rows) {
  const metrics = {
    activated_organizers: 0,
    recurring_organizers: 0,
    qualifying_bookings: 0,
    qualifying_gmv: 0,
    approved_cities: 0,
  };
  const byOrg = {};
  const seenPayments = new Set();
  for (const row of rows) {
    if (row.status !== 'counted') {
      continue;
    }
    if (seenPayments.has(row.payment_id)) {
      continue;
    }
    seenPayments.add(row.payment_id);
    if (!isQualifyingPayment(row)) {
      continue;
    }
    metrics.qualifying_bookings += 1;
    metrics.qualifying_gmv += boothGmv(row);
    const orgId = row.organizer_id;
    if (!orgId) {
      continue;
    }
    if (!byOrg[orgId]) {
      byOrg[orgId] = { bookings: 0, opps: new Set(), published: !!row.published, verified: !!row.verified, market: row.market_slug };
    }
    byOrg[orgId].bookings += 1;
    if (row.opportunity_id) {
      byOrg[orgId].opps.add(row.opportunity_id);
    }
  }
  const cities = new Set();
  for (const info of Object.values(byOrg)) {
    const activated = info.verified && info.published && info.bookings >= 3;
    if (activated) {
      metrics.activated_organizers += 1;
      if (info.opps.size >= 2) {
        metrics.recurring_organizers += 1;
      }
      if (info.market) {
        cities.add(info.market);
      }
    }
  }
  metrics.approved_cities = cities.size;
  return metrics;
}

function meetsRank(def, metrics, approved, onboardingDone) {
  if (!approved) {
    return false;
  }
  if (def.onboarding && !onboardingDone) {
    return false;
  }
  if (metrics.activated_organizers < def.activated) {
    return false;
  }
  if (metrics.qualifying_bookings < def.bookings) {
    return false;
  }
  if (metrics.qualifying_gmv + 0.0001 < def.gmv) {
    return false;
  }
  if (metrics.recurring_organizers < def.recurring) {
    return false;
  }
  if (def.cities > 1 && metrics.approved_cities < def.cities) {
    return false;
  }
  return true;
}

function evaluate(metrics, approved, onboardingDone, current = '', highest = '') {
  let achieved = '';
  for (const key of KEYS) {
    if (!meetsRank(DEFS[key], metrics, approved, onboardingDone)) {
      break;
    }
    achieved = key;
  }
  let nextCurrent = current;
  if (achieved && rankIndex(achieved) > rankIndex(nextCurrent)) {
    nextCurrent = achieved;
  }
  let nextHighest = highest;
  if (nextCurrent && rankIndex(nextCurrent) > rankIndex(nextHighest)) {
    nextHighest = nextCurrent;
  }
  return {
    achieved,
    current: nextCurrent,
    highest: nextHighest,
    advanced: Boolean(nextCurrent && nextCurrent !== current),
  };
}

function bonusKey(connectorId, rankKey) {
  return `connector_rank_bonus:${connectorId}:${rankKey}`;
}

function queueBonus(existingKeys, connectorId, rankKey) {
  const key = bonusKey(connectorId, rankKey);
  if (existingKeys.has(key) || DEFS[rankKey].bonus <= 0) {
    return existingKeys.size;
  }
  existingKeys.add(key);
  return existingKeys.size;
}

const zero = {
  activated_organizers: 0,
  recurring_organizers: 0,
  qualifying_bookings: 0,
  qualifying_gmv: 0,
  approved_cities: 0,
};

const builderReady = {
  activated_organizers: 3,
  recurring_organizers: 0,
  qualifying_bookings: 15,
  qualifying_gmv: 3000,
  approved_cities: 1,
};

const justBelowBuilder = {
  ...builderReady,
  qualifying_gmv: 2999.99,
};

const checks = [
  ['approved + onboarding is City Connector', evaluate(zero, true, true).current === 'city_connector'],
  ['unapproved stays unranked', evaluate(zero, false, true).current === ''],
  ['onboarding incomplete stays unranked', evaluate(zero, true, false).current === ''],
  ['cannot skip to City Captain', evaluate({ ...builderReady, recurring_organizers: 3 }, true, true).current === 'market_builder'],
  ['all Market Builder requirements required', evaluate(justBelowBuilder, true, true).current === 'city_connector'],
  ['Market Builder boundary $3,000', evaluate(builderReady, true, true).current === 'market_builder'],
  ['activation needs 3 bookings', countLedger([
    { payment_id: 1, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 2, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).activated_organizers === 0],
  ['activation at 3 bookings', countLedger([
    { payment_id: 1, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 2, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 3, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).activated_organizers === 1],
  ['recurring needs 2 opportunities', countLedger([
    { payment_id: 1, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 2, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 3, status: 'counted', organizer_id: 9, opportunity_id: 2, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).recurring_organizers === 1],
  ['repeat bookings on one listing are not recurring', countLedger([
    { payment_id: 1, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 2, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 3, status: 'counted', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).recurring_organizers === 0],
  ['refunded booking excluded', countLedger([
    { payment_id: 1, status: 'reversed', organizer_id: 9, opportunity_id: 1, vendor_spot_fee: 250, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).qualifying_bookings === 0],
  ['chargeback excluded', isQualifyingPayment({ status: 'paid', chargeback: true, vendor_spot_fee: 250, hold_elapsed: true }) === false],
  ['sim_ test intent excluded', isTestPayment({ intent_id: 'sim_123' }) === true],
  ['live site + livemode false excluded', isTestPayment({ livemode: false }, true) === true],
  ['GMV uses booth fee not platform fee', boothGmv({ vendor_spot_fee: 250, lnkup_fee: 5.99, total_amount: 280 }) === 250],
  ['GMV falls back to vendor_fee', boothGmv({ vendor_fee: 175, lnkup_fee: 5.99 }) === 175],
  ['organizer wins when both referred', attributionWinner(true, true) === 'organizer'],
  ['vendor wins only if organizer was not referred', attributionWinner(false, true) === 'vendor'],
  ['same payment counted once', countLedger([
    { payment_id: 7, status: 'counted', organizer_id: 1, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
    { payment_id: 7, status: 'counted', organizer_id: 1, opportunity_id: 1, vendor_spot_fee: 100, verified: true, published: true, market_slug: 'charlotte', hold_elapsed: true },
  ]).qualifying_bookings === 1],
  ['bonus queued once per rank', (() => {
    const keys = new Set();
    queueBonus(keys, 4, 'market_builder');
    queueBonus(keys, 4, 'market_builder');
    return keys.size === 1 && keys.has('connector_rank_bonus:4:market_builder');
  })()],
  ['rank does not auto-decrease', evaluate(zero, true, true, 'market_builder', 'market_builder').current === 'market_builder'],
  ['founding is not a rank key', KEYS.includes('founding_partner') === false],
  ['City Connector has no cash bonus', DEFS.city_connector.bonus === 0],
];

let failed = 0;
for (const [name, ok] of checks) {
  if (!ok) {
    console.error('FAIL', name);
    failed += 1;
  } else {
    console.log('ok', name);
  }
}

if (failed) {
  process.exit(1);
}
console.log('connector-ranks-verify: all checks passed');
