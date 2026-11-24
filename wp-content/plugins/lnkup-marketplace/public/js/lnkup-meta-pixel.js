/**
 * LNKup Meta Pixel.
 *
 * Initializes the Pixel once, fires PageView, replays server-recorded conversions with
 * their server event_id (browser/server deduplication), and exposes
 * window.lnkupMeta.track() for client events (ViewContent, Search, registration started)
 * which are mirrored to the Conversions API with the same event_id.
 *
 * Respects Global Privacy Control and the lnkup_tracking_optout cookie even on cached pages.
 */
(function () {
  'use strict';

  var cfg = window.lnkupMeta;
  if (!cfg || !cfg.pixelId) return;

  function refused() {
    if (navigator.globalPrivacyControl === true) return true;
    return /(?:^|;\s*)lnkup_tracking_optout=1(?:;|$)/.test(document.cookie || '');
  }
  if (refused()) return;

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  var conflict = typeof window.fbq === 'function';

  if (!conflict) {
    /* Meta Pixel base code (https://developers.facebook.com/docs/meta-pixel/get-started). */
    (function (f, b, e, v, n, t, s) {
      if (f.fbq) return;
      n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!f._fbq) f._fbq = n;
      n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
      t = b.createElement(e); t.async = true; t.src = v;
      t.onerror = function () { cfg.loadFailed = true; };
      s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

    if (cfg.dpo && cfg.dpo.options) {
      window.fbq('dataProcessingOptions', cfg.dpo.options, cfg.dpo.country, cfg.dpo.state);
    }
    window.fbq('init', cfg.pixelId, cfg.advancedMatching || {});
    window.fbq('track', 'PageView', {}, { eventID: uuid() });
  } else {
    reportConflict('fbq_predefined');
  }

  function isStandard(name) {
    return (cfg.standardEvents || []).indexOf(name) !== -1;
  }

  function fire(name, data, eventId, standard) {
    if (typeof window.fbq !== 'function' || !eventId) return;
    var std = typeof standard === 'boolean' ? standard : isStandard(name);
    var payload = data || {};
    try {
      if (conflict) {
        window.fbq(std ? 'trackSingle' : 'trackSingleCustom', cfg.pixelId, name, payload, { eventID: eventId });
      } else {
        window.fbq(std ? 'track' : 'trackCustom', name, payload, { eventID: eventId });
      }
    } catch (err) {
      if (window.console) console.error('LNKup Meta event failed', err);
    }
  }

  function headers() {
    var h = { 'Content-Type': 'application/json' };
    if (cfg.nonce) h['X-WP-Nonce'] = cfg.nonce;
    return h;
  }

  function post(path, body) {
    if (!cfg.restUrl || typeof fetch !== 'function') return;
    fetch(cfg.restUrl + path, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: headers(),
      body: JSON.stringify(body)
    }).catch(function () { /* advertising beacons never surface errors */ });
  }

  function reportConflict(reason) {
    try {
      if (window.sessionStorage && sessionStorage.getItem('lnkupMetaConflict')) return;
      if (window.sessionStorage) sessionStorage.setItem('lnkupMetaConflict', '1');
    } catch (e) { /* storage blocked */ }
    post('meta/conflict', { reason: reason });
  }

  (cfg.pending || []).forEach(function (evt) {
    fire(evt.name, evt.data, evt.eventId, evt.standard);
  });

  cfg.track = function (name, data) {
    if ((cfg.clientEvents || []).indexOf(name) === -1) return;
    var eventId = uuid();
    fire(name, data, eventId);
    if (cfg.capi) {
      post('meta/event', { event_name: name, event_id: eventId, custom_data: data || {}, source_url: window.location.href.split('?')[0] });
    }
  };

  /** Fetch conversions the server recorded after this page loaded (e.g. after a save). */
  cfg.flush = function () {
    if (!cfg.loggedIn || !cfg.restUrl || typeof fetch !== 'function') return;
    fetch(cfg.restUrl + 'meta/pending', { credentials: 'same-origin', headers: headers() })
      .then(function (res) {
        var type = res.headers.get('content-type') || '';
        return res.ok && type.indexOf('application/json') !== -1 ? res.json() : null;
      })
      .then(function (json) {
        if (json && Array.isArray(json.events)) {
          json.events.forEach(function (evt) { fire(evt.name, evt.data, evt.eventId, evt.standard); });
        }
      })
      .catch(function () { /* ignore */ });
  };

  if (cfg.viewContent) cfg.track('ViewContent', cfg.viewContent);

  if (cfg.registrationRole) {
    var started = false;
    document.addEventListener('focusin', function (e) {
      if (started || !e.target || !e.target.closest) return;
      var form = e.target.closest('form.lnkup-signup-form');
      if (!form) return;
      started = true;
      var picked = form.querySelector('input[name="lnkup_role"]:checked');
      var role = picked ? picked.value : cfg.registrationRole;
      var organizer = role === 'lnkup_organizer' || role === 'organizer';
      cfg.track(organizer ? 'OrganizerRegistrationStarted' : 'VendorRegistrationStarted', { marketplace_role: organizer ? 'organizer' : 'vendor' });
    });
  }

  window.addEventListener('load', function () {
    var tags = document.querySelectorAll('script[src*="connect.facebook.net"][src*="fbevents"]');
    if (tags.length > 1) reportConflict('multiple_fbevents');
  });
})();
