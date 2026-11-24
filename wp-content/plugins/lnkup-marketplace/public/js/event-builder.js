/**
 * LNKup Event Creation OS 3.0 — organizer builder app.
 *
 * Screens: method chooser, five-step builder (info, options, applications, payments, publish),
 * publish success, Edit 2.0 review. Later phases register extra screens on LNKupBuilder.screens
 * (import, bulk, recurring, tour, series, imports center).
 *
 * Talks only to lnkup/v1/organizer/builder/*, /locations/* and import routes. Autosave sends a
 * revision with every change; the server rejects stale revisions so nothing is silently lost.
 */
(function () {
  'use strict';

  var cfg = window.lnkupEventBuilder || {};
  var root = document.querySelector('[data-testid="lnkup-event-builder"]');
  if (!root || !cfg.rest) return;
  // wp_localize_script sends every scalar as a string ("0" is truthy).
  ['draft', 'edit', 'duplicate'].forEach(function (k) { cfg[k] = parseInt(cfg[k], 10) || 0; });
  if (cfg.flags) Object.keys(cfg.flags).forEach(function (k) { cfg.flags[k] = cfg.flags[k] === true || cfg.flags[k] === '1'; });

  /* ------------------------------------------------------------------ helpers */

  function h(tag, props, children) {
    var el = document.createElement(tag);
    props = props || {};
    Object.keys(props).forEach(function (key) {
      var val = props[key];
      if (val === null || val === undefined || val === false) return;
      if (key === 'class') el.className = val;
      else if (key === 'text') el.textContent = val;
      else if (key === 'style') el.setAttribute('style', val);
      else if (key.indexOf('on') === 0 && typeof val === 'function') el.addEventListener(key.slice(2).toLowerCase(), val);
      else if (key === 'value') el.value = val;
      else if (key === 'checked') el.checked = !!val;
      else if (key === 'selected') el.selected = !!val;
      else if (key === 'disabled') el.disabled = !!val;
      else el.setAttribute(key, val === true ? '' : String(val));
    });
    append(el, children);
    return el;
  }
  function append(el, children) {
    if (children === null || children === undefined || children === false) return el;
    if (!Array.isArray(children)) children = [children];
    children.forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      if (Array.isArray(c)) return append(el, c);
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return el;
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
  function uid(prefix) { return (prefix || 'k') + Math.random().toString(36).slice(2, 9); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function money(n) {
    var v = Number(n || 0);
    return '$' + v.toLocaleString(undefined, { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
  }
  function debounce(fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; }
  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso + 'T12:00:00');
    return isNaN(d) ? iso : d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }
  function ago(iso) {
    var d = new Date(iso); if (isNaN(d)) return '';
    var s = Math.round((Date.now() - d.getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    return d.toLocaleDateString();
  }
  function toast(msg, tone) {
    if (window.LNKupOS && typeof window.LNKupOS.toast === 'function') return window.LNKupOS.toast(msg, tone);
    var el = h('div', { class: 'eb-toast' + (tone === 'error' ? ' eb-toast--error' : ''), role: 'status', text: msg });
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }
  function friendly(status) {
    if (status === 401 || status === 403) return 'Your session expired or you do not have access. Sign in again and retry.';
    if (status === 404) return 'We could not find that. It may have been removed.';
    if (status === 429) return 'Too many requests. Wait a moment and try again.';
    if (status >= 500) return 'Something went wrong on our side. Your work is saved; try again in a moment.';
    return 'That did not work. Check your connection and try again.';
  }
  function api(path, opts) {
    opts = opts || {};
    var url = cfg.rest + path.replace(/^\//, '');
    if (opts.query) {
      var q = new URLSearchParams();
      Object.keys(opts.query).forEach(function (k) { if (opts.query[k] !== undefined && opts.query[k] !== null) q.append(k, opts.query[k]); });
      url += (url.indexOf('?') >= 0 ? '&' : '?') + q.toString();
    }
    var isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
    var headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
    if (opts.body && !isForm) headers['Content-Type'] = 'application/json';
    return fetch(url, { method: opts.method || 'GET', credentials: 'same-origin', headers: headers, body: opts.body ? (isForm ? opts.body : JSON.stringify(opts.body)) : undefined })
      .catch(function () { var e = new Error('You appear to be offline. Your changes are kept on this device until you reconnect.'); e.status = 0; throw e; })
      .then(function (res) {
        var type = res.headers.get('content-type') || '';
        var parse = type.indexOf('application/json') >= 0 ? res.json() : Promise.resolve(null);
        return parse.then(function (data) {
          if (!res.ok) {
            var err = new Error((data && data.message) || friendly(res.status));
            err.status = res.status; err.code = data && data.code; err.data = data && data.data;
            throw err;
          }
          if (data === null) { var e2 = new Error('Unexpected response. Try again.'); e2.status = res.status; throw e2; }
          return data;
        });
      });
  }
  function track(event, metadata) {
    api('marketplace/track/event', { method: 'POST', body: { event_type: event, data: metadata || {} } }).catch(function () {});
  }

  var ICONS = {
    import: '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
    single: '<rect x="3.5" y="5" width="17" height="15" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 9.5h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    quick: '<path d="M13 2L4.5 13.5H11l-1 8.5L19.5 10H13l0-8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
    recurring: '<path d="M4 12a8 8 0 0113.7-5.6L20 8.5M20 4v4.5h-4.5M20 12a8 8 0 01-13.7 5.6L4 15.5M4 20v-4.5h4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
    tour: '<path d="M12 21s-6-5.3-6-10.5a6 6 0 1112 0C18 15.7 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="10.5" r="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    series: '<rect x="3" y="4" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="4" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    duplicate: '<rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    template: '<path d="M5 4h14v4H5zM5 11h6v9H5zM14 11h5v9h-5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
    bulk: '<path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M18 15v6m-3-3h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    draft: '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v5h5M9 13h6M9 17h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    up: '<path d="M12 19V5m0 0l-6 6m6-6l6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
    down: '<path d="M12 5v14m0 0l-6-6m6 6l6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
    back: '<path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
    close: '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    search: '<circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    send: '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4 18-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
    grid: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/>',
    tool: '<path d="M14.5 6.5a4 4 0 00-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 005.3-5.3l-2.5 2.5-2.5-.5-.5-2.5 2.5-2.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
  };
  function icon(name) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = ICONS[name] || ''; // Static, trusted markup from ICONS only.
    return svg;
  }
  var CAT_EMOJI = { 'Food Trucks': '🚚', Food: '🍔', Desserts: '🧁', Drinks: '🥤', Retail: '🛍️', Clothing: '👕', Beauty: '💄', Jewelry: '💍', Art: '🎨', 'Photo Booth': '📸', Services: '🧰', Wellness: '🌿', Merchandise: '🏷️' };
  var PROVIDERS = [
    ['posh.vip', 'POSH'], ['eventbrite.', 'Eventbrite'], ['eventeny.com', 'Eventeny'], ['ticketleap.', 'TicketLeap'], ['lu.ma', 'Luma'], ['luma.com', 'Luma'],
    ['marketspread.com', 'Marketspread'], ['tickettailor.com', 'Ticket Tailor'], ['humanitix.com', 'Humanitix'], ['partiful.com', 'Partiful'], ['dice.fm', 'DICE'], ['tixr.com', 'Tixr'],
    ['feverup.com', 'Fever'], ['shotgun.live', 'Shotgun'], ['ra.co', 'Resident Advisor'], ['ticketmaster.', 'Ticketmaster'], ['axs.com', 'AXS'], ['seatgeek.com', 'SeatGeek'],
    ['eventzilla.net', 'Eventzilla'], ['cvent.com', 'Cvent'], ['whova.com', 'Whova'], ['facebook.com', 'Facebook'], ['meetup.com', 'Meetup']
  ];
  function providerFor(url) {
    try {
      var host = new URL(url).hostname.replace(/^www\./, '');
      for (var i = 0; i < PROVIDERS.length; i++) if (host.indexOf(PROVIDERS[i][0]) >= 0) return { key: PROVIDERS[i][1].toLowerCase().replace(/\s+/g, '_'), label: PROVIDERS[i][1], host: host };
      return { key: 'website', label: 'Event website', host: host };
    } catch (e) { return null; }
  }

  /* ------------------------------------------------------------------ store */

  var STEPS = [
    { key: 'info', label: 'Event information' },
    { key: 'options', label: 'Vendor opportunities' },
    { key: 'applications', label: 'Applications' },
    { key: 'payments', label: 'Payments & policies' },
    { key: 'publish', label: 'Preview & publish' }
  ];
  var QUICK_STEPS = ['info', 'options', 'publish'];

  var S = {
    config: null, draft: null, report: null, fees: [], step: 'info', pending: {}, saving: false, again: false, timer: null,
    saveState: 'saved', openOption: null, optionsView: 'cards', previewMode: 'vendor', device: 'desktop', preview: null, conflict: false
  };
  var Builder = window.LNKupBuilder = { screens: {}, api: api, h: h, icon: icon, toast: toast, S: S, cfg: cfg, go: null, openDraft: null, providerFor: providerFor, fmtDate: fmtDate, money: money, uid: uid, clone: clone };

  function steps() {
    var keys = S.draft && S.draft.state.quick ? QUICK_STEPS : STEPS.map(function (s) { return s.key; });
    return STEPS.filter(function (s) { return keys.indexOf(s.key) >= 0; });
  }
  function isEdit() { return !!(S.draft && S.draft.source_event); }

  function mergePatch(a, b) {
    var out = clone(a || {});
    Object.keys(b || {}).forEach(function (k) {
      if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && out[k] && typeof out[k] === 'object' && !Array.isArray(out[k])) out[k] = mergePatch(out[k], b[k]);
      else out[k] = clone(b[k]);
    });
    return out;
  }
  function getPath(obj, path) { return path.split('.').reduce(function (o, k) { return o ? o[k] : undefined; }, obj); }
  function setPath(obj, path, value) {
    var parts = path.split('.'); var o = obj;
    for (var i = 0; i < parts.length - 1; i++) { if (!o[parts[i]] || typeof o[parts[i]] !== 'object') o[parts[i]] = {}; o = o[parts[i]]; }
    o[parts[parts.length - 1]] = value;
  }
  function patchFor(path, value) { var p = {}; setPath(p, path, value); return p; }

  /** Change a field: updates local state, queues a patch, refreshes the rail. */
  function set(path, value, opts) {
    setPath(S.draft.state, path, value);
    var top = path.split('.')[0];
    var listRoots = { options: 1, sources: 1 };
    if (listRoots[top]) S.pending = mergePatch(S.pending, patchFor(top, clone(S.draft.state[top])));
    else if (path.indexOf('applications.questions') === 0) S.pending = mergePatch(S.pending, { applications: { questions: clone(S.draft.state.applications.questions) } });
    else if (path.indexOf('applications.documents') === 0) S.pending = mergePatch(S.pending, { applications: { documents: clone(S.draft.state.applications.documents) } });
    else if (path === 'event.gallery_ids') S.pending = mergePatch(S.pending, { event: { gallery_ids: clone(value) } });
    else if (path === 'confirmed') S.pending = mergePatch(S.pending, { confirmed: clone(value) });
    else S.pending = mergePatch(S.pending, patchFor(path, value));
    setSave('dirty');
    backup();
    clearTimeout(S.timer);
    S.timer = setTimeout(flush, opts && opts.now ? 0 : 900);
    renderRail();
  }
  Builder.set = set;

  function backupKey() { return 'lnkup_eb_backup_' + (S.draft ? S.draft.id : 0); }
  function backup() {
    try { localStorage.setItem(backupKey(), JSON.stringify({ at: Date.now(), revision: S.draft.revision, pending: S.pending, state: S.draft.state })); } catch (e) { /* storage full or disabled */ }
  }
  function dropBackup() { try { localStorage.removeItem(backupKey()); } catch (e) { /* ignore */ } }

  function setSave(state) {
    S.saveState = state;
    var pill = root.querySelector('[data-eb-save]');
    if (!pill) return;
    pill.setAttribute('data-state', state);
    clear(pill);
    var labels = { saved: 'Saved', saving: 'Saving…', dirty: 'Unsaved changes', error: 'Not saved' };
    append(pill, labels[state] || 'Saved');
    if (state === 'error') append(pill, h('button', { type: 'button', class: 'eb-link', style: 'margin-left:6px', text: 'Retry', onclick: function () { flush(); } }));
  }

  function flush() {
    clearTimeout(S.timer);
    if (!S.draft) return Promise.resolve();
    if (S.saving) { S.again = true; return S.savePromise || Promise.resolve(); }
    var patch = S.pending;
    if (!Object.keys(patch).length) { setSave('saved'); return Promise.resolve(); }
    S.pending = {};
    S.saving = true;
    setSave('saving');
    S.savePromise = api('organizer/builder/drafts/' + S.draft.id, { method: 'PATCH', body: { revision: S.draft.revision, patch: patch } })
      .then(function (r) {
        S.draft.revision = r.draft.revision;
        S.draft.updated_at = r.draft.updated_at;
        if (!Object.keys(S.pending).length) S.draft.state = r.draft.state;
        S.report = r.report; S.fees = r.fees || [];
        setSave(Object.keys(S.pending).length ? 'dirty' : 'saved');
        if (!Object.keys(S.pending).length) dropBackup();
        afterReport();
      })
      .catch(function (e) {
        S.pending = mergePatch(patch, S.pending);
        backup();
        if (e.status === 409) { S.conflict = true; showConflict(); setSave('error'); }
        else { setSave('error'); if (e.status !== 0) toast(e.message, 'error'); }
      })
      .then(function () {
        S.saving = false;
        if (S.again) { S.again = false; return flush(); }
      });
    return S.savePromise;
  }
  Builder.flush = flush;

  window.addEventListener('beforeunload', function (e) {
    if (S.draft && (Object.keys(S.pending).length || S.saving)) { backup(); e.preventDefault(); e.returnValue = ''; }
  });
  window.addEventListener('online', function () { if (S.draft && Object.keys(S.pending).length) flush(); });

  /* ------------------------------------------------------------------ routing */

  function url(params) {
    var u = new URL(window.location.href);
    ['draft', 'step', 'edit', 'duplicate', 'template', 'method', 'url'].forEach(function (k) { u.searchParams.delete(k); });
    Object.keys(params || {}).forEach(function (k) { if (params[k]) u.searchParams.set(k, params[k]); });
    return u.toString();
  }
  function navigate(params, replace) {
    var next = url(params);
    if (replace) window.history.replaceState({ eb: params }, '', next);
    else window.history.pushState({ eb: params }, '', next);
  }
  window.addEventListener('popstate', function () { boot(true); });

  /** Keep the sticky action bar above the dashboard's mobile bottom navigation. */
  function syncBottomOffset() {
    var nav = document.getElementById('lnkup-bottom-nav');
    var visible = nav && window.getComputedStyle(nav).display !== 'none' && nav.getBoundingClientRect().height > 0;
    root.style.setProperty('--eb-bottom-offset', visible ? Math.ceil(nav.getBoundingClientRect().height) + 'px' : '0px');
  }
  window.addEventListener('resize', debounce(syncBottomOffset, 150));

  function screen(node) {
    clear(root);
    append(root, node);
    syncBottomOffset();
    window.scrollTo({ top: 0, behavior: 'auto' });
  }
  Builder.screen = screen;

  function loading(label) {
    return h('div', { class: 'eb-boot' }, [h('p', { class: 'eb-boot__title', text: label || 'Loading…' }), h('div', { class: 'eb-skeleton', 'aria-hidden': 'true' }, [h('span'), h('span'), h('span')])]);
  }
  function errorCard(message, retry) {
    return h('div', { class: 'eb-card', role: 'alert' }, [
      h('div', { class: 'eb-card__head' }, [h('div', {}, [h('h2', { text: 'We could not load this' }), h('p', { text: message })])]),
      h('div', { class: 'eb-notice__actions' }, [
        retry ? h('button', { type: 'button', class: 'eb-btn eb-btn--dark', text: 'Try again', onclick: retry }) : null,
        h('a', { class: 'eb-btn', href: cfg.urls.create, text: 'Back to Create' })
      ])
    ]);
  }
  Builder.errorCard = errorCard;
  Builder.loading = loading;

  function loadConfig() {
    if (S.config) return Promise.resolve(S.config);
    return api('organizer/builder/config').then(function (c) { S.config = c; return c; });
  }
  Builder.loadConfig = loadConfig;

  function boot(fromPop) {
    var p = new URL(window.location.href).searchParams;
    var draft = parseInt(p.get('draft') || cfg.draft || 0, 10);
    var edit = parseInt(p.get('edit') || 0, 10) || (fromPop ? 0 : cfg.edit);
    var method = p.get('method') || (fromPop ? '' : cfg.method);
    screen(loading(cfg.view === 'imports' ? 'Event imports' : 'Create'));
    loadConfig().then(function () {
      if (cfg.view === 'imports' && Builder.screens.importsCenter) return Builder.screens.importsCenter();
      if (draft) return openDraft(draft, p.get('step') || cfg.step);
      if (edit) return startEdit(edit);
      if (!fromPop && cfg.duplicate) return duplicateDialog(cfg.duplicate);
      if (!fromPop && cfg.template) return createDraft({ method: 'template', template_id: cfg.template });
      if (method && Builder.screens[method]) return Builder.screens[method]();
      return chooser();
    }).catch(function (e) { screen(errorCard(e.message, function () { boot(); })); });
  }
  Builder.go = boot;

  /* ------------------------------------------------------------------ chooser */

  function methodCard(opts) {
    var tag = opts.href ? 'a' : 'button';
    return h(tag, {
      class: 'eb-method' + (opts.lead ? ' eb-method--lead' : '') + (opts.dark ? ' eb-method--dark' : ''),
      type: opts.href ? null : 'button', href: opts.href || null, 'data-method': opts.key,
      onclick: opts.onclick || null
    }, [
      h('span', { class: 'eb-method__icon' }, icon(opts.icon)),
      h('span', {}, [
        h('span', { class: 'eb-method__title' }, [opts.title, opts.badge ? h('span', { class: 'eb-chip eb-chip--lime', text: opts.badge }) : null]),
        h('span', { class: 'eb-method__desc', text: opts.desc }),
        opts.meta ? h('span', { class: 'eb-method__meta' }, opts.meta) : null
      ])
    ]);
  }

  function chooser() {
    var flags = (S.config && S.config.flags) || cfg.flags || {};
    var portfolio = S.config && S.config.portfolio && flags.portfolio_builder_v3;
    var wrap = h('div', { class: 'eb-chooser', 'data-testid': 'eb-chooser' });
    var recoBox = h('div', { class: 'eb-reco', 'data-testid': 'eb-reco' });
    var draftsBox = h('div', { class: 'eb-drafts', 'data-testid': 'eb-drafts' }, [h('div', { class: 'eb-skeleton' }, [h('span')])]);
    var badge = {};

    append(wrap, [
      h('div', { class: 'eb-hero' }, [
        h('div', {}, [
          h('span', { class: 'eb-eyebrow', text: 'Create' }),
          h('h1', { text: 'How would you like to start?' }),
          h('p', { text: 'Bring in an event you already list elsewhere, or build a new one. Keep your ticketing platform — manage your vendors with LNKup.' })
        ]),
        h('a', { class: 'eb-btn', href: cfg.urls.events, text: 'Back to events' })
      ]),
      recoBox
    ]);

    var lead = h('div', { class: 'eb-methods eb-methods--lead' });
    if (flags.event_import_v3) {
      append(lead, methodCard({ key: 'import', icon: 'import', lead: true, dark: true, title: 'Import an existing event', desc: 'Paste a POSH, Eventbrite, Eventeny, TicketLeap, Luma or website link. We bring in what the listing shares; you add vendor options.', meta: ['POSH', 'Eventbrite', 'Eventeny', 'Website'].map(function (t) { return h('span', { class: 'eb-chip', text: t }); }), onclick: function () { navigate({ method: 'import' }); boot(); } }));
    }
    append(lead, methodCard({ key: 'quick', icon: 'quick', lead: true, title: 'Quick create', badge: badge.quick, desc: 'Name, date, place and one vendor option. Publish in about three minutes; add details any time.', onclick: function () { createDraft({ method: 'single', quick: true }); } }));
    append(lead, methodCard({ key: 'single', icon: 'single', lead: true, title: 'Create a single event', desc: 'The full guided builder: imagery, multiple vendor options, applications, payments and a live preview.', onclick: function () { createDraft({ method: 'single' }); } }));
    append(wrap, h('section', { class: 'eb-section' }, lead));

    if (portfolio) {
      append(wrap, h('section', { class: 'eb-section' }, [
        h('div', { class: 'eb-section__head' }, [h('h2', { text: 'More than one date' }), h('p', { text: 'Each date keeps its own vendors, payments and day-of operations.' })]),
        h('div', { class: 'eb-methods' }, [
          methodCard({ key: 'recurring', icon: 'recurring', title: 'Recurring event', desc: 'Weekly markets, monthly pop-ups. Set the rule, review every date.', onclick: function () { navigate({ method: 'recurring' }); boot(); } }),
          methodCard({ key: 'tour', icon: 'tour', title: 'Multi-city tour', desc: 'One tour, many cities. Shared vendor setup with per-stop overrides.', onclick: function () { navigate({ method: 'tour' }); boot(); } }),
          methodCard({ key: 'series', icon: 'series', title: 'Event series', desc: 'Related events with their own dates and venues — no fixed schedule.', onclick: function () { navigate({ method: 'series' }); boot(); } })
        ])
      ]));
    }

    var reuse = [
      methodCard({ key: 'duplicate', icon: 'duplicate', title: 'Duplicate an event', desc: 'Reuse a past event\'s setup. Bookings and payments are never copied.', onclick: function () { duplicateDialog(0); } }),
      methodCard({ key: 'template', icon: 'template', title: 'Use a template', desc: 'Start from your saved setups or a starter structure.', onclick: function () { libraryDialog(); } })
    ];
    if (flags.event_import_bulk) reuse.push(methodCard({ key: 'bulk', icon: 'bulk', title: 'Bulk import', desc: 'Several links, a spreadsheet or a calendar file at once.', onclick: function () { navigate({ method: 'bulk' }); boot(); } }));
    append(wrap, h('section', { class: 'eb-section' }, [
      h('div', { class: 'eb-section__head' }, [h('h2', { text: 'Reuse what you have' })]),
      h('div', { class: 'eb-methods' }, reuse)
    ]));

    append(wrap, h('section', { class: 'eb-section' }, [
      h('div', { class: 'eb-section__head' }, [h('h2', { text: 'Continue a draft' }), h('p', { text: 'Drafts are private until you publish.' })]),
      draftsBox
    ]));
    screen(wrap);

    api('organizer/builder/recommendations').then(function (r) {
      clear(recoBox);
      (r.recommended || []).forEach(function (rec) {
        var label = { import: 'Import', continue: 'Continue a draft', tour: 'Add a tour stop', template: 'Use a template', duplicate: 'Duplicate' }[rec.method] || rec.method;
        append(recoBox, h('div', { class: 'eb-reco__item' }, [h('span', { class: 'eb-chip eb-chip--lime', text: 'Suggested' }), h('div', {}, [h('strong', { text: label }), h('p', { text: rec.reason })])]));
      });
      renderDrafts(draftsBox, r.drafts || []);
      S.recent = r.recent_events || [];
      S.library = r.library || null;
    }).catch(function () {
      api('organizer/builder/drafts').then(function (d) { renderDrafts(draftsBox, d.drafts || []); }).catch(function (e) { clear(draftsBox); append(draftsBox, h('p', { class: 'eb-error', text: e.message })); });
    });
  }
  Builder.screens.chooser = chooser;

  function renderDrafts(box, drafts) {
    clear(box);
    if (!drafts.length) {
      append(box, h('div', { class: 'eb-card eb-card--flat' }, [h('p', { class: 'eb-help', text: 'No drafts yet. Anything you start is saved here automatically.' })]));
      return;
    }
    drafts.forEach(function (d) {
      append(box, h('div', { class: 'eb-draft', 'data-testid': 'eb-draft-row' }, [
        h('div', {}, [
          h('p', { class: 'eb-draft__title', text: d.title || 'Untitled event' }),
          h('p', { class: 'eb-draft__meta' }, [
            h('span', { text: (d.editing ? 'Editing a live event' : ({ single: 'Single event', template: 'From template', duplicate: 'Copy', import: 'Imported', recurring: 'Recurring', tour: 'Tour', series: 'Series' }[d.method] || 'Draft')) }),
            h('span', { text: 'Edited ' + ago(d.updated_at) }),
            h('span', {}, [h('span', { class: 'eb-meter' }, h('span', { style: 'width:' + d.progress + '%' })), ' ' + d.progress + '%']),
            d.source ? h('span', { class: 'eb-chip eb-chip--line', text: d.source }) : null
          ]),
          d.missing && d.missing.length ? h('p', { class: 'eb-help', text: 'Still needed: ' + d.missing.join(', ') }) : null
        ]),
        h('div', { class: 'eb-draft__actions' }, [
          h('button', { type: 'button', class: 'eb-btn eb-btn--dark eb-btn--sm', text: 'Continue', onclick: function () { openDraft(d.id); } }),
          h('button', { type: 'button', class: 'eb-btn eb-btn--sm eb-btn--danger', text: 'Delete', 'aria-label': 'Delete draft ' + (d.title || 'Untitled'), onclick: function () {
            confirmDialog('Delete this draft?', 'This removes the unpublished draft. Published events are not affected.', 'Delete draft').then(function (ok) {
              if (!ok) return;
              api('organizer/builder/drafts/' + d.id, { method: 'DELETE' }).then(function () { toast('Draft deleted.'); chooser(); }).catch(function (e) { toast(e.message, 'error'); });
            });
          } })
        ])
      ]));
    });
  }

  function createDraft(body) {
    screen(loading('Setting up your draft…'));
    track('event_creation_started', { method: body.method || 'single' });
    return api('organizer/builder/drafts', { method: 'POST', body: body })
      .then(function (r) { useDraft(r); navigate({ draft: r.draft.id, step: 'info' }, true); renderBuilder(); })
      .catch(function (e) { screen(errorCard(e.message, function () { createDraft(body); })); });
  }
  Builder.createDraft = createDraft;

  function useDraft(r) {
    S.draft = r.draft; S.report = r.report; S.fees = r.fees || []; S.pending = {}; S.conflict = false; S.scheduledAt = r.scheduled_at || '';
    S.step = r.draft.state.step || 'info';
  }

  function openDraft(id, step) {
    screen(loading('Opening draft…'));
    return api('organizer/builder/drafts/' + id).then(function (r) {
      useDraft(r);
      if (step && steps().some(function (s) { return s.key === step; })) S.step = step;
      maybeRestore();
      navigate({ draft: id, step: S.step }, true);
      renderBuilder();
    }).catch(function (e) { screen(errorCard(e.message, function () { openDraft(id, step); })); });
  }
  Builder.openDraft = openDraft;

  function maybeRestore() {
    try {
      var raw = localStorage.getItem(backupKey());
      if (!raw) return;
      var b = JSON.parse(raw);
      if (!b || !b.pending || !Object.keys(b.pending).length) { dropBackup(); return; }
      if (b.revision !== S.draft.revision) { S.restoreOffer = b; return; }
      S.draft.state = b.state; S.pending = b.pending; S.restoreApplied = true;
      setTimeout(flush, 300);
    } catch (e) { dropBackup(); }
  }

  function startEdit(eventId) {
    screen(loading('Opening event…'));
    return api('organizer/builder/events/' + eventId + '/edit', { method: 'POST' }).then(function (r) {
      useDraft(r);
      navigate({ draft: r.draft.id, step: 'info' }, true);
      renderBuilder();
    }).catch(function (e) { screen(errorCard(e.message, function () { startEdit(eventId); })); });
  }
  Builder.startEdit = startEdit;

  /* ------------------------------------------------------------------ dialogs */

  function dialog(opts) {
    var prev = document.activeElement;
    var close;
    var box = h('div', { class: 'eb-dialog' + (opts.wide ? ' eb-dialog--wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'eb-dlg-title' }, [
      h('div', { class: 'eb-dialog__head' }, [
        h('h2', { id: 'eb-dlg-title', text: opts.title }),
        h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Close', onclick: function () { close(null); } }, icon('close'))
      ]),
      h('div', { class: 'eb-dialog__body' }, opts.body),
      opts.foot ? h('div', { class: 'eb-dialog__foot' }, opts.foot) : null
    ]);
    var backdrop = h('div', { class: 'eb-dialog-backdrop', onclick: function (e) { if (e.target === backdrop) close(null); } }, box);
    function onKey(e) {
      if (e.key === 'Escape') close(null);
      if (e.key === 'Tab') {
        var f = box.querySelectorAll('button, [href], input, select, textarea');
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    }
    var resolve;
    var p = new Promise(function (r) { resolve = r; });
    close = function (v) { document.removeEventListener('keydown', onKey); backdrop.remove(); if (prev && prev.focus) prev.focus(); resolve(v); };
    document.addEventListener('keydown', onKey);
    document.body.appendChild(backdrop);
    var first = box.querySelector('input, select, textarea, .eb-dialog__foot button');
    (first || box.querySelector('button')).focus();
    p.close = close;
    if (opts.onOpen) opts.onOpen(close, box);
    return p;
  }
  Builder.dialog = dialog;

  function confirmDialog(title, text, okLabel) {
    var d;
    d = dialog({ title: title, body: h('p', { text: text }), foot: [
      h('button', { type: 'button', class: 'eb-btn', text: 'Cancel', onclick: function () { d.close(false); } }),
      h('button', { type: 'button', class: 'eb-btn eb-btn--dark', text: okLabel || 'Confirm', onclick: function () { d.close(true); } })
    ] });
    return d;
  }
  Builder.confirmDialog = confirmDialog;

  function duplicateDialog(eventId) {
    var events = S.recent || [];
    var choose = h('select', { class: 'eb-input', id: 'eb-dup-event' }, [h('option', { value: '', text: 'Choose an event…' })].concat(events.map(function (e) {
      return h('option', { value: e.id, selected: e.id === eventId, text: e.title + (e.date ? ' · ' + fmtDate(e.date) : '') + (e.city ? ' · ' + e.city : '') });
    })));
    if (!events.length && eventId) append(choose, h('option', { value: eventId, selected: true, text: 'Event #' + eventId }));
    var parts = [['description', 'Description'], ['images', 'Images'], ['category', 'Event category'], ['options', 'Vendor options and prices'], ['questions', 'Application questions'], ['documents', 'Document rules'], ['payments', 'Deposit and policies']];
    var boxes = parts.map(function (p) { return h('label', { class: 'eb-check' }, [h('input', { type: 'checkbox', checked: true, value: p[0] }), h('span', { text: p[1] })]); });
    var d = dialog({
      title: 'Duplicate an event',
      body: [
        h('div', { class: 'eb-field' }, [h('label', { for: 'eb-dup-event', text: 'Event to copy' }), choose]),
        h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'What to copy' }), h('div', { class: 'eb-grid' }, boxes)]),
        h('div', { class: 'eb-notice' }, h('div', {}, [h('strong', { text: 'Never copied' }), h('span', { text: 'Applications, bookings, invoices, payments, booth assignments, passes and check-ins. You will confirm the new date, location and links before publishing.' })]))
      ],
      foot: [
        h('button', { type: 'button', class: 'eb-btn', text: 'Cancel', onclick: function () { d.close(null); } }),
        h('button', { type: 'button', class: 'eb-btn eb-btn--dark', text: 'Create copy', onclick: function () {
          var id = parseInt(choose.value, 10);
          if (!id) { choose.focus(); return; }
          var comps = boxes.map(function (b) { var i = b.querySelector('input'); return i.checked ? i.value : null; }).filter(Boolean);
          d.close(true);
          createDraft({ method: 'duplicate', duplicate_event: id, components: comps });
        } })
      ]
    });
    if (!events.length) {
      api('organizer/builder/recommendations').then(function (r) {
        S.recent = r.recent_events || [];
        S.recent.forEach(function (e) { append(choose, h('option', { value: e.id, selected: e.id === eventId, text: e.title + (e.date ? ' · ' + fmtDate(e.date) : '') })); });
      }).catch(function () {});
    }
    return d;
  }
  Builder.duplicateDialog = duplicateDialog;

  function libraryDialog(applyToDraft) {
    var body = h('div', { class: 'eb-stage' }, loading('Loading templates…'));
    var d = dialog({ title: applyToDraft ? 'Apply a template' : 'Start from a template', wide: true, body: body });
    function pick(ref) {
      d.close(true);
      if (applyToDraft) return applyTemplate(ref);
      createDraft({ method: 'template', template_id: ref });
    }
    api('organizer/builder/library').then(function (lib) {
      clear(body);
      if (lib.templates.length) {
        append(body, h('h3', { class: 'eb-eyebrow', text: 'Your templates' }));
        append(body, h('div', { class: 'eb-methods' }, lib.templates.map(function (t) {
          return methodCard({ key: 'tpl', icon: 'template', title: t.name, desc: t.booths.map(function (b) { return b.label + (b.price ? ' ' + money(b.price) : ''); }).join(' · ') || 'No booth types', meta: [h('span', { class: 'eb-chip', text: 'v' + t.version })], onclick: function () { pick(String(t.id)); } });
        })));
      }
      if (lib.setups.length) {
        append(body, h('h3', { class: 'eb-eyebrow', text: 'Saved setups' }));
        append(body, h('div', { class: 'eb-methods' }, lib.setups.map(function (s) {
          return h('div', { class: 'eb-method' }, [h('span', { class: 'eb-method__icon' }, icon('draft')), h('span', {}, [
            h('span', { class: 'eb-method__title', text: s.name }),
            h('span', { class: 'eb-method__desc', text: s.slot_count + ' booth types' }),
            h('span', { class: 'eb-method__meta' }, [
              h('button', { type: 'button', class: 'eb-btn eb-btn--sm eb-btn--dark', text: 'Use', onclick: function () { pick('setup:' + s.id); } }),
              h('button', { type: 'button', class: 'eb-btn eb-btn--sm', text: 'Convert to template', onclick: function () {
                api('organizer/builder/library/setups/' + encodeURIComponent(s.id) + '/convert', { method: 'POST' }).then(function () { toast('Saved as a versioned template.'); d.close(null); libraryDialog(applyToDraft); }).catch(function (e) { toast(e.message, 'error'); });
              } })
            ])
          ])]);
        })));
      }
      append(body, h('h3', { class: 'eb-eyebrow', text: 'Starter structures' }));
      append(body, h('p', { class: 'eb-help', text: 'Starters add vendor categories and common questions. You set prices and space counts.' }));
      append(body, h('div', { class: 'eb-methods' }, lib.starters.map(function (s) {
        return methodCard({ key: 'starter', icon: 'template', title: s.name, desc: s.categories.join(' · '), onclick: function () { pick('starter:' + s.key); } });
      })));
    }).catch(function (e) { clear(body); append(body, h('p', { class: 'eb-error', text: e.message })); });
    return d;
  }
  Builder.libraryDialog = libraryDialog;

  function applyTemplate(ref) {
    flush().then(function () {
      return api('organizer/builder/drafts/' + S.draft.id + '/apply-template', { method: 'POST', body: { template_id: ref, revision: S.draft.revision } });
    }).then(function (r) {
      useDraft(r); S.step = 'options'; renderBuilder(); toast('Template applied. Review prices and spaces.');
    }).catch(function (e) { toast(e.message, 'error'); });
  }

  function showConflict() {
    var d = dialog({
      title: 'This draft changed somewhere else',
      body: h('p', { text: 'A teammate or another window saved newer changes. Reload to see the latest version. Your unsaved edits on this screen are kept on this device so you can re-apply them.' }),
      foot: [h('button', { type: 'button', class: 'eb-btn eb-btn--dark', text: 'Reload latest', onclick: function () { d.close(true); dropBackup(); openDraft(S.draft.id, S.step); } })]
    });
  }

  /* ------------------------------------------------------------------ builder shell */

  function goStep(key) {
    flush();
    S.step = key;
    setPath(S.draft.state, 'step', key);
    S.pending = mergePatch(S.pending, { step: key });
    navigate({ draft: S.draft.id, step: key }, false);
    renderBuilder();
    track('creation_step_viewed', { step: key });
  }
  Builder.goStep = goStep;

  function checkFor(key) {
    var list = (S.report && S.report.checks) || [];
    for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
    return null;
  }
  Builder.checkFor = checkFor;

  function renderBuilder() {
    var st = S.draft.state;
    var list = steps();
    if (!list.some(function (s) { return s.key === S.step; })) S.step = list[0].key;
    var idx = list.findIndex(function (s) { return s.key === S.step; });
    var title = st.event.name || (isEdit() ? 'Edit event' : 'New event');

    var top = h('div', { class: 'eb-top' }, [
      h('a', { class: 'eb-btn eb-btn--ghost eb-btn--sm', href: isEdit() ? cfg.urls.events : cfg.urls.create, onclick: function (e) { e.preventDefault(); flush().then(function () { if (isEdit()) window.location.href = cfg.urls.events; else { navigate({}); chooser(); } }); }, 'aria-label': 'Exit builder' }, [icon('back'), h('span', { class: 'eb-hide-sm', text: 'Exit' })]),
      h('div', { class: 'eb-top__title' }, [h('strong', { text: title, 'data-eb-title': '' }), h('span', { text: isEdit() ? 'Editing a published event — changes apply when you save' : (st.quick ? 'Quick create' : 'Event builder') })]),
      h('div', { class: 'eb-top__actions' }, [
        h('span', { class: 'eb-save', 'data-eb-save': '', 'data-state': S.saveState, role: 'status', 'aria-live': 'polite' }),
        h('button', { type: 'button', class: 'eb-btn eb-btn--sm eb-hide-sm', onclick: openPreviewSheet }, [icon('eye'), 'Preview'])
      ])
    ]);

    S.visited = S.visited || {};
    S.visited[S.step] = true;
    var nav = h('nav', { class: 'eb-steps', 'aria-label': 'Builder steps' }, list.map(function (s, i) {
      var state = stepState(s.key);
      return h('button', { type: 'button', class: 'eb-step', 'data-step': s.key, 'aria-current': s.key === S.step ? 'step' : null, 'data-state': state, onclick: function () { goStep(s.key); } }, [h('span', { class: 'eb-step__num', text: state === 'done' && s.key !== S.step ? '✓' : String(i + 1) }), s.label]);
    }));
    var mobile = h('div', { class: 'eb-mobile-progress' }, [
      h('p', {}, [h('span', { text: list[idx].label }), h('span', { text: 'Step ' + (idx + 1) + ' of ' + list.length })]),
      h('div', { class: 'eb-progress' }, h('span', { style: 'width:' + Math.round(100 * (idx + 1) / list.length) + '%' }))
    ]);

    var main = h('div', { class: 'eb-main', 'data-eb-main': '', 'data-testid': 'eb-step-' + S.step });
    var rail = h('aside', { class: 'eb-rail', 'data-eb-rail': '', 'aria-label': 'Live preview' });
    var prevStep = list[idx - 1], nextStep = list[idx + 1];
    var bar = h('div', { class: 'eb-actionbar' }, [
      h('div', {}, prevStep ? h('button', { type: 'button', class: 'eb-btn', onclick: function () { goStep(prevStep.key); } }, [icon('back'), h('span', { class: 'eb-hide-sm', text: 'Back' })]) : null),
      h('div', { class: 'eb-actionbar__right' }, [
        h('button', { type: 'button', class: 'eb-btn eb-btn--ghost eb-hide-sm', text: 'Save draft', onclick: function () { flush().then(function () { if (S.saveState === 'saved') toast('Draft saved.'); }); } }),
        nextStep
          ? h('button', { type: 'button', class: 'eb-btn eb-btn--primary', 'data-testid': 'eb-continue', text: 'Continue to ' + nextStep.label.toLowerCase(), onclick: function () { goStep(nextStep.key); } })
          : h('button', { type: 'button', class: 'eb-btn eb-btn--primary', 'data-testid': 'eb-publish', 'data-eb-publish': '', text: isEdit() ? 'Review & save changes' : 'Publish', onclick: function () { isEdit() ? reviewEdit() : confirmPublish(); } })
      ])
    ]);

    var shell = h('div', { class: 'eb-shell' }, [top, nav, mobile, S.restoreOffer ? restoreBanner() : null, S.scheduledAt ? h('div', { class: 'eb-notice' }, h('div', {}, [h('strong', { text: 'Scheduled' }), h('span', { text: 'This event will publish on ' + new Date(S.scheduledAt).toLocaleString() + '. Editing keeps it scheduled.' })])) : null, h('div', { class: 'eb-layout' }, [main, rail]), bar]);
    screen(shell);
    setSave(S.saveState);
    var renderer = Builder.steps[S.step];
    append(main, renderer ? renderer() : h('p', { text: 'Unknown step.' }));
    renderRail();
    var heading = main.querySelector('h2');
    if (heading) heading.setAttribute('tabindex', '-1');
  }
  Builder.render = renderBuilder;

  /** A step shows "done" only after the organizer has been there (or in edit mode). */
  function stepState(key) {
    var raw = S.report && S.report.steps ? S.report.steps[key] : '';
    if (raw === 'attention') return (S.visited && S.visited[key]) || isEdit() ? 'attention' : '';
    return raw === 'done' && ((S.visited && S.visited[key]) || isEdit()) ? 'done' : '';
  }

  function restoreBanner() {
    var b = S.restoreOffer;
    return h('div', { class: 'eb-notice eb-notice--warning', role: 'alert' }, h('div', {}, [
      h('strong', { text: 'Unsaved changes from ' + new Date(b.at).toLocaleString() }),
      h('span', { text: 'These were not saved before the draft changed. Apply them on top of the latest version?' }),
      h('div', { class: 'eb-notice__actions' }, [
        h('button', { type: 'button', class: 'eb-btn eb-btn--sm eb-btn--dark', text: 'Apply my changes', onclick: function () { S.pending = b.pending; S.draft.state = mergePatch(S.draft.state, b.pending); S.restoreOffer = null; flush(); renderBuilder(); } }),
        h('button', { type: 'button', class: 'eb-btn eb-btn--sm', text: 'Discard', onclick: function () { S.restoreOffer = null; dropBackup(); renderBuilder(); } })
      ])
    ]));
  }

  function afterReport() {
    renderRail();
    var nav = root.querySelector('.eb-steps');
    if (nav && S.report) {
      nav.querySelectorAll('.eb-step').forEach(function (btn) {
        var key = btn.getAttribute('data-step');
        var state = stepState(key);
        btn.setAttribute('data-state', state);
        var num = btn.querySelector('.eb-step__num');
        if (num && key !== S.step) num.textContent = state === 'done' ? '✓' : String(steps().findIndex(function (s) { return s.key === key; }) + 1);
      });
    }
    var t = root.querySelector('[data-eb-title]');
    if (t) t.textContent = S.draft.state.event.name || (isEdit() ? 'Edit event' : 'New event');
    root.querySelectorAll('[data-eb-live]').forEach(function (node) {
      var fn = Builder.live[node.getAttribute('data-eb-live')];
      if (fn) { clear(node); append(node, fn()); }
    });
    var pub = root.querySelector('[data-eb-publish]');
    if (pub && !isEdit()) pub.setAttribute('aria-disabled', S.report && S.report.publishable ? 'false' : 'true');
  }
  Builder.afterReport = afterReport;
  Builder.live = {};

  /* ------------------------------------------------------------------ rail */

  function previewCard(st) {
    var ev = st.event;
    var inv = (S.report && S.report.summary) || { starting_fee: 0, spaces: 0, free_spaces: 0 };
    var img = S.imageUrl || '';
    var media = h('div', { class: 'eb-pcard__media' }, img
      ? h('img', { src: img, alt: '', 'data-focus': ev.flyer_id ? 'contain' : 'cover', style: 'object-position:center ' + (ev.image_focus || 'center') })
      : h('div', { class: 'eb-pcard__fallback', text: ev.name || 'Your event' }));
    append(media, h('span', { class: 'eb-pcard__badge' }, h('span', { class: 'eb-chip eb-chip--lime', text: st.applications.mode === 'closed' || st.publish.applications === 'closed' ? 'Applications closed' : (st.applications.mode === 'invite' ? 'Invite only' : 'Booking vendors') })));
    var place = [ev.venue_tba ? 'Venue TBA' : ev.venue_name, ev.city ? ev.city + (ev.state ? ', ' + ev.state : '') : ''].filter(Boolean).join(' · ');
    var price = !inv.spaces ? 'Add vendor options' : (inv.spaces === inv.free_spaces ? 'Free for vendors' : 'From ' + money(inv.starting_fee));
    return h('div', { class: 'eb-pcard', 'data-testid': 'eb-preview-card' }, [
      media,
      h('div', { class: 'eb-pcard__body' }, [
        h('p', { class: 'eb-pcard__title', text: ev.name || 'Event name' }),
        h('p', { class: 'eb-pcard__meta', text: ev.date_tba ? 'Date TBA' : (ev.date ? fmtDate(ev.date) + (ev.start_time ? ' · ' + ev.start_time : '') : 'Date') }),
        h('p', { class: 'eb-pcard__meta', text: place || 'Location' }),
        h('p', { class: 'eb-pcard__meta', text: 'By ' + (cfg.organizer || 'you') }),
        h('div', { class: 'eb-pcard__foot' }, [h('strong', { text: price }), h('span', { class: 'eb-help', text: inv.spaces ? inv.spaces + ' spaces' : '' })])
      ])
    ]);
  }
  Builder.previewCard = previewCard;

  function renderRail() {
    var rail = root.querySelector('[data-eb-rail]');
    if (!rail || !S.draft) return;
    clear(rail);
    var rep = S.report || { progress: 0, checks: [] };
    var req = rep.checks.filter(function (c) { return c.level === 'required'; });
    var done = req.filter(function (c) { return c.ok; }).length;
    var missing = req.filter(function (c) { return !c.ok; }).slice(0, 4);
    append(rail, [
      previewCard(S.draft.state),
      h('div', { class: 'eb-railbox' }, [
        h('h3', { text: isEdit() ? 'Ready to save' : 'Ready to publish' }),
        h('div', { class: 'eb-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(rep.progress), 'aria-label': 'Required items complete' }, h('span', { style: 'width:' + rep.progress + '%' })),
        h('p', { class: 'eb-help', style: 'margin-top:6px', text: done + ' of ' + req.length + ' required items done' }),
        missing.length ? h('ul', { style: 'margin:10px 0 0;padding:0;display:grid;gap:6px' }, missing.map(function (c) {
          return h('li', { style: 'list-style:none;display:flex;justify-content:space-between;gap:8px;font-size:13px' }, [h('span', { text: c.label }), h('button', { type: 'button', class: 'eb-link', text: 'Fix', onclick: function () { goStep(c.step); } })]);
        })) : h('p', { class: 'eb-help', style: 'margin-top:8px;color:var(--eb-success)', text: 'Everything required is in place.' })
      ]),
      h('div', { class: 'eb-railbox' }, [
        h('h3', { text: 'Vendor inventory' }),
        h('dl', { class: 'eb-kv' }, [
          kv('Vendor options', String((rep.summary && rep.summary.types) || 0)),
          kv('Total spaces', String((rep.summary && rep.summary.spaces) || 0)),
          kv('Free spaces', String((rep.summary && rep.summary.free_spaces) || 0)),
          kv('Max. base fees', money((rep.summary && rep.summary.max_base_fees) || 0))
        ]),
        h('p', { class: 'eb-help', style: 'margin-top:8px', text: 'Maximum if every space sells at its base fee — not a forecast.' })
      ])
    ]);
  }
  Builder.renderRail = renderRail;
  function kv(k, v) { return h('div', {}, [h('dt', { text: k }), h('dd', { text: v })]); }
  Builder.kv = kv;

  function openPreviewSheet() {
    var body = h('div', { class: 'eb-stage' }, loading('Building preview…'));
    dialog({ title: 'Vendor view preview', wide: true, body: body });
    flush().then(function () { return renderPreviewStage(body); });
  }

  /* ------------------------------------------------------------------ field helpers */

  function field(label, input, opts) {
    opts = opts || {};
    var id = input.id || uid('f');
    input.id = id;
    var check = opts.check ? checkFor(opts.check) : null;
    var invalid = check && !check.ok && check.level === 'required' && opts.showError;
    return h('div', { class: 'eb-field' + (opts.span ? ' ' + opts.span : '') + (invalid ? ' eb-field--invalid' : '') }, [
      h('label', { for: id }, [label, opts.required ? h('span', { class: 'eb-req', 'aria-hidden': 'true', text: '*' }) : null, opts.optional ? h('span', { class: 'eb-opt', text: 'Optional' }) : null]),
      input,
      opts.help ? h('p', { class: 'eb-help', id: id + '-help', text: opts.help }) : null,
      invalid ? h('p', { class: 'eb-error', role: 'alert', text: check.message }) : null
    ]);
  }
  Builder.field = field;

  function textInput(path, opts) {
    opts = opts || {};
    var el = h(opts.multiline ? 'textarea' : 'input', {
      type: opts.multiline ? null : (opts.type || 'text'), value: getPath(S.draft.state, path) || '', placeholder: opts.placeholder || null,
      maxlength: opts.max || null, min: opts.min, step: opts.step || null, inputmode: opts.inputmode || null, required: opts.required || null,
      'aria-required': opts.required ? 'true' : null, rows: opts.rows || null, autocomplete: 'off'
    });
    if (opts.multiline) el.value = getPath(S.draft.state, path) || '';
    el.addEventListener('input', function () {
      var v = el.value;
      if (opts.type === 'number') v = v === '' ? 0 : Number(v);
      set(path, v);
      if (opts.oninput) opts.oninput(v);
    });
    return el;
  }
  Builder.textInput = textInput;

  function checkbox(path, label, help) {
    var input = h('input', { type: 'checkbox', checked: !!getPath(S.draft.state, path), onchange: function () { set(path, input.checked); } });
    return h('label', { class: 'eb-check' }, [input, h('span', {}, [label, help ? h('small', { text: help }) : null])]);
  }
  Builder.checkbox = checkbox;

  function segmented(path, options, onchange) {
    var current = getPath(S.draft.state, path);
    var wrap = h('div', { class: 'eb-seg', role: 'group' });
    options.forEach(function (o) {
      append(wrap, h('button', { type: 'button', 'aria-pressed': o[0] === current ? 'true' : 'false', text: o[1], onclick: function () {
        set(path, o[0]);
        wrap.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
        this.setAttribute('aria-pressed', 'true');
        if (onchange) onchange(o[0]);
      } }));
    });
    return wrap;
  }
  Builder.segmented = segmented;

  function card(title, subtitle, children, opts) {
    return h('section', { class: 'eb-card', 'data-testid': opts && opts.testid ? opts.testid : null }, [
      h('div', { class: 'eb-card__head' }, [h('div', {}, [h('h2', { text: title }), subtitle ? h('p', { text: subtitle }) : null]), opts && opts.action ? opts.action : null]),
      children
    ]);
  }
  Builder.card = card;

  function liveBox(key) { var node = h('div', { 'data-eb-live': key }); append(node, Builder.live[key]()); return node; }
  Builder.liveBox = liveBox;

  /* ------------------------------------------------------------------ step: info */

  Builder.steps = {};

  Builder.steps.info = function () {
    var st = S.draft.state, ev = st.event, quick = st.quick;
    var nodes = [];

    var typeSelect = h('select', { onchange: function () { set('event.event_type', typeSelect.value); } }, [h('option', { value: '', text: 'Choose a category' })]);
    var types = (S.config && S.config.event_types) || [];
    types.filter(function (t) { return !t.parent; }).forEach(function (p) {
      append(typeSelect, h('option', { value: p.slug, selected: ev.event_type === p.slug, text: p.name }));
      types.filter(function (t) { return t.parent === p.id; }).forEach(function (k) {
        append(typeSelect, h('option', { value: k.slug, selected: ev.event_type === k.slug, text: '— ' + k.name }));
      });
    });

    var nameInput = textInput('event.name', { max: 140, placeholder: 'e.g. Dirty South Rodeo Festival', required: true });
    var cleanCheck = checkFor('name_clean');
    var basics = [
      field('Event name', nameInput, { required: true, span: 'eb-span-all', check: 'name', help: 'Use the event\'s own name. The city, venue and date are shown separately on the listing.' }),
      cleanCheck && !cleanCheck.ok ? h('div', { class: 'eb-notice eb-notice--warning eb-span-all' }, h('div', {}, [h('span', { text: cleanCheck.message })])) : null,
      field('Event category', typeSelect, { optional: true, help: 'The kind of event — not the vendor categories.' })
    ];
    if (!quick && S.config && S.config.brands && S.config.brands.length) {
      var brand = h('select', { onchange: function () { set('event.brand_id', parseInt(brand.value, 10) || 0); } }, [h('option', { value: '0', text: 'No brand' })].concat(S.config.brands.map(function (b) { return h('option', { value: b.id, selected: ev.brand_id === b.id, text: b.name }); })));
      basics.push(field('Event brand', brand, { optional: true }));
    }
    if (!quick) {
      basics.push(field('Short summary', textInput('event.summary', { max: 200, placeholder: 'One line vendors see in search' }), { optional: true, span: 'eb-span-all' }));
      var desc = textInput('event.description', { multiline: true, rows: 6, max: 6000, placeholder: 'What is the event like? Who comes? What makes it a good fit for vendors?' });
      basics.push(field('Description', desc, { span: 'eb-span-all', help: 'Describe what actually happens. Avoid unconfirmed claims about attendance, performers or sponsors.' }));
    }
    nodes.push(card('Event basics', quick ? 'Just the essentials — you can add more later.' : null, h('div', { class: 'eb-grid' }, basics)));

    if (!quick) nodes.push(mediaCard());

    // Date and time.
    var multi = !!ev.end_date && ev.end_date !== ev.date;
    var dateGrid = h('div', { class: 'eb-grid' }, [
      field(multi ? 'Start date' : 'Date', textInput('event.date', { type: 'date' }), { required: true, check: 'date' }),
      multi ? field('End date', textInput('event.end_date', { type: 'date' })) : null,
      field('Start time', textInput('event.start_time', { type: 'time' }), { optional: true }),
      field('End time', textInput('event.end_time', { type: 'time' }), { optional: true, help: 'An end time earlier than the start means the event runs past midnight.' })
    ]);
    var tzSelect = h('select', { onchange: function () { set('event.timezone', tzSelect.value); } });
    ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Puerto_Rico'].concat(ev.timezone && ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Puerto_Rico'].indexOf(ev.timezone) < 0 ? [ev.timezone] : []).forEach(function (z) {
      append(tzSelect, h('option', { value: z, selected: ev.timezone === z, text: z.replace('America/', '').replace('Pacific/', '').replace('_', ' ') + ' time' }));
    });
    nodes.push(card('Date and time', null, [
      dateGrid,
      h('div', { class: 'eb-grid', style: 'margin-top:14px' }, [
        field('Time zone', tzSelect, { help: 'Times are shown in the event\'s time zone, not the viewer\'s.' }),
        h('div', { class: 'eb-field', style: 'align-content:end' }, [
          h('label', { class: 'eb-check' }, [h('input', { type: 'checkbox', checked: multi, onchange: function (e) { set('event.end_date', e.target.checked ? (ev.date || '') : ''); renderBuilder(); } }), h('span', { text: 'Runs more than one day' })]),
          checkbox('event.date_tba', 'Date not set yet', 'You can save the draft, but you will need a date to publish.')
        ])
      ])
    ]));

    nodes.push(locationCard(quick));

    if (!quick) {
      var att = ev.attendance;
      var attInputs = h('div', { class: 'eb-grid', style: 'margin-top:10px' });
      function drawAtt() {
        clear(attInputs);
        if (att.mode === 'estimate') append(attInputs, field('Expected attendance', textInput('event.attendance.value', { type: 'number', min: 0, inputmode: 'numeric' }), { help: 'Shown to vendors as your estimate.' }));
        if (att.mode === 'range') append(attInputs, [field('From', textInput('event.attendance.min', { type: 'number', min: 0 })), field('To', textInput('event.attendance.max', { type: 'number', min: 0 }))]);
      }
      drawAtt();
      nodes.push(card('Event details', 'Helps vendors decide if your event fits.', [
        h('div', { class: 'eb-grid' }, [
          h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Setting' }), segmented('event.format', [['outdoor', 'Outdoor'], ['indoor', 'Indoor'], ['both', 'Indoor & outdoor'], ['tba', 'Not decided']])]),
          h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Expected attendance' }), segmented('event.attendance.mode', [['unknown', 'Not sure yet'], ['estimate', 'Estimate'], ['range', 'Range']], function (m) { att.mode = m; drawAtt(); })])
        ]),
        attInputs,
        h('div', { style: 'margin-top:10px' }, checkbox('event.power_available', 'Electricity available for vendors'))
      ]));
      nodes.push(sourcesCard());
    }
    if (quick) {
      nodes.push(h('div', { class: 'eb-card eb-card--flat' }, [
        h('p', { class: 'eb-help', text: 'Want images, a description, attendance or your ticketing link now?' }),
        h('button', { type: 'button', class: 'eb-link', text: 'Switch to the full builder', onclick: function () { set('quick', false, { now: true }); S.draft.state.quick = false; renderBuilder(); } })
      ]));
    }
    return nodes;
  };

  function mediaCard() {
    var ev = S.draft.state.event;
    var frame = h('div', { class: 'eb-media__frame', 'data-testid': 'eb-media-frame' });
    var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', class: 'eb-sr', id: uid('upl') });
    var status = h('p', { class: 'eb-help', role: 'status' });
    function draw() {
      clear(frame);
      if (S.imageUrl) append(frame, h('img', { src: S.imageUrl, alt: 'Event image preview' }));
      else append(frame, h('span', { text: 'Flyers (portrait) and photos (landscape) both work. We never crop your flyer.' }));
    }
    var imgId = ev.flyer_id || ev.featured_image_id;
    if (imgId && !S.imageUrl) {
      fetch(cfg.rest.replace(/lnkup\/v1\/$/, '') + 'wp/v2/media/' + imgId, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (m) { if (m && m.source_url) { S.imageUrl = m.source_url; draw(); renderRail(); } }).catch(function () {});
    }
    draw();
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      if (f.size > 8 * 1024 * 1024) { status.textContent = 'Images can be up to 8 MB.'; return; }
      var fd = new FormData(); fd.append('file', f);
      status.textContent = 'Uploading…';
      api('organizer/builder/media', { method: 'POST', body: fd }).then(function (r) {
        S.imageUrl = r.url;
        var portrait = r.height > r.width;
        set(portrait ? 'event.flyer_id' : 'event.featured_image_id', r.id, { now: true });
        if (portrait && S.draft.state.event.featured_image_id) set('event.featured_image_id', 0);
        if (!portrait && S.draft.state.event.flyer_id) set('event.flyer_id', 0);
        status.textContent = portrait ? 'Flyer added — shown in full, never cropped.' : 'Cover photo added.';
        draw();
        renderRail();
      }).catch(function (e) { status.textContent = e.message; });
    });
    return card('Event image', 'Shown on your listing, in search and when your link is shared.', h('div', { class: 'eb-media' }, [
      frame,
      h('div', { style: 'display:grid;gap:12px' }, [
        h('div', { class: 'eb-media__actions' }, [
          h('label', { for: file.id, class: 'eb-btn eb-btn--dark', role: 'button', tabindex: '0', onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } } }, imgId ? 'Replace image' : 'Upload flyer or photo'),
          file,
          imgId ? h('button', { type: 'button', class: 'eb-btn', text: 'Remove', onclick: function () { S.imageUrl = ''; set('event.flyer_id', 0); set('event.featured_image_id', 0, { now: true }); draw(); renderRail(); } }) : null
        ]),
        status,
        ev.featured_image_id ? h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Focus for wide crops' }), segmented('event.image_focus', [['top', 'Top'], ['center', 'Center'], ['bottom', 'Bottom']], function () { renderRail(); })]) : null,
        h('p', { class: 'eb-help', text: 'No image yet? Your listing uses a branded LNKup image until you add one. Only upload images you have the right to use.' })
      ])
    ]));
  }

  function locationCard(quick) {
    var ev = S.draft.state.event;
    var cityWrap = h('div', { class: 'eb-ac' });
    var cityInput = h('input', { type: 'text', value: ev.city ? ev.city + (ev.state ? ', ' + ev.state : '') : '', placeholder: 'Start typing a city', autocomplete: 'off', role: 'combobox', 'aria-expanded': 'false', 'aria-autocomplete': 'list', 'aria-required': 'true' });
    var list = h('div', { class: 'eb-ac__list', role: 'listbox', hidden: true });
    append(cityWrap, [cityInput, list]);
    var active = -1, items = [];
    function close() { list.hidden = true; cityInput.setAttribute('aria-expanded', 'false'); active = -1; }
    function choose(p) {
      cityInput.value = p.display;
      set('event.city', p.city); set('event.state', p.state_code); set('event.location_confirmed', true, { now: true });
      close();
    }
    var lookup = debounce(function () {
      var q = cityInput.value.trim();
      if (q.length < 2) { close(); return; }
      api('locations/suggest', { query: { q: q, limit: 6 } }).then(function (r) {
        items = r.results || [];
        clear(list);
        items.forEach(function (p, i) {
          append(list, h('button', { type: 'button', class: 'eb-ac__item', role: 'option', 'aria-selected': 'false', onmousedown: function (e) { e.preventDefault(); choose(p); } }, [p.city, h('small', { text: p.state_name })]));
        });
        list.hidden = !items.length;
        cityInput.setAttribute('aria-expanded', items.length ? 'true' : 'false');
      }).catch(function () { close(); });
    }, 220);
    cityInput.addEventListener('input', function () {
      var raw = cityInput.value.split(',');
      set('event.city', (raw[0] || '').trim());
      set('event.state', (raw[1] || '').trim());
      set('event.location_confirmed', false);
      lookup();
    });
    cityInput.addEventListener('keydown', function (e) {
      var opts = list.querySelectorAll('.eb-ac__item');
      if (list.hidden || !opts.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
        opts.forEach(function (o, i) { o.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
      } else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]); }
      else if (e.key === 'Escape') close();
    });
    cityInput.addEventListener('blur', function () { setTimeout(close, 150); });

    var grid = h('div', { class: 'eb-grid' }, [
      field('City and state', cityWrap, { required: true, check: 'location', help: 'Where the event physically happens.' }),
      field('Venue name', textInput('event.venue_name', { max: 160, placeholder: 'e.g. BH Ranch' }), { optional: quick })
    ]);
    if (!quick) {
      append(grid, [
        field('Street address', textInput('event.venue_address', { max: 240 }), { optional: true }),
        field('Postal code', textInput('event.postal_code', { max: 12, inputmode: 'numeric' }), { optional: true })
      ]);
    }
    var extras = h('div', { style: 'display:grid;gap:8px;margin-top:12px' }, [
      checkbox('event.venue_tba', 'Venue not announced yet'),
      quick ? null : checkbox('event.venue_hidden', 'Share the venue only with booked vendors')
    ]);
    var market = quick ? null : h('details', { class: 'eb-adv', open: ev.market_city ? true : null }, [
      h('summary', { text: 'Promote under a different market' }),
      h('p', { class: 'eb-help', text: 'Example: the venue is in Stockbridge, GA but you promote it as an Atlanta event. The listing still shows the real venue.' }),
      field('Marketing market', textInput('event.market_city', { max: 80, placeholder: 'e.g. Atlanta' }), { optional: true })
    ]);
    var live = liveBox('location');
    var dupConfirm = S.draft.state.duplicate_of ? h('div', { style: 'margin-top:12px' }, h('label', { class: 'eb-check' }, [
      h('input', { type: 'checkbox', checked: S.draft.state.confirmed.indexOf('location') >= 0, onchange: function (e) {
        var c = S.draft.state.confirmed.filter(function (x) { return x !== 'location'; });
        if (e.target.checked) c.push('location');
        set('confirmed', c, { now: true });
      } }),
      h('span', {}, ['This copy happens at the location above', h('small', { text: 'Copies never keep the previous event\'s city silently.' })])
    ])) : null;
    return card('Location', null, [grid, extras, market, dupConfirm, live]);
  }

  Builder.live.location = function () {
    var rep = S.report || {};
    var loc = rep.location || {};
    var out = [];
    if (loc.state === 'confirm') {
      out.push(h('div', { class: 'eb-notice eb-notice--warning', style: 'margin-top:12px' }, h('div', {}, [
        h('span', { text: loc.message }),
        loc.suggestions && loc.suggestions.length ? h('div', { class: 'eb-notice__actions' }, loc.suggestions.map(function (s) {
          return h('button', { type: 'button', class: 'eb-chip', text: s.display || (s.city + ', ' + s.state_code), onclick: function () {
            set('event.city', s.city); set('event.state', s.state_code); set('event.location_confirmed', true, { now: true });
            var input = root.querySelector('.eb-ac input'); if (input) input.value = s.city + ', ' + s.state_code;
          } });
        })) : null
      ])));
    }
    var geo = checkFor('geo_conflict');
    if (geo && !geo.ok) {
      out.push(h('div', { class: 'eb-notice eb-notice--warning', style: 'margin-top:12px' }, h('div', {}, [
        h('span', { text: geo.message }),
        h('div', { class: 'eb-notice__actions' }, [h('button', { type: 'button', class: 'eb-btn eb-btn--sm', text: 'The location is correct', onclick: function () { set('event.location_confirmed', true, { now: true }); } })])
      ])));
    }
    if (loc.state === 'ok' && loc.display) out.push(h('p', { class: 'eb-help', style: 'margin-top:10px;color:var(--eb-success)', text: '✓ ' + loc.display + (loc.market ? ' · shown in the ' + loc.market.replace(/-/g, ' ') + ' market' : '') }));
    return out;
  };

  function sourcesCard() {
    var list = h('div', { class: 'eb-links', 'data-testid': 'eb-sources' });
    var input = h('input', { type: 'url', class: 'eb-input', placeholder: 'https://posh.vip/e/your-event', 'aria-label': 'External event link' });
    function draw() {
      clear(list);
      var sources = S.draft.state.sources;
      if (!sources.length) append(list, h('p', { class: 'eb-help', text: 'No links yet.' }));
      sources.forEach(function (s, i) {
        var p = providerFor(s.url) || { label: 'Link', host: s.url };
        append(list, h('div', { class: 'eb-linkrow' }, [
          h('span', { class: 'eb-provider', text: p.label.slice(0, 2).toUpperCase() }),
          h('div', { style: 'min-width:0' }, [h('p', { class: 'eb-linkrow__host' }, [p.label, s.primary ? h('span', { class: 'eb-chip eb-chip--lime', style: 'margin-left:6px', text: 'Primary' }) : null]), h('p', { class: 'eb-linkrow__url', text: s.url })]),
          h('div', { style: 'display:flex;gap:4px' }, [
            s.primary ? null : h('button', { type: 'button', class: 'eb-btn eb-btn--sm eb-btn--ghost', text: 'Make primary', onclick: function () { var next = clone(sources).map(function (x, j) { x.primary = j === i; return x; }); set('sources', next, { now: true }); S.draft.state.sources = next; draw(); } }),
            h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Remove link', onclick: function () { var next = clone(sources); next.splice(i, 1); if (next.length && !next.some(function (x) { return x.primary; })) next[0].primary = true; set('sources', next, { now: true }); S.draft.state.sources = next; draw(); } }, icon('trash'))
          ])
        ]));
      });
    }
    draw();
    var add = function () {
      var v = input.value.trim();
      var p = providerFor(v);
      if (!p || !/^https?:\/\//i.test(v)) { toast('Paste a full link that starts with https://', 'error'); return; }
      if (S.draft.state.sources.some(function (s) { return s.url === v; })) { input.value = ''; return; }
      var next = clone(S.draft.state.sources).concat([{ url: v, provider: p.key, primary: !S.draft.state.sources.length, mode: 'link' }]);
      set('sources', next, { now: true }); S.draft.state.sources = next;
      input.value = '';
      draw();
      track('external_link_added', { provider: p.key });
    };
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    var flags = (S.config && S.config.flags) || {};
    return card('Official event listing', 'Where attendees buy tickets or learn more. Your external event link is separate from your LNKup vendor application.', [
      list,
      h('div', { class: 'eb-import-input', style: 'margin-top:10px' }, [input, h('button', { type: 'button', class: 'eb-btn eb-btn--dark', text: 'Add link', onclick: add })]),
      flags.event_import_v3 && Builder.importIntoDraft ? h('p', { class: 'eb-help', style: 'margin-top:8px' }, ['Want to fill in details from a link? ', h('button', { type: 'button', class: 'eb-link', text: 'Import from a link', onclick: function () { Builder.importIntoDraft(); } })]) : null
    ], { testid: 'eb-sources-card' });
  }

  /* ------------------------------------------------------------------ step: options */

  function feeFor(key) {
    for (var i = 0; i < S.fees.length; i++) if (S.fees[i].key === key) return S.fees[i];
    return null;
  }

  function newOption(category) {
    return { key: uid('o'), name: category || '', category: category || '', fee: 0, spots: 0, width_ft: 0, depth_ft: 0, amenities: [], description: '', deadline: '', opportunity_id: 0 };
  }

  function setOptions(next, now) {
    S.draft.state.options = next;
    set('options', next, { now: now });
  }

  Builder.steps.options = function () {
    var st = S.draft.state;
    var cats = (S.config && S.config.categories) || [];
    var quick = h('div', { class: 'eb-quickadd', 'aria-label': 'Add a vendor category' }, cats.map(function (c) {
      return h('button', { type: 'button', class: 'eb-chip', onclick: function () {
        var o = newOption(c); S.openOption = o.key;
        setOptions(st.options.concat([o]), true); renderBuilder();
        track('vendor_option_created', { category: c });
      } }, [CAT_EMOJI[c] || '＋', ' ', c]);
    }));
    var viewToggle = h('div', { class: 'eb-seg' }, [['cards', 'Cards'], ['table', 'Table']].map(function (v) {
      return h('button', { type: 'button', 'aria-pressed': S.optionsView === v[0] ? 'true' : 'false', text: v[1], onclick: function () { S.optionsView = v[0]; renderBuilder(); } });
    }));
    var body = S.optionsView === 'table' ? optionsTable() : optionsCards();
    return [
      card('Vendor opportunities', 'The booth or space types vendors can apply for. Physical booth placement comes later in Event Operations.', [
        quick,
        body,
        h('div', { class: 'eb-notice__actions', style: 'margin-top:12px' }, [
          h('button', { type: 'button', class: 'eb-btn', text: '+ Add a vendor option', onclick: function () { var o = newOption(''); S.openOption = o.key; setOptions(st.options.concat([o]), true); renderBuilder(); } }),
          h('button', { type: 'button', class: 'eb-btn eb-btn--ghost', text: 'Use a template', onclick: function () { libraryDialog(true); } })
        ])
      ], { testid: 'eb-options-card', action: st.options.length > 2 ? viewToggle : null }),
      card('Inventory summary', null, liveBox('inventory'))
    ];
  };

  Builder.live.inventory = function () {
    var s = (S.report && S.report.summary) || { types: 0, spaces: 0, free_spaces: 0, paid_spaces: 0, max_base_fees: 0 };
    return [
      h('div', { class: 'eb-summary' }, [
        h('div', {}, [h('strong', { text: String(s.types) }), h('span', { text: 'vendor options' })]),
        h('div', {}, [h('strong', { text: String(s.spaces) }), h('span', { text: 'total spaces' })]),
        h('div', {}, [h('strong', { text: s.free_spaces + ' / ' + s.paid_spaces }), h('span', { text: 'free / paid spaces' })]),
        h('div', {}, [h('strong', { text: money(s.max_base_fees) }), h('span', { text: 'maximum base fees' })])
      ]),
      h('p', { class: 'eb-help', style: 'margin-top:8px', text: 'The maximum assumes every space sells at its base fee. It is not a forecast of demand.' })
    ];
  };

  function optionsCards() {
    var st = S.draft.state;
    var list = h('div', { class: 'eb-options', 'data-testid': 'eb-options' });
    if (!st.options.length) {
      append(list, h('div', { class: 'eb-card eb-card--flat', style: 'text-align:center' }, [h('p', { style: 'font-weight:700', text: 'No vendor options yet' }), h('p', { class: 'eb-help', text: 'Pick a category above, or add a custom option. Most events start with 1–3.' })]));
      return list;
    }
    st.options.forEach(function (o, i) { append(list, optionCard(o, i)); });
    return list;
  }

  function optionCard(o, i) {
    var st = S.draft.state;
    var open = S.openOption === o.key;
    var check = checkFor('option_' + o.key);
    var complete = check ? check.ok : (o.category && o.spots > 0);
    var fee = feeFor(o.key);
    var meta = [o.fee > 0 ? money(o.fee) : 'Free', o.spots ? o.spots + ' spaces' : 'Spaces not set', o.width_ft && o.depth_ft ? o.width_ft + '×' + o.depth_ft + ' ft' : null].filter(Boolean);
    var move = function (d) { var n = clone(st.options); var t = n[i + d]; n[i + d] = n[i]; n[i] = t; setOptions(n, true); renderBuilder(); };
    var head = h('div', { class: 'eb-option__head' }, [
      h('span', { class: 'eb-option__icon', 'aria-hidden': 'true', text: CAT_EMOJI[o.category] || '🏷️' }),
      h('button', { type: 'button', style: 'text-align:left;background:none;border:0;padding:0;min-width:0', 'aria-expanded': open ? 'true' : 'false', onclick: function () { S.openOption = open ? null : o.key; renderBuilder(); } }, [
        h('p', { class: 'eb-option__name' }, [o.name || o.category || 'New vendor option', complete ? null : h('span', { class: 'eb-chip eb-chip--warning', style: 'margin-left:8px', text: 'Needs details' })]),
        h('p', { class: 'eb-option__meta' }, meta.map(function (m) { return h('span', { text: m }); }))
      ]),
      h('div', { class: 'eb-option__actions' }, [
        i > 0 ? h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Move up', onclick: function () { move(-1); } }, icon('up')) : null,
        i < st.options.length - 1 ? h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Move down', onclick: function () { move(1); } }, icon('down')) : null,
        h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Duplicate option', onclick: function () { var c = clone(o); c.key = uid('o'); c.opportunity_id = 0; c.name = (o.name || o.category) + ' (copy)'; var n = clone(st.options); n.splice(i + 1, 0, c); S.openOption = c.key; setOptions(n, true); renderBuilder(); } }, icon('copy')),
        h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Remove option', onclick: function () {
          var go = function () { var n = clone(st.options); n.splice(i, 1); setOptions(n, true); renderBuilder(); };
          if (o.opportunity_id) confirmDialog('Remove this vendor option?', 'Options with applications are closed instead of deleted, so no booking is lost.', 'Remove').then(function (ok) { if (ok) go(); });
          else go();
        } }, icon('trash'))
      ])
    ]);
    var node = h('div', { class: 'eb-option', 'data-open': open ? 'true' : 'false', 'data-testid': 'eb-option' }, head);
    if (!open) return node;

    var cats = (S.config && S.config.categories) || [];
    var custom = o.category && cats.indexOf(o.category) < 0;
    var catSelect = h('select', { onchange: function () {
      var v = catSelect.value;
      if (v === '__custom') { customInput.hidden = false; customInput.focus(); return; }
      customInput.hidden = true;
      var n = clone(st.options); n[i].category = v; if (!n[i].name || cats.indexOf(n[i].name) >= 0) n[i].name = v; setOptions(n);
    } }, [h('option', { value: '', text: 'Choose a category' })].concat(cats.map(function (c) { return h('option', { value: c, selected: o.category === c, text: c }); })).concat([h('option', { value: '__custom', selected: custom, text: 'Custom category…' })]));
    var customInput = h('input', { type: 'text', value: custom ? o.category : '', placeholder: 'e.g. Live painting', hidden: !custom, maxlength: 60, oninput: function () { var n = clone(st.options); n[i].category = customInput.value; setOptions(n); } });
    function optInput(key, type, attrs) {
      var el = h('input', Object.assign({ type: type || 'text', value: o[key] === 0 && type === 'number' ? '' : (o[key] || '') }, attrs || {}));
      el.addEventListener('input', function () { var n = clone(st.options); n[i][key] = type === 'number' ? (el.value === '' ? 0 : Number(el.value)) : el.value; setOptions(n); });
      return el;
    }
    var free = o.fee <= 0;
    var priceInput = optInput('fee', 'number', { min: 0, step: '0.01', inputmode: 'decimal', disabled: free && o.freeConfirmed });
    var amen = (S.config && S.config.amenities) || [];
    var feeLine = h('p', { class: 'eb-help', 'data-eb-live': 'fee_' + o.key });
    Builder.live['fee_' + o.key] = function () {
      var f = feeFor(o.key);
      if (!f) return 'Fees update as you type.';
      if (f.free) return 'Free for vendors — no LNKup or processing fees.';
      return 'Vendor pays ' + money(f.vendor_total) + ' at checkout · You receive about ' + money(f.organizer_net) + ' after fees.';
    };
    append(feeLine, Builder.live['fee_' + o.key]());
    append(node, h('div', { class: 'eb-option__body' }, [
      h('div', { class: 'eb-grid', style: 'margin-top:12px' }, [
        field('Option name', optInput('name', 'text', { maxlength: 100, placeholder: 'e.g. Food Truck — Premium Space' }), { help: 'Two options can share a category, like Standard and Premium food truck spaces.' }),
        h('div', { class: 'eb-field' }, [h('label', { for: catSelect.id = uid('c') }, ['Vendor category', h('span', { class: 'eb-req', text: '*' })]), catSelect, customInput]),
        h('div', { class: 'eb-field' }, [
          h('label', { for: priceInput.id = uid('p') }, 'Booth fee'),
          h('div', { class: 'eb-prefix' }, [h('span', { text: '$' }), priceInput]),
          feeLine
        ]),
        field('Spaces available', optInput('spots', 'number', { min: 1, inputmode: 'numeric' }), { required: true, help: 'Vendor capacity is the number of businesses that can reserve this option.' }),
        field('Application deadline', optInput('deadline', 'date'), { optional: true, help: 'Leave empty to use the event-wide deadline.' })
      ]),
      h('details', { class: 'eb-adv' }, [
        h('summary', { text: 'Space size, what\'s included and description' }),
        h('div', { class: 'eb-grid', style: 'margin-top:10px' }, [
          field('Width (ft)', optInput('width_ft', 'number', { min: 0, inputmode: 'numeric' }), { optional: true }),
          field('Depth (ft)', optInput('depth_ft', 'number', { min: 0, inputmode: 'numeric' }), { optional: true }),
          h('div', { class: 'eb-field eb-span-all' }, [h('span', { class: 'eb-field__label', text: 'Included with this space' }), h('div', { class: 'eb-amenities' }, amen.map(function (a) {
            var cb = h('input', { type: 'checkbox', checked: o.amenities.indexOf(a.key) >= 0, onchange: function () { var n = clone(st.options); var list = n[i].amenities.filter(function (x) { return x !== a.key; }); if (cb.checked) list.push(a.key); n[i].amenities = list; setOptions(n); } });
            return h('label', {}, [cb, a.label]);
          })), h('p', { class: 'eb-help', text: 'Included items only. Paid add-ons are not part of this booth fee.' })]),
          field('Description', (function () { var t = h('textarea', { rows: 3, maxlength: 1000 }); t.value = o.description || ''; t.addEventListener('input', function () { var n = clone(st.options); n[i].description = t.value; setOptions(n); }); return t; })(), { optional: true, span: 'eb-span-all' })
        ])
      ]),
      h('div', {}, h('button', { type: 'button', class: 'eb-btn eb-btn--dark eb-btn--sm', text: 'Done', onclick: function () { S.openOption = null; flush(); renderBuilder(); } }))
    ]));
    return node;
  }

  function optionsTable() {
    var st = S.draft.state;
    var cats = (S.config && S.config.categories) || [];
    var tbody = h('tbody');
    st.options.forEach(function (o, i) {
      function cell(key, type, attrs) {
        var el = h('input', Object.assign({ type: type, value: o[key] === 0 && type === 'number' ? '' : o[key], 'aria-label': key + ' for ' + (o.name || 'option ' + (i + 1)) }, attrs || {}));
        el.addEventListener('input', function () { var n = clone(st.options); n[i][key] = type === 'number' ? Number(el.value || 0) : el.value; setOptions(n); });
        return el;
      }
      var sel = h('select', { 'aria-label': 'Category', onchange: function () { var n = clone(st.options); n[i].category = sel.value; setOptions(n); } }, cats.concat(o.category && cats.indexOf(o.category) < 0 ? [o.category] : []).map(function (c) { return h('option', { value: c, selected: o.category === c, text: c }); }));
      append(tbody, h('tr', {}, [
        h('td', {}, cell('name', 'text')), h('td', {}, sel), h('td', {}, cell('fee', 'number', { min: 0, step: '0.01' })), h('td', {}, cell('spots', 'number', { min: 1 })), h('td', {}, cell('deadline', 'date')),
        h('td', {}, h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Remove', onclick: function () { var n = clone(st.options); n.splice(i, 1); setOptions(n, true); renderBuilder(); } }, icon('trash')))
      ]));
    });
    return h('div', { class: 'eb-table-wrap' }, h('table', { class: 'eb-table' }, [
      h('thead', {}, h('tr', {}, ['Option', 'Category', 'Fee ($)', 'Spaces', 'Deadline', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
      tbody
    ]));
  }

  /* ------------------------------------------------------------------ step: applications */

  var QUESTION_TYPES = [['short', 'Short answer'], ['long', 'Paragraph'], ['choice', 'Multiple choice'], ['yesno', 'Yes / no'], ['number', 'Number'], ['file', 'File upload'], ['ack', 'Acknowledgment']];
  var QUESTION_SETS = {
    'Food truck': [['Truck or trailer length (ft)', 'number', true], ['Do you need electricity?', 'yesno', true], ['Menu highlights', 'long', false]],
    Retail: [['What will you sell?', 'long', true], ['Price range of your products', 'short', false]],
    Festival: [['Have you vended at a festival this size before?', 'yesno', false], ['Anything we should know about your setup?', 'long', false]],
    Artisan: [['Is everything you sell handmade by you?', 'yesno', true], ['Link to your portfolio or shop', 'short', false]],
    Service: [['Describe the service you will offer', 'long', true], ['Do you need power or water?', 'short', false]]
  };

  function setQuestions(next, now) {
    S.draft.state.applications.questions = next;
    set('applications.questions', next, { now: now });
  }

  Builder.steps.applications = function () {
    var st = S.draft.state, app = st.applications;
    var modes = [
      ['review', 'You review every application', 'Vendors apply; you approve before anyone pays.'],
      ['invite', 'Invite only', 'Only vendors you invite can apply. Hidden from public search.'],
      ['closed', 'Not accepting yet', 'Publish the event now, open applications later.']
    ];
    var modeCards = h('div', { class: 'eb-choice-cards', role: 'group', 'aria-label': 'Application mode' }, modes.map(function (m) {
      return h('button', { type: 'button', class: 'eb-choice', 'aria-pressed': app.mode === m[0] ? 'true' : 'false', onclick: function () { set('applications.mode', m[0], { now: true }); app.mode = m[0]; renderBuilder(); } }, [h('strong', { text: m[1] }), h('span', { text: m[2] })]);
    }));
    var prefilled = ['Business name and description', 'Contact details', 'Photos and portfolio', 'Categories and home city', 'Approved documents already on file'];
    return [
      card('How vendors join', null, modeCards),
      card('What vendors already share', 'Pulled from each vendor\'s LNKup Passport, so they don\'t type it again.', h('ul', { style: 'margin:0;padding:0;display:flex;gap:6px;flex-wrap:wrap' }, prefilled.map(function (p) { return h('li', { style: 'list-style:none' }, h('span', { class: 'eb-chip', text: '✓ ' + p })); }))),
      questionsCard(),
      documentsCard(),
      card('Application preview', 'What a vendor sees when they apply.', liveBox('appPreview'), { testid: 'eb-app-preview' })
    ];
  };

  function questionsCard() {
    var st = S.draft.state;
    var qs = st.applications.questions;
    var list = h('div', { class: 'eb-questions', 'data-testid': 'eb-questions' });
    if (!qs.length) append(list, h('p', { class: 'eb-help', text: 'No extra questions. Add only what you need for this event.' }));
    var scopes = [['all', 'All vendors']].concat((st.options || []).filter(function (o) { return o.category; }).map(function (o) { return [o.key, 'Only ' + (o.name || o.category)]; }));
    qs.forEach(function (q, i) {
      function upd(key, val, now) { var n = clone(qs); n[i][key] = val; setQuestions(n, now); }
      var label = h('input', { type: 'text', value: q.label, placeholder: 'Question', maxlength: 200, 'aria-label': 'Question text', oninput: function () { upd('label', label.value); } });
      var scope = h('select', { 'aria-label': 'Who is asked', onchange: function () { upd('scope', scope.value, true); } }, scopes.map(function (s) { return h('option', { value: s[0], selected: q.scope === s[0], text: s[1] }); }));
      var req = h('input', { type: 'checkbox', checked: q.required, onchange: function () { upd('required', req.checked, true); } });
      var move = function (d) { var n = clone(qs); var t = n[i + d]; n[i + d] = n[i]; n[i] = t; setQuestions(n, true); renderBuilder(); };
      var choices = null;
      if (q.type === 'choice') {
        var ta = h('textarea', { rows: 3, placeholder: 'One choice per line', 'aria-label': 'Choices' });
        ta.value = (q.options || []).join('\n');
        ta.addEventListener('input', function () { upd('options', ta.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean)); });
        choices = field('Choices', ta, { help: 'At least two.' });
      }
      append(list, h('div', { class: 'eb-q' }, [
        h('div', { class: 'eb-q__head' }, [
          h('span', { class: 'eb-q__type', text: (QUESTION_TYPES.find(function (t) { return t[0] === q.type; }) || ['', 'Question'])[1] }),
          h('div', { class: 'eb-option__actions' }, [
            i > 0 ? h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Move question up', onclick: function () { move(-1); } }, icon('up')) : null,
            i < qs.length - 1 ? h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Move question down', onclick: function () { move(1); } }, icon('down')) : null,
            h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Duplicate question', onclick: function () { var n = clone(qs); var c = clone(q); c.id = uid('q'); n.splice(i + 1, 0, c); setQuestions(n, true); renderBuilder(); } }, icon('copy')),
            h('button', { type: 'button', class: 'eb-iconbtn', 'aria-label': 'Remove question', onclick: function () { var n = clone(qs); n.splice(i, 1); setQuestions(n, true); renderBuilder(); } }, icon('trash'))
          ])
        ]),
        label,
        h('div', { class: 'eb-grid' }, [h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Asked to' }), scope]), h('label', { class: 'eb-check', style: 'align-self:end' }, [req, h('span', { text: 'Required' })])]),
        choices
      ]));
    });
    var addMenu = h('div', { class: 'eb-addmenu' }, QUESTION_TYPES.map(function (t) {
      return h('button', { type: 'button', class: 'eb-chip', text: '+ ' + t[1], onclick: function () { setQuestions(qs.concat([{ id: uid('q'), label: '', type: t[0], required: false, options: t[0] === 'choice' ? ['', ''] : [], help: '', scope: 'all' }]), true); renderBuilder(); } });
    }));
    var sets = h('div', { class: 'eb-addmenu' }, Object.keys(QUESTION_SETS).map(function (name) {
      return h('button', { type: 'button', class: 'eb-chip eb-chip--line', text: name + ' set', onclick: function () {
        var add = QUESTION_SETS[name].filter(function (row) { return !qs.some(function (q) { return q.label === row[0]; }); }).map(function (row) { return { id: uid('q'), label: row[0], type: row[1], required: row[2], options: [], help: '', scope: 'all' }; });
        setQuestions(qs.concat(add), true); renderBuilder();
      } });
    }));
    return card('Questions for this event', 'Up to 20. Vendors answer these on top of their Passport.', [list, h('p', { class: 'eb-help', style: 'margin:12px 0 6px', text: 'Add a question' }), addMenu, h('p', { class: 'eb-help', style: 'margin:12px 0 6px', text: 'Or start from a set (you can edit everything)' }), sets]);
  }

  function documentsCard() {
    var st = S.draft.state, docs = st.applications.documents;
    var known = (S.config && S.config.documents) || [];
    function setDocs(next, now) { st.applications.documents = next; set('applications.documents', next, { now: now }); }
    var modes = h('div', { class: 'eb-choice-cards' }, [
      ['none', 'No documents', 'Vendors are not asked for documents.'],
      ['platform_defaults', 'LNKup defaults', 'Use the standard documents for each vendor category.'],
      ['custom', 'Choose documents', 'Pick exactly what you need and when.']
    ].map(function (m) {
      return h('button', { type: 'button', class: 'eb-choice', 'aria-pressed': docs.mode === m[0] ? 'true' : 'false', onclick: function () { setDocs({ mode: m[0], items: m[0] === 'custom' ? docs.items : [] }, true); renderBuilder(); } }, [h('strong', { text: m[1] }), h('span', { text: m[2] })]);
    }));
    var items = null;
    if (docs.mode === 'custom') {
      items = h('div', { style: 'display:grid;gap:8px;margin-top:12px' }, known.map(function (d) {
        var cur = docs.items.find(function (x) { return x.key === d.key; });
        var cb = h('input', { type: 'checkbox', checked: !!cur });
        var timing = h('select', { 'aria-label': 'When ' + d.label + ' is due', disabled: !cur }, [['before_event', 'Due before the event'], ['before_approval', 'Due before approval']].map(function (t) { return h('option', { value: t[0], selected: cur && cur.timing === t[0], text: t[1] }); }));
        function save() {
          var list = docs.items.filter(function (x) { return x.key !== d.key; });
          if (cb.checked) list.push({ key: d.key, timing: timing.value, required: true });
          timing.disabled = !cb.checked;
          setDocs({ mode: 'custom', items: list }, true);
        }
        cb.addEventListener('change', save); timing.addEventListener('change', save);
        return h('div', { class: 'eb-linkrow', style: 'grid-template-columns:auto 1fr auto' }, [cb, h('span', { style: 'font-weight:650', text: d.label }), timing]);
      }));
    }
    return card('Documents', 'Reusable documents in a vendor\'s Passport count automatically.', [modes, items, h('p', { class: 'eb-help', style: 'margin-top:10px', text: 'LNKup does not decide which permits your city requires. Ask vendors for what your venue and local rules need.' })]);
  }

  Builder.live.appPreview = function () {
    var st = S.draft.state;
    var qs = st.applications.questions;
    var docs = st.applications.documents;
    var rows = [h('div', { class: 'eb-detail__opt' }, [h('span', { text: 'Business profile, contact and photos' }), h('span', { class: 'eb-chip eb-chip--success', text: 'From Passport' })])];
    qs.forEach(function (q) { rows.push(h('div', { class: 'eb-detail__opt' }, [h('span', { text: (q.label || 'Untitled question') + (q.required ? ' *' : '') }), h('span', { class: 'eb-chip eb-chip--line', text: q.scope === 'all' ? 'Everyone' : 'Some options' })])); });
    if (docs.mode === 'custom') docs.items.forEach(function (d) { var k = ((S.config && S.config.documents) || []).find(function (x) { return x.key === d.key; }); rows.push(h('div', { class: 'eb-detail__opt' }, [h('span', { text: (k ? k.label : d.key) }), h('span', { class: 'eb-chip eb-chip--warning', text: d.timing === 'before_approval' ? 'Before applying' : 'Before the event' })])); });
    if (docs.mode === 'platform_defaults') rows.push(h('div', { class: 'eb-detail__opt' }, [h('span', { text: 'Standard documents for the vendor\'s category' }), h('span', { class: 'eb-chip eb-chip--line', text: 'LNKup defaults' })]));
    var modeText = { review: 'You review each application before the vendor pays.', invite: 'Only invited vendors can apply.', closed: 'Applications are not open yet.' }[st.applications.mode];
    return [h('p', { class: 'eb-help', style: 'margin-bottom:8px', text: modeText }), h('div', { class: 'eb-detail__opts' }, rows)];
  };

  /* ------------------------------------------------------------------ step: payments */

  Builder.steps.payments = function () {
    var st = S.draft.state, pay = st.payments;
    var paid = st.options.some(function (o) { return o.fee > 0; });
    var dep = pay.deposit;
    var depFields = h('div', { class: 'eb-grid', style: 'margin-top:10px', hidden: !dep.enabled }, [
      h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Deposit type' }), segmented('payments.deposit.type', [['percent', 'Percent'], ['fixed', 'Fixed amount']])]),
      field('Deposit', textInput('payments.deposit.value', { type: 'number', min: 0, step: '0.01', inputmode: 'decimal' }), { help: 'Vendors pay the deposit after approval and the rest by your due date.' })
    ]);
    var depToggle = h('input', { type: 'checkbox', checked: dep.enabled, onchange: function () { set('payments.deposit.enabled', depToggle.checked, { now: true }); depFields.hidden = !depToggle.checked; } });
    var ddl = pay.deadline;
    var ddlInputs = h('div', { class: 'eb-grid', style: 'margin-top:10px' });
    function drawDeadline() {
      clear(ddlInputs);
      if (ddl.mode === 'fixed') append(ddlInputs, field('Deadline', textInput('payments.deadline.date', { type: 'date' }), { check: 'deadline', showError: true }));
      if (ddl.mode === 'relative') append(ddlInputs, field('Days before the event', textInput('payments.deadline.days_before', { type: 'number', min: 0, inputmode: 'numeric' }), { help: 'Useful for templates, recurring dates and tours.' }));
      if (ddl.mode === 'default') append(ddlInputs, h('p', { class: 'eb-help', text: 'Applications close 7 days before the event (or 48 hours from now for events within a week).' }));
    }
    drawDeadline();
    return [
      card('Booth fees', paid ? 'Calculated by LNKup\'s fee engine for your account.' : 'All options are free for vendors.', liveBox('fees'), { testid: 'eb-fees-card' }),
      paid ? card('Payouts', null, liveBox('payouts')) : null,
      paid ? card('Deposit', null, [h('label', { class: 'eb-check' }, [depToggle, h('span', {}, ['Collect a deposit after approval', h('small', { text: 'Optional. The remaining balance follows your normal payment rules.' })])]), depFields]) : null,
      card('Application deadline', null, [segmented('payments.deadline.mode', [['default', 'LNKup default'], ['fixed', 'Pick a date'], ['relative', 'Days before event']], function (m) { ddl.mode = m; drawDeadline(); }), ddlInputs]),
      card('Cancellations and refunds', 'LNKup\'s marketplace terms always apply. Add anything specific to your event.', field('Your cancellation note', textInput('payments.cancellation_note', { multiline: true, rows: 4, max: 1200, placeholder: 'e.g. If the event is postponed, booked vendors keep their space for the new date or can request a refund.' }), { optional: true }))
    ];
  };

  Builder.live.fees = function () {
    if (!S.fees.length) return h('p', { class: 'eb-help', text: 'Add vendor options to see fees.' });
    return h('div', { class: 'eb-table-wrap' }, h('table', { class: 'eb-fees' }, [
      h('thead', {}, h('tr', {}, ['Vendor option', 'Booth fee', 'LNKup fee', 'Processing', 'Tax', 'Vendor pays', 'You receive'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
      h('tbody', {}, S.fees.map(function (f) {
        return h('tr', {}, f.free
          ? [h('td', { text: f.name || 'Option' }), h('td', { text: 'Free' }), h('td', { text: '—' }), h('td', { text: '—' }), h('td', { text: '—' }), h('td', {}, h('strong', { text: '$0' })), h('td', { text: '$0' })]
          : [h('td', { text: f.name || 'Option' }), h('td', { text: money(f.fee) }), h('td', { text: money(f.vendor_checkout_fee) }), h('td', { text: money(f.processing_fee) }), h('td', { text: money(f.tax_amount) }), h('td', {}, h('strong', { text: money(f.vendor_total) })), h('td', { text: money(f.organizer_net) })]);
      }))
    ]));
  };

  Builder.live.payouts = function () {
    var ready = S.config && S.config.payouts_ready;
    if (ready) return h('div', { class: 'eb-notice eb-notice--success' }, h('div', {}, [h('strong', { text: 'Payouts active' }), h('span', { text: 'Booth fees are paid out to your connected account.' })]));
    var back = encodeURIComponent(window.location.href);
    return h('div', { class: 'eb-notice eb-notice--warning' }, h('div', {}, [
      h('strong', { text: 'Finish payout setup' }),
      h('span', { text: 'You can publish now and receive applications. Set up payouts before approving paid vendors. Your draft stays right here.' }),
      h('div', { class: 'eb-notice__actions' }, h('a', { class: 'eb-btn eb-btn--sm eb-btn--dark', href: cfg.urls.financial + (cfg.urls.financial.indexOf('?') >= 0 ? '&' : '?') + 'return_to=' + back, onclick: function () { flush(); } }, 'Set up payouts'))
    ]));
  };

  /* ------------------------------------------------------------------ step: publish */

  Builder.steps.publish = function () {
    var st = S.draft.state;
    var stage = h('div', { class: 'eb-stage', 'data-testid': 'eb-preview-stage' }, loading('Building preview…'));
    flush().then(function () { renderPreviewStage(stage); });
    var whenFields = h('div', { class: 'eb-grid', style: 'margin-top:10px', hidden: st.publish.when !== 'schedule' }, [
      field('Publish at', textInput('publish.at', { type: 'datetime-local' }), { help: 'Your local time.', check: 'schedule', showError: true })
    ]);
    var attest = st.sources.length || st.import.provider ? h('label', { class: 'eb-check', style: 'margin-top:12px' }, [
      h('input', { type: 'checkbox', checked: st.publish.attest, 'data-testid': 'eb-attest', onchange: function (e) { set('publish.attest', e.target.checked, { now: true }); } }),
      h('span', {}, ['I organize this event or am authorized to manage its vendors', h('small', { text: 'Linking a listing does not prove ownership. Disputed events are reviewed by LNKup.' })])
    ]) : null;
    return [
      card('Preview', 'Exactly what vendors and visitors see.', [
        h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px' }, [
          h('div', { class: 'eb-seg' }, [['vendor', 'Signed-in vendor'], ['guest', 'Visitor']].map(function (m) { return h('button', { type: 'button', 'aria-pressed': S.previewMode === m[0] ? 'true' : 'false', text: m[1], onclick: function () { S.previewMode = m[0]; renderBuilder(); } }); })),
          h('div', { class: 'eb-seg' }, [['desktop', 'Desktop'], ['mobile', 'Mobile']].map(function (m) { return h('button', { type: 'button', 'aria-pressed': S.device === m[0] ? 'true' : 'false', text: m[1], onclick: function () { S.device = m[0]; renderBuilder(); } }); }))
        ]),
        stage
      ]),
      card('Publishing checklist', null, liveBox('checklist'), { testid: 'eb-checklist' }),
      isEdit() ? null : card('Publishing options', null, [
        h('div', { class: 'eb-grid' }, [
          h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'When' }), segmented('publish.when', [['now', 'Publish now'], ['schedule', 'Schedule']], function (v) { whenFields.hidden = v !== 'schedule'; })]),
          h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: 'Applications' }), segmented('publish.applications', [['open', 'Open right away'], ['closed', 'Keep closed for now']])])
        ]),
        whenFields,
        attest
      ])
    ];
  };

  Builder.live.checklist = function () {
    var checks = (S.report && S.report.checks) || [];
    var groups = [['required', 'Required'], ['recommended', 'Recommended'], ['optional', 'Optional']];
    return groups.map(function (g) {
      var items = checks.filter(function (c) { return c.level === g[0]; });
      if (!items.length) return null;
      return h('div', { style: 'margin-bottom:12px' }, [
        h('h3', { class: 'eb-eyebrow', style: 'margin-bottom:8px', text: g[1] }),
        h('div', { class: 'eb-checklist' }, items.map(function (c) {
          return h('div', { class: 'eb-checkrow', 'data-ok': c.ok ? 'true' : 'false', 'data-level': c.level }, [
            h('span', { class: 'eb-checkrow__icon', 'aria-hidden': 'true', text: c.ok ? '✓' : (c.level === 'required' ? '!' : '•') }),
            h('div', {}, [h('strong', { text: c.label }), !c.ok && c.message ? h('p', { text: c.message }) : null]),
            !c.ok && c.step !== 'publish' ? h('button', { type: 'button', class: 'eb-btn eb-btn--sm', text: 'Fix', onclick: function () { goStep(c.step); } }) : null
          ]);
        }))
      ]);
    });
  };

  function renderPreviewStage(stage) {
    return api('organizer/builder/drafts/' + S.draft.id + '/preview', { query: { mode: S.previewMode } }).then(function (p) {
      clear(stage);
      var hero = h('div', { class: 'eb-detail__hero' }, p.image ? h('img', { src: p.image, alt: '' }) : h('div', { class: 'eb-pcard__fallback', text: p.title }));
      var opts = h('div', { class: 'eb-detail__opts' }, p.options.length ? p.options.map(function (o) {
        return h('div', { class: 'eb-detail__opt' }, [
          h('div', {}, [h('strong', { text: o.name }), h('p', { class: 'eb-help', text: [o.category, o.dimensions].filter(Boolean).join(' · ') })]),
          h('strong', { text: o.price === null ? 'Sign in to see' : (o.free ? 'Free' : money(o.price)) })
        ]);
      }) : h('p', { class: 'eb-help', text: 'No vendor options yet.' }));
      var official = p.sources && p.sources.length ? h('div', { class: 'eb-official' }, [
        h('strong', { text: 'Official event listing' }),
        h('span', { class: 'eb-help', text: p.disclosure }),
        h('div', { class: 'eb-notice__actions' }, p.sources.map(function (s) { return h('span', { class: 'eb-chip eb-chip--line', text: 'View on ' + s.label }); }))
      ]) : null;
      append(stage, h('div', { class: 'eb-stage__frame', 'data-device': S.device }, h('div', { class: 'eb-detail' }, [
        hero,
        h('div', { class: 'eb-detail__body' }, [
          h('div', {}, [h('span', { class: 'eb-chip eb-chip--lime', text: p.applications === 'open' ? 'Booking vendors' : (p.applications === 'invite' ? 'Invite only' : 'Applications closed') }), h('h3', { class: 'eb-detail__title', style: 'margin-top:8px', text: p.title })]),
          h('p', { class: 'eb-pcard__meta', text: [p.date, p.time].filter(Boolean).join(' · ') }),
          h('p', { class: 'eb-pcard__meta', text: p.place }),
          h('p', { class: 'eb-pcard__meta', text: 'By ' + p.organizer }),
          p.attendance ? h('p', { class: 'eb-help', text: p.attendance }) : null,
          p.description ? h('p', { style: 'white-space:pre-line', text: p.description.slice(0, 600) + (p.description.length > 600 ? '…' : '') }) : null,
          h('h4', { text: 'Vendor options' }),
          opts,
          p.pricing_note ? h('p', { class: 'eb-help', text: p.pricing_note }) : null,
          p.deadline ? h('p', { class: 'eb-help', text: 'Apply by ' + fmtDate(p.deadline) }) : null,
          h('div', {}, h('span', { class: 'eb-btn eb-btn--primary', 'aria-hidden': 'true', text: p.mode === 'guest' ? 'Sign in to apply' : 'View vendor options' })),
          official
        ])
      ])));
    }).catch(function (e) { clear(stage); append(stage, h('p', { class: 'eb-error', text: e.message })); });
  }

  function confirmPublish() {
    flush().then(function () {
      if (!S.report || !S.report.publishable) {
        toast('Finish the required items first.', 'error');
        if (S.step !== 'publish') goStep('publish');
        return;
      }
      var st = S.draft.state, sum = S.report.summary;
      var scheduled = st.publish.when === 'schedule';
      var d = dialog({
        title: scheduled ? 'Schedule this event?' : 'Publish this event?',
        body: [
          previewCard(st),
          h('dl', { class: 'eb-kv' }, [
            kv('Vendor options', String(sum.types)), kv('Spaces', String(sum.spaces)),
            kv('Applications', st.applications.mode === 'closed' || st.publish.applications === 'closed' ? 'Closed for now' : (st.applications.mode === 'invite' ? 'Invite only' : 'Open — you review each one')),
            kv('Goes live', scheduled ? new Date(st.publish.at).toLocaleString() : 'Right away')
          ]),
          h('p', { class: 'eb-help', text: 'You can edit the event after publishing. Changes that affect booked vendors are reviewed first.' })
        ],
        foot: [
          h('button', { type: 'button', class: 'eb-btn', text: 'Keep editing', onclick: function () { d.close(false); } }),
          h('button', { type: 'button', class: 'eb-btn eb-btn--primary', 'data-testid': 'eb-confirm-publish', text: scheduled ? 'Schedule' : 'Publish event', onclick: function (e) { e.target.disabled = true; d.close(true); doPublish(); } })
        ]
      });
    });
  }

  function doPublish() {
    screen(loading('Publishing…'));
    api('organizer/builder/drafts/' + S.draft.id + '/publish', { method: 'POST', body: {} }).then(function (r) {
      dropBackup();
      if (r.scheduled) { toast('Scheduled. It will publish at ' + new Date(r.publish_at).toLocaleString() + '.'); S.scheduledAt = r.publish_at; renderBuilder(); return; }
      track('event_published', { method: S.draft.state.method });
      success(r);
    }).catch(function (e) {
      renderBuilder();
      if (e.data && e.data.checks) { toast('Finish the required items first.', 'error'); goStep('publish'); return; }
      toast(e.message, 'error');
    });
  }

  function success(r) {
    navigate({}, true);
    var copy = function (val) { if (navigator.clipboard) navigator.clipboard.writeText(val).then(function () { toast('Link copied.'); }); };
    var qr = window.LNKupQR && r.public_url ? h('img', { src: window.LNKupQR.dataUrl(r.public_url, 200), alt: 'QR code for the event page' }) : null;
    var linkRow = function (label, val) {
      if (!val) return null;
      return h('div', { class: 'eb-field' }, [h('span', { class: 'eb-field__label', text: label }), h('div', { class: 'eb-copy' }, [h('input', { type: 'text', readonly: true, value: val, 'aria-label': label }), h('button', { type: 'button', class: 'eb-btn eb-btn--dark eb-btn--sm', text: 'Copy', onclick: function () { copy(val); } })])]);
    };
    var actions = [
      ['Find vendors', 'Search vendors that match your categories.', r.find_vendors, 'search'],
      ['Invite vendors', 'Send invitations to vendors you know.', r.invite_url, 'send'],
      ['Open event dashboard', 'Applications, money and readiness.', r.dashboard_url, 'grid'],
      ['Event operations', 'Packet, booth map and check-in — when you are ready.', r.operations_url, 'tool']
    ];
    screen(h('div', { class: 'eb-success', 'data-testid': 'eb-success' }, [
      h('div', { class: 'eb-success__hero' }, [
        h('div', { style: 'position:relative;z-index:1' }, [
          h('span', { class: 'eb-chip', text: '✓ Published' }),
          h('h1', { style: 'margin-top:12px', text: r.title + ' is live' }),
          h('p', { text: 'Vendors can find it now. Share your link to bring in your first applications.' }),
          h('div', { class: 'eb-notice__actions', style: 'margin-top:16px' }, [
            h('a', { class: 'eb-btn eb-btn--primary', href: r.public_url, target: '_blank', rel: 'noopener', text: 'View listing' }),
            navigator.share ? h('button', { type: 'button', class: 'eb-btn', text: 'Share', onclick: function () { navigator.share({ title: r.title, url: r.public_url }).catch(function () {}); } }) : null
          ])
        ]),
        qr ? h('div', { class: 'eb-success__qr' }, [qr, h('span', { class: 'eb-help', text: 'Scan to open' })]) : null
      ]),
      h('div', { class: 'eb-card' }, [linkRow('Public event page', r.public_url), h('div', { style: 'height:10px' }), linkRow('Vendor application link', r.apply_url)]),
      h('div', {}, [h('h2', { class: 'eb-eyebrow', style: 'margin-bottom:10px', text: 'Next steps' }), h('div', { class: 'eb-next' }, actions.filter(function (a) { return a[2]; }).map(function (a) {
        return h('a', { class: 'eb-method', href: a[2] }, [h('span', { class: 'eb-method__icon' }, icon(a[3])), h('span', {}, [h('span', { class: 'eb-method__title', text: a[0] }), h('span', { class: 'eb-method__desc', text: a[1] })])]);
      }))]),
      h('div', {}, h('a', { class: 'eb-link', href: cfg.urls.create, text: 'Create another event' }))
    ]));
  }
  Builder.success = success;

  /* ------------------------------------------------------------------ edit review */

  function reviewEdit() {
    flush().then(function () { return api('organizer/builder/drafts/' + S.draft.id + '/review'); }).then(function (rv) {
      var labels = { 'event.name': 'Name', 'event.date': 'Date', 'event.end_date': 'End date', 'event.start_time': 'Start time', 'event.end_time': 'End time', 'event.city': 'City', 'event.state': 'State', 'event.venue_name': 'Venue', 'event.venue_address': 'Address', 'event.description': 'Description', 'event.event_type': 'Category', 'event.format': 'Setting', 'option.new': 'New option', 'option.removed': 'Removed option' };
      var rows = rv.changes.map(function (c) {
        return h('div', { class: 'eb-diff__row' }, [h('strong', {}, [labels[c.field] || 'Vendor option', c.material ? h('span', { class: 'eb-chip eb-chip--warning', style: 'margin-left:6px', text: 'Affects vendors' }) : null]), h('del', { text: (c.before || '—').slice(0, 140) }), h('ins', { text: (c.after || '—').slice(0, 140) })]);
      });
      var warn = rv.material && rv.booked ? h('div', { class: 'eb-notice eb-notice--warning' }, h('div', {}, [
        h('strong', { text: rv.booked + ' booked vendor' + (rv.booked === 1 ? '' : 's') + ' will be notified' }),
        h('span', { text: 'Date, venue and location changes are sent to booked vendors. ' + (rv.pricing ? 'New prices apply to new applications only — existing bookings and invoices keep their amounts.' : '') })
      ])) : null;
      var d = dialog({
        title: rv.changes.length ? 'Review changes' : 'No changes to save',
        wide: true,
        body: [warn, rv.changes.length ? h('div', { class: 'eb-diff' }, rows) : h('p', { class: 'eb-help', text: 'This edit matches the live event.' })],
        foot: [
          h('button', { type: 'button', class: 'eb-btn', text: 'Keep editing', onclick: function () { d.close(false); } }),
          rv.changes.length ? h('button', { type: 'button', class: 'eb-btn eb-btn--primary', 'data-testid': 'eb-save-edit', text: 'Save changes', onclick: function () {
            d.close(true);
            screen(loading('Saving changes…'));
            api('organizer/builder/drafts/' + S.draft.id + '/publish', { method: 'POST', body: { confirm: true } }).then(function (r) {
              dropBackup();
              toast(r.message || 'Event updated.');
              if (r.warnings && r.warnings.length) toast(r.warnings[0], 'error');
              window.location.href = r.dashboard_url || cfg.urls.events;
            }).catch(function (e) { renderBuilder(); toast(e.message, 'error'); });
          } }) : null
        ]
      });
    }).catch(function (e) { toast(e.message, 'error'); });
  }

  /* ------------------------------------------------------------------ boot */

  Builder.methodCard = methodCard;
  Builder.navigate = navigate;
  Builder.useDraft = useDraft;
  Builder.renderPreviewStage = renderPreviewStage;
  document.dispatchEvent(new CustomEvent('lnkup:builder-ready', { detail: Builder }));
  setTimeout(function () { boot(false); }, 0);
})();
