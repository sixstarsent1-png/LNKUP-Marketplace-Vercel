/**
 * City Connector earnings formula checks (mirrors LNKup_Connector_Rewards).
 * Live dashboard must POST /connector/calculator — do not copy this into UI.
 *
 * Run: node scripts/connector-earnings-verify.mjs
 */
const percent = 10;
const cap = 0;

function amountFromGmv(boothGmv) {
  if (boothGmv <= 0 || percent <= 0) {
    return 0;
  }
  let amount = Math.round(boothGmv * (percent / 100) * 100) / 100;
  if (cap > 0) {
    amount = Math.min(amount, cap);
  }
  return amount;
}

function estimate(boothPrice, paidBookings, organizers = 1) {
  const orgs = Math.max(1, organizers);
  const per = amountFromGmv(boothPrice);
  return {
    gmv: Math.round(boothPrice * paidBookings * orgs * 100) / 100,
    earnings: Math.round(per * paidBookings * orgs * 100) / 100,
    per_organizer: Math.round((per * paidBookings * orgs) / orgs * 100) / 100,
  };
}

const checks = [
  ['$250 booth is $25', amountFromGmv(250) === 25],
  ['free booth is $0', amountFromGmv(0) === 0],
  ['20 bookings estimated $500', estimate(250, 20, 1).earnings === 500],
  ['100 bookings estimated $2500', estimate(250, 100, 1).earnings === 2500],
  ['two organizers double GMV', estimate(250, 20, 2).gmv === 10000],
  ['cap 0 does not clip $80 booth at 10%', amountFromGmv(800) === 80],
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
console.log('connector-earnings-verify: all checks passed');
