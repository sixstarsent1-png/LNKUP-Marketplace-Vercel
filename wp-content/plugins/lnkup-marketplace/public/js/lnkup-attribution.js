/**
 * LNKup first-party acquisition attribution.
 *
 * Runs on cached pages too. Writes lnkup_ft (first touch, once) and lnkup_lt (last
 * non-direct touch). A direct visit never replaces an existing touch. Honors Global
 * Privacy Control and the lnkup_tracking_optout cookie. No fingerprinting.
 */
(function () {
  'use strict';

  if (navigator.globalPrivacyControl === true) return;
  if (/(?:^|;\s*)lnkup_tracking_optout=1(?:;|$)/.test(document.cookie || '')) return;

  var PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'campaign_id', 'adset_id', 'ad_id', 'placement', 'fbclid', 'gclid', 'ttclid', 'ref', 'org_ref', 'cc', 'partner', 'lnk'];
  var LONG = ['fbclid', 'gclid', 'ttclid'];

  function device() {
    var ua = navigator.userAgent || '';
    if (/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i.test(ua)) return 'tablet';
    return /Mobi|iPhone|Android/i.test(ua) ? 'mobile' : 'desktop';
  }
  var IGNORED = ['stripe.com', 'stripe.network', 'paypal.com', 'accounts.google.com', 'appleid.apple.com'];
  var TTL = 90 * 24 * 60 * 60;

  function bare(host) {
    return String(host || '').toLowerCase().replace(/^www\./, '');
  }

  function internal(host) {
    var h = bare(host);
    if (!h || h === bare(window.location.hostname)) return true;
    return IGNORED.some(function (d) { return h === d || h.slice(-(d.length + 1)) === '.' + d; });
  }

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? match[1] : '';
  }

  function writeCookie(name, touch) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(JSON.stringify(touch)) + '; Max-Age=' + TTL + '; Path=/; SameSite=Lax' + secure;
  }

  var query = new URLSearchParams(window.location.search);
  var touch = {};
  var campaign = false;
  PARAMS.forEach(function (key) {
    var value = query.get(key);
    if (value) {
      touch[key] = value.slice(0, LONG.indexOf(key) > -1 ? 500 : 150);
      campaign = true;
    }
  });

  var refHost = '';
  try { refHost = document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) { refHost = ''; }
  var external = refHost && !internal(refHost);
  if (external) touch.referrer = bare(refHost).slice(0, 100);

  if (!campaign && !external) {
    if (!readCookie('lnkup_ft')) writeCookie('lnkup_ft', { landing: window.location.pathname.slice(0, 200), ts: Math.floor(Date.now() / 1000) });
    return;
  }

  touch.landing = window.location.pathname.slice(0, 200);
  touch.ts = Math.floor(Date.now() / 1000);
  touch.device = device();
  var market = query.get('market') || (window.location.pathname.match(/^\/markets\/([a-z0-9-]{1,60})\//) || [])[1];
  if (market) touch.market = String(market).toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 60);

  if (!readCookie('lnkup_ft')) writeCookie('lnkup_ft', touch);
  writeCookie('lnkup_lt', touch);
})();
