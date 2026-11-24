/**
 * Organizer dashboard → Marketing (LNKup_Organizer_Marketing).
 *
 * Share & promote (tracked links + local QR), graphics (SVG built from real event data, exported
 * to PNG in the browser), copy templates (editable, never auto-posted), website embeds and
 * per-link performance with fact-based suggestions.
 */
(function () {
  'use strict';

  var cfg = window.lnkupOrgMarketing || {};
  var root = document.querySelector('[data-testid="lnkup-org-marketing"]');
  if (!root || !cfg.rest) return;

  var initialTab = new URLSearchParams(window.location.search).get('om_tab');
  var state = { ctx: null, eventId: 0, tab: ['share', 'graphics', 'copy', 'embed', 'performance'].indexOf(initialTab) !== -1 ? initialTab : 'share', links: [], perf: null, graphic: { template: 'applications_open', format: 'portrait', cobrand: true, link: '' } };
  var select = root.querySelector('[data-om-event]');
  var statusEl = root.querySelector('[data-om-status]');

  var CHANNEL_LABELS = { instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', x: 'X', linkedin: 'LinkedIn', email: 'Email', sms: 'Text message', whatsapp: 'WhatsApp', qr: 'QR / print', print: 'Flyer', website: 'Website', partner: 'Partner', app: 'App share', other: 'Other' };
  var FORMATS = {
    portrait: { w: 1080, h: 1350, label: 'Instagram post (4:5)' },
    square: { w: 1080, h: 1080, label: 'Square (1:1)' },
    story: { w: 1080, h: 1920, label: 'Story / Reel (9:16)' },
    landscape: { w: 1200, h: 630, label: 'Facebook / landscape' },
    poster: { w: 1275, h: 1650, label: 'Flyer (8.5×11)' }
  };

  /* ---------------------------------------------------------------- helpers */

  function api(path, opts) {
    opts = opts || {};
    return fetch(cfg.rest + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) throw new Error('Something went wrong. Please refresh and try again.');
      return res.json().then(function (json) {
        if (!res.ok) throw new Error((json && json.message) || 'Something went wrong. Please try again.');
        return json;
      });
    });
  }

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  function panel(name) { return root.querySelector('[data-om-panel="' + name + '"]'); }

  function event() {
    var list = (state.ctx && state.ctx.events) || [];
    for (var i = 0; i < list.length; i++) if (list[i].id === state.eventId) return list[i];
    return null;
  }

  function toast(text, isError) {
    statusEl.innerHTML = '';
    statusEl.appendChild(h('p', { class: 'lnkup-om__toast' + (isError ? ' is-error' : ''), text: text }));
    clearTimeout(toast.t);
    toast.t = setTimeout(function () { statusEl.innerHTML = ''; }, 4000);
  }

  function copy(text) {
    var done = function () { toast('Copied.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); });
    fallbackCopy(text);
  }

  function fallbackCopy(text) {
    var ta = h('textarea', { style: 'position:fixed;opacity:0' });
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('Copied.'); } catch (e) { toast('Copy failed. Select the text and copy it manually.', true); }
    ta.remove();
  }

  function download(blob, name) {
    var a = h('a', { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  function slug(s) { return String(s || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }

  function errorCard(target, message, retry) {
    target.innerHTML = '';
    target.appendChild(h('div', { class: 'lnkup-om__error', role: 'alert' }, [
      h('p', { text: message }),
      retry ? h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Try again', onclick: retry }) : null
    ]));
  }

  function loading(target) {
    target.innerHTML = '';
    target.appendChild(h('div', { class: 'lnkup-om__skeleton', 'aria-hidden': 'true' }));
  }

  /* ---------------------------------------------------------------- boot */

  function boot() {
    statusEl.innerHTML = '';
    statusEl.appendChild(h('div', { class: 'lnkup-om__skeleton', 'aria-hidden': 'true' }));
    api('context').then(function (ctx) {
      state.ctx = ctx;
      statusEl.innerHTML = '';
      select.innerHTML = '';
      if (!ctx.events || !ctx.events.length) {
        select.appendChild(h('option', { text: 'No published events' }));
        root.querySelectorAll('[data-om-panel]').forEach(function (p) { p.hidden = true; });
        statusEl.appendChild(h('div', { class: 'lnkup-om__empty', 'data-testid': 'lnkup-org-marketing-empty' }, [
          h('h3', { text: 'Publish an event to start promoting it' }),
          h('p', { text: 'Tracked links, graphics and posts are built from your published events.' }),
          h('a', { class: 'lnkup-btn lnkup-btn--primary', href: (cfg.site || '/') + 'organizer-dashboard/?view=create', text: 'Launch an event' })
        ]));
        return;
      }
      ctx.events.forEach(function (ev) {
        select.appendChild(h('option', { value: String(ev.id), text: ev.title + (ev.date_label ? ' · ' + ev.date_label : '') }));
      });
      select.disabled = false;
      var fromUrl = parseInt(new URLSearchParams(window.location.search).get('event') || '0', 10);
      state.eventId = ctx.events.some(function (e) { return e.id === fromUrl; }) ? fromUrl : ctx.events[0].id;
      select.value = String(state.eventId);
      switchTab(state.tab);
    }).catch(function (err) {
      errorCard(statusEl, err.message, boot);
    });
  }

  select.addEventListener('change', function () {
    state.eventId = parseInt(select.value, 10);
    state.links = [];
    state.perf = null;
    switchTab(state.tab);
  });

  root.querySelectorAll('[data-om-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () { switchTab(btn.getAttribute('data-om-tab')); });
    btn.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-om-tab]'));
      var next = tabs[(tabs.indexOf(btn) + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      next.focus();
      switchTab(next.getAttribute('data-om-tab'));
    });
  });

  function switchTab(name) {
    state.tab = name;
    root.querySelectorAll('[data-om-tab]').forEach(function (b) {
      var on = b.getAttribute('data-om-tab') === name;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
    });
    root.querySelectorAll('[data-om-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-om-panel') !== name; });
    if (!event()) return;
    ({ share: renderShare, graphics: renderGraphics, copy: renderCopy, embed: renderEmbed, performance: renderPerformance }[name] || renderShare)();
  }

  function loadLinks() {
    return api('links?event_id=' + state.eventId).then(function (json) {
      state.links = json.links || [];
      return state.links;
    });
  }

  function linkName(link) {
    return link.label || CHANNEL_LABELS[link.channel] || link.channel;
  }

  /* ---------------------------------------------------------------- share */

  function renderShare() {
    var target = panel('share');
    var ev = event();
    loading(target);
    loadLinks().then(function (links) {
      target.innerHTML = '';
      target.appendChild(h('div', { class: 'lnkup-om__card' }, [
        h('h3', { text: ev.title }),
        h('p', { class: 'lnkup-om__muted', text: [ev.date_label, ev.where].filter(Boolean).join(' · ') }),
        h('div', { class: 'lnkup-om__row' }, [
          h('input', { class: 'lnkup-om__url', readonly: true, value: ev.url, 'aria-label': 'Public event link' }),
          h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy link', onclick: function () { copy(ev.url); } }),
          navigator.share ? h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Share…', onclick: function () { navigator.share({ title: ev.title, url: ev.url }).catch(function () {}); } }) : null
        ])
      ]));

      var form = h('form', { class: 'lnkup-om__card lnkup-om__create', 'data-testid': 'lnkup-om-create-link' }, [
        h('h3', { text: 'Create a tracked link' }),
        h('p', { class: 'lnkup-om__muted', text: 'Make one link per place you share. Clicks, applications and bookings are counted per link.' }),
        h('div', { class: 'lnkup-om__row' }, [
          h('label', {}, [h('span', { text: 'Where will you share it?' }), channelSelect()]),
          h('label', {}, [h('span', { text: 'Name (optional)' }), h('input', { name: 'label', maxlength: '120', placeholder: 'e.g. Spring flyer' })]),
          h('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary', text: 'Create link' })
        ])
      ]);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        api('links', { method: 'POST', body: { target_type: 'event', target_id: ev.id, channel: form.channel.value, label: form.label.value } }).then(function () {
          toast('Link created.');
          renderShare();
        }).catch(function (err) { toast(err.message, true); btn.disabled = false; });
      });
      target.appendChild(form);

      if (!links.length) {
        target.appendChild(h('div', { class: 'lnkup-om__empty' }, [h('p', { text: 'No tracked links for this event yet.' })]));
        return;
      }
      var list = h('ul', { class: 'lnkup-om__links', 'data-testid': 'lnkup-om-links' });
      links.forEach(function (link) { list.appendChild(linkRow(link)); });
      target.appendChild(list);
    }).catch(function (err) { errorCard(target, err.message, renderShare); });
  }

  function channelSelect() {
    var sel = h('select', { name: 'channel' });
    ((state.ctx && state.ctx.channels) || Object.keys(CHANNEL_LABELS)).forEach(function (c) {
      sel.appendChild(h('option', { value: c, text: CHANNEL_LABELS[c] || c }));
    });
    return sel;
  }

  function linkRow(link) {
    var qrBox = h('div', { class: 'lnkup-om__qr', hidden: true });
    var li = h('li', { class: 'lnkup-om__link' + (link.status !== 'active' ? ' is-paused' : ''), 'data-link-code': link.code }, [
      h('div', { class: 'lnkup-om__link-main' }, [
        h('strong', { text: linkName(link) }),
        h('code', { text: link.url }),
        h('span', { class: 'lnkup-om__muted', text: link.clicks + ' clicks · ' + link.applications + ' applications · ' + link.bookings + ' bookings' + (link.status !== 'active' ? ' · Paused' : '') })
      ]),
      h('div', { class: 'lnkup-om__link-actions' }, [
        h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy', onclick: function () { copy(link.url); } }),
        h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'QR code', 'aria-expanded': 'false', onclick: function (e) {
          var open = qrBox.hidden;
          qrBox.hidden = !open;
          e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (open && !qrBox.childNodes.length) {
            var src = window.LNKupQR ? window.LNKupQR.dataUrl(link.url, 200) : '';
            qrBox.appendChild(src ? h('img', { src: src, width: '200', height: '200', alt: 'QR code for ' + linkName(link) }) : h('p', { text: 'QR code unavailable.' }));
            qrBox.appendChild(h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Download PNG', onclick: function () {
              window.LNKupQR.pngBlob(link.url, 1024).then(function (b) { download(b, 'lnkup-qr-' + link.code + '.png'); }).catch(function (err) { toast(err.message, true); });
            } }));
          }
        } }),
        h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost', text: link.status === 'active' ? 'Pause' : 'Resume', onclick: function () {
          api('links/' + link.id, { method: 'POST', body: { status: link.status === 'active' ? 'paused' : 'active' } }).then(renderShare).catch(function (err) { toast(err.message, true); });
        } })
      ]),
      qrBox
    ]);
    return li;
  }

  /* ---------------------------------------------------------------- graphics */

  var TEMPLATES = [
    { id: 'applications_open', label: 'Applications open' },
    { id: 'calling_category', label: 'Calling a vendor category', needs: function (ev) { return ev.categories.length ? '' : 'Add vendor categories to your spots first.'; } },
    { id: 'final_spaces', label: 'Final spaces', needs: function (ev) { return ev.open_spots > 0 && ev.open_spots <= 15 ? '' : 'Shown when 1–15 spots are left.'; } },
    { id: 'closing_soon', label: 'Closing soon', needs: function (ev) { return ev.deadline && ev.open_spots > 0 ? '' : 'Needs an application deadline and open spots.'; } },
    { id: 'now_booking', label: 'Now booking (categories)', needs: function (ev) { return ev.categories.length ? '' : 'Add vendor categories to your spots first.'; } },
    { id: 'save_the_date', label: 'Save the date', needs: function (ev) { return ev.date ? '' : 'Needs an event date.'; } },
    { id: 'free_to_apply', label: 'Free to apply' },
    { id: 'tour', label: 'Tour', needs: function (ev) { return ev.tour ? '' : 'This event is not part of a tour.'; } },
    { id: 'scan_to_apply', label: 'Scan to apply (QR)' },
    { id: 'thank_you', label: 'Thank you, vendors' }
  ];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function wrap(text, size, maxWidth, maxLines) {
    var words = String(text || '').split(/\s+/).filter(Boolean);
    var lines = [];
    var line = '';
    var perChar = size * 0.56;
    words.forEach(function (w) {
      var test = line ? line + ' ' + w : w;
      if (test.length * perChar > maxWidth && line) { lines.push(line); line = w; } else { line = test; }
    });
    if (line) lines.push(line);
    if (lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] = lines[maxLines - 1].replace(/[\s,.]*$/, '') + '…'; }
    return lines;
  }

  function imageData(url) {
    if (!url) return Promise.resolve('');
    imageData.cache = imageData.cache || {};
    if (imageData.cache[url]) return Promise.resolve(imageData.cache[url]);
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('image'); return r.blob(); }).then(function (blob) {
      return new Promise(function (resolve) {
        var fr = new FileReader();
        fr.onload = function () { imageData.cache[url] = String(fr.result); resolve(imageData.cache[url]); };
        fr.onerror = function () { resolve(''); };
        fr.readAsDataURL(blob);
      });
    }).catch(function () { return ''; });
  }

  function graphicCopy(tpl, ev) {
    var cat = ev.categories[0] ? ev.categories[0].label : '';
    var cats = ev.categories.slice(0, 4).map(function (c) { return c.label; });
    var meta = [ev.date_label, ev.where].filter(Boolean).join('  ·  ');
    switch (tpl) {
      case 'calling_category': return { kicker: ev.title, head: 'Calling all ' + cat + ' vendors', sub: meta };
      case 'final_spaces': return { kicker: ev.title, head: 'Final vendor spaces', sub: ev.open_spots + (ev.open_spots === 1 ? ' spot left' : ' spots left') + (meta ? '  ·  ' + meta : '') };
      case 'closing_soon': return { kicker: ev.title, head: 'Applications close ' + ev.deadline_label, sub: meta };
      case 'now_booking': return { kicker: ev.title, head: 'Now booking', list: cats, sub: meta };
      case 'save_the_date': return { kicker: 'Save the date', head: ev.title, sub: meta };
      case 'free_to_apply': return { kicker: ev.title, head: 'Free to apply', sub: 'Pay the booth fee only if you are approved' };
      case 'tour': return { kicker: 'On tour', head: ev.tour ? ev.tour.title : ev.title, sub: 'Next stop: ' + meta };
      case 'scan_to_apply': return { kicker: ev.title, head: 'Scan to apply', sub: meta, qr: true };
      case 'thank_you': return { kicker: ev.title, head: 'Thank you, vendors!', sub: 'See you at the next one' };
      default: return { kicker: 'Vendor applications open', head: ev.title, sub: meta };
    }
  }

  function buildSvg(ev, opts) {
    var f = FORMATS[opts.format] || FORMATS.portrait;
    var W = f.w, H = f.h;
    var pad = Math.round(W * 0.07);
    var c = graphicCopy(opts.template, ev);
    var org = (state.ctx && state.ctx.organizer) || {};
    return Promise.all([imageData(ev.image), imageData(org.logo)]).then(function (imgs) {
      var bg = imgs[0], logo = imgs[1];
      var out = ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">'];
      out.push('<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0b0b0c" stop-opacity="0.25"/><stop offset="0.55" stop-color="#0b0b0c" stop-opacity="0.7"/><stop offset="1" stop-color="#0b0b0c" stop-opacity="0.95"/></linearGradient></defs>');
      out.push('<rect width="100%" height="100%" fill="#0b0b0c"/>');
      if (bg) out.push('<image href="' + bg + '" x="0" y="0" width="' + W + '" height="' + H + '" preserveAspectRatio="xMidYMid slice"/>');
      out.push('<rect width="100%" height="100%" fill="url(#g)"/>');
      var font = 'Arial, Helvetica, sans-serif';
      var y = pad;
      if (logo) { out.push('<image href="' + logo + '" x="' + pad + '" y="' + y + '" width="' + Math.round(W * 0.12) + '" height="' + Math.round(W * 0.12) + '" preserveAspectRatio="xMidYMid meet"/>'); }
      if (org.name) out.push('<text x="' + (logo ? pad + Math.round(W * 0.14) : pad) + '" y="' + (y + Math.round(W * 0.07)) + '" font-family="' + font + '" font-size="' + Math.round(W * 0.034) + '" font-weight="700" fill="#ffffff">' + esc(org.name) + '</text>');
      var headSize = Math.round(W * (H / W > 1.5 ? 0.1 : 0.085));
      var headLines = wrap(c.head, headSize, W - pad * 2, 4);
      var listLines = (c.list || []).slice(0, 4);
      var qrSize = c.qr ? Math.round(Math.min(W, H) * 0.34) : 0;
      var blockH = Math.round(W * 0.05) + headLines.length * headSize * 1.08 + listLines.length * headSize * 0.62 + Math.round(W * 0.06) + (qrSize ? qrSize + pad * 0.5 : 0) + (opts.cobrand ? Math.round(W * 0.08) : 0);
      y = H - pad - blockH;
      out.push('<rect x="' + pad + '" y="' + y + '" rx="' + Math.round(W * 0.025) + '" height="' + Math.round(W * 0.05) + '" width="' + Math.min(W - pad * 2, Math.round(String(c.kicker).length * W * 0.019 + W * 0.06)) + '" fill="#c8f542"/>');
      out.push('<text x="' + (pad + Math.round(W * 0.025)) + '" y="' + (y + Math.round(W * 0.034)) + '" font-family="' + font + '" font-size="' + Math.round(W * 0.03) + '" font-weight="700" fill="#1a2400">' + esc(wrap(c.kicker, W * 0.03, W - pad * 2.5, 1)[0] || '') + '</text>');
      y += Math.round(W * 0.05) + headSize;
      headLines.forEach(function (l) { out.push('<text x="' + pad + '" y="' + Math.round(y) + '" font-family="' + font + '" font-size="' + headSize + '" font-weight="800" fill="#ffffff">' + esc(l) + '</text>'); y += headSize * 1.08; });
      listLines.forEach(function (l) { out.push('<text x="' + pad + '" y="' + Math.round(y) + '" font-family="' + font + '" font-size="' + Math.round(headSize * 0.5) + '" font-weight="700" fill="#c8f542">• ' + esc(l) + '</text>'); y += headSize * 0.62; });
      if (c.sub) { y += Math.round(W * 0.01); out.push('<text x="' + pad + '" y="' + Math.round(y) + '" font-family="' + font + '" font-size="' + Math.round(W * 0.034) + '" font-weight="400" fill="#e8ebe2">' + esc(wrap(c.sub, W * 0.034, W - pad * 2, 1)[0] || '') + '</text>'); y += Math.round(W * 0.05); }
      if (qrSize) {
        var qr = window.LNKupQR ? window.LNKupQR.dataUrl(opts.link || ev.url, qrSize) : '';
        if (qr) { out.push('<rect x="' + (pad - 8) + '" y="' + Math.round(y - 8) + '" width="' + (qrSize + 16) + '" height="' + (qrSize + 16) + '" rx="16" fill="#ffffff"/><image href="' + qr + '" x="' + pad + '" y="' + Math.round(y) + '" width="' + qrSize + '" height="' + qrSize + '"/>'); }
        y += qrSize + pad * 0.5;
      }
      if (opts.cobrand) out.push('<text x="' + pad + '" y="' + Math.round(H - pad * 0.6) + '" font-family="' + font + '" font-size="' + Math.round(W * 0.026) + '" font-weight="700" fill="#c8f542">Apply on LNKup</text>');
      out.push('</svg>');
      return out.join('');
    });
  }

  function svgToPng(svg, format) {
    var f = FORMATS[format] || FORMATS.portrait;
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = f.w;
        canvas.height = f.h;
        canvas.getContext('2d').drawImage(img, 0, 0, f.w, f.h);
        canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('Export failed')); }, 'image/png');
      };
      img.onerror = function () { reject(new Error('The graphic could not be drawn.')); };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });
  }

  function renderGraphics() {
    var target = panel('graphics');
    var ev = event();
    loading(target);
    loadLinks().then(function (links) {
      target.innerHTML = '';
      var g = state.graphic;
      var tplSel = h('select', { 'data-om-template': true });
      TEMPLATES.forEach(function (t) {
        var reason = t.needs ? t.needs(ev) : '';
        tplSel.appendChild(h('option', { value: t.id, disabled: !!reason, text: t.label + (reason ? ' (' + reason + ')' : '') }));
      });
      if (TEMPLATES.some(function (t) { return t.id === g.template && !(t.needs && t.needs(ev)); })) tplSel.value = g.template; else { g.template = 'applications_open'; tplSel.value = g.template; }
      var fmtSel = h('select', { 'data-om-format': true });
      Object.keys(FORMATS).forEach(function (k) { fmtSel.appendChild(h('option', { value: k, text: FORMATS[k].label })); });
      fmtSel.value = g.format;
      var linkSel = h('select', {});
      linkSel.appendChild(h('option', { value: ev.url, text: 'Public event link' }));
      links.filter(function (l) { return l.status === 'active'; }).forEach(function (l) { linkSel.appendChild(h('option', { value: l.url, text: 'Tracked: ' + linkName(l) })); });
      var cob = h('input', { type: 'checkbox', checked: g.cobrand });
      var preview = h('div', { class: 'lnkup-om__preview', 'data-testid': 'lnkup-om-graphic-preview' });
      var redraw = function () {
        g.template = tplSel.value; g.format = fmtSel.value; g.cobrand = cob.checked; g.link = linkSel.value;
        preview.innerHTML = '';
        preview.appendChild(h('div', { class: 'lnkup-om__skeleton', 'aria-hidden': 'true' }));
        buildSvg(ev, g).then(function (svg) {
          preview.innerHTML = '';
          preview.appendChild(h('img', { src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg), alt: 'Preview: ' + tplSel.options[tplSel.selectedIndex].text }));
          preview.setAttribute('data-svg-ready', '1');
        }).catch(function () { errorCard(preview, 'The preview could not be drawn.', redraw); });
      };
      [tplSel, fmtSel, linkSel, cob].forEach(function (el) { el.addEventListener('change', redraw); });
      target.appendChild(h('div', { class: 'lnkup-om__card lnkup-om__graphics' }, [
        h('div', { class: 'lnkup-om__controls' }, [
          h('label', {}, [h('span', { text: 'Template' }), tplSel]),
          h('label', {}, [h('span', { text: 'Size' }), fmtSel]),
          h('label', {}, [h('span', { text: 'QR / link' }), linkSel]),
          h('label', { class: 'lnkup-om__check' }, [cob, h('span', { text: 'Show "Apply on LNKup"' })]),
          h('p', { class: 'lnkup-om__muted', text: ev.image_is_fallback ? 'Uses LNKup artwork because this event has no photo yet.' : 'Uses your event photo.' }),
          h('div', { class: 'lnkup-om__row' }, [
            h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Download PNG', 'data-testid': 'lnkup-om-download-png', onclick: function () {
              buildSvg(ev, g).then(function (svg) { return svgToPng(svg, g.format); }).then(function (b) { download(b, slug(ev.title) + '-' + g.template + '-' + g.format + '.png'); }).catch(function (err) { toast(err.message, true); });
            } }),
            h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline', text: 'Download SVG', onclick: function () {
              buildSvg(ev, g).then(function (svg) { download(new Blob([svg], { type: 'image/svg+xml' }), slug(ev.title) + '-' + g.template + '.svg'); });
            } })
          ])
        ]),
        preview
      ]));
      redraw();
    }).catch(function (err) { errorCard(target, err.message, renderGraphics); });
  }

  /* ---------------------------------------------------------------- copy */

  function fillCopy(template, ev, link) {
    var org = (state.ctx && state.ctx.organizer && state.ctx.organizer.name) || '';
    var values = {
      title: ev.title, date: ev.date_label, city: ev.city, where: ev.where, link: link,
      categories: ev.categories.slice(0, 5).map(function (c) { return c.label; }).join(', '),
      deadline: ev.deadline_label, organizer: org
    };
    return template.split('\n').filter(function (line) {
      var keys = line.match(/\{(\w+)\}/g) || [];
      return keys.every(function (k) { return values[k.slice(1, -1)]; });
    }).map(function (line) {
      return line.replace(/\{(\w+)\}/g, function (_, k) { return values[k] || ''; });
    }).join('\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  function renderCopy() {
    var target = panel('copy');
    var ev = event();
    loading(target);
    loadLinks().then(function (links) {
      target.innerHTML = '';
      var templates = (state.ctx && state.ctx.copy) || {};
      var channelFor = { instagram: 'instagram', facebook_group: 'facebook', email: 'email', sms: 'sms', deadline: 'instagram', food_truck: 'facebook', partner: 'partner' };
      target.appendChild(h('p', { class: 'lnkup-om__muted', text: 'Edit anything before you post. LNKup never posts for you.' }));
      Object.keys(templates).forEach(function (key) {
        var tpl = templates[key];
        var channel = channelFor[key] || 'other';
        var tracked = links.filter(function (l) { return l.status === 'active' && l.channel === channel; })[0];
        var ta = h('textarea', { rows: '6', 'aria-label': tpl.label, 'data-om-copy': key });
        ta.value = fillCopy(tpl.text, ev, tracked ? tracked.url : ev.url);
        var actions = h('div', { class: 'lnkup-om__row' }, [
          h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy text', onclick: function () { copy(ta.value); } })
        ]);
        if (!tracked) {
          actions.appendChild(h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Use a tracked ' + (CHANNEL_LABELS[channel] || channel) + ' link', onclick: function (e) {
            e.currentTarget.disabled = true;
            api('links', { method: 'POST', body: { target_type: 'event', target_id: ev.id, channel: channel, label: tpl.label } }).then(function (json) {
              ta.value = ta.value.split(ev.url).join(json.link.url);
              toast('Tracked link created and added.');
              state.links.push(json.link);
            }).catch(function (err) { toast(err.message, true); });
          } }));
        }
        target.appendChild(h('div', { class: 'lnkup-om__card' }, [h('h3', { text: tpl.label }), ta, actions]));
      });
    }).catch(function (err) { errorCard(target, err.message, renderCopy); });
  }

  /* ---------------------------------------------------------------- embeds */

  function renderEmbed() {
    var target = panel('embed');
    var ev = event();
    loading(target);
    loadLinks().then(function (links) {
      target.innerHTML = '';
      var embed = (state.ctx && state.ctx.embed) || {};
      var web = links.filter(function (l) { return l.status === 'active' && l.channel === 'website'; })[0];
      var lnk = web ? ' data-lnkup-link="' + web.code + '"' : '';
      var snippets = [
        { title: 'All open vendor spots for this event', code: '<div data-lnkup-list="event:' + ev.id + '"' + lnk + '></div>\n<script src="' + embed.list_script + '" async></script>' }
      ];
      if (ev.tour) snippets.push({ title: 'Upcoming tour stops', code: '<div data-lnkup-list="tour:' + ev.tour.id + '"' + lnk + '></div>\n<script src="' + embed.list_script + '" async></script>' });
      ev.opportunities.filter(function (o) { return o.open; }).slice(0, 5).forEach(function (o) {
        snippets.push({ title: 'Apply button: ' + o.title, code: '<div data-lnkup-apply="' + o.id + '"></div>\n<script src="' + embed.apply_script + '" async></script>' });
      });
      target.appendChild(h('p', { class: 'lnkup-om__muted', text: web ? 'Embeds are tracked with your "' + linkName(web) + '" website link.' : 'Tip: create a tracked link for "Website" to count applications from your site.' }));
      snippets.forEach(function (s) {
        var ta = h('textarea', { rows: '3', readonly: true, 'aria-label': s.title });
        ta.value = s.code;
        target.appendChild(h('div', { class: 'lnkup-om__card' }, [h('h3', { text: s.title }), ta, h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy code', onclick: function () { copy(s.code); } })]));
      });
    }).catch(function (err) { errorCard(target, err.message, renderEmbed); });
  }

  /* ---------------------------------------------------------------- performance */

  function renderPerformance() {
    var target = panel('performance');
    loading(target);
    api('performance?event_id=' + state.eventId + '&days=30').then(function (perf) {
      target.innerHTML = '';
      var e = perf.event || {};
      var stats = h('ul', { class: 'lnkup-om__stats', 'data-testid': 'lnkup-om-stats' });
      [['Listing views', e.views], ['Applications', e.applications], ['Approved', e.approved], ['Paid bookings', e.bookings], ['Open spots', e.open_spots]].forEach(function (s) {
        stats.appendChild(h('li', {}, [h('strong', { text: String(s[1] || 0) }), h('span', { text: s[0] })]));
      });
      target.appendChild(stats);
      var recs = perf.recommendations || [];
      if (recs.length) {
        var list = h('ul', { class: 'lnkup-om__recs', 'data-testid': 'lnkup-om-recs' });
        recs.forEach(function (r) {
          list.appendChild(h('li', {}, [
            h('span', { text: r.text }),
            r.tab ? h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost', text: 'Open', onclick: function () { if (r.template) state.graphic.template = r.template; switchTab(r.tab); } }) : null
          ]));
        });
        target.appendChild(h('div', { class: 'lnkup-om__card' }, [h('h3', { text: 'Suggestions' }), h('p', { class: 'lnkup-om__muted', text: 'Based only on this event’s current numbers. Results are not guaranteed.' }), list]));
      }
      var links = perf.links || [];
      if (!links.length) {
        target.appendChild(h('div', { class: 'lnkup-om__empty' }, [h('p', { text: 'Create tracked links to see clicks and applications per channel.' }), h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Create a link', onclick: function () { switchTab('share'); } })]));
        return;
      }
      var table = h('table', { class: 'lnkup-om__table' }, [
        h('thead', {}, [h('tr', {}, ['Link', 'Clicks', 'Unique', 'Sign-ups', 'Applications', 'Bookings'].map(function (c) { return h('th', { scope: 'col', text: c }); }))]),
        h('tbody', {}, links.map(function (l) {
          return h('tr', {}, [h('th', { scope: 'row', text: linkName(l) })].concat([l.clicks, l.unique_clicks, l.signups, l.applications, l.bookings].map(function (n) { return h('td', { text: String(n) }); })));
        }))
      ]);
      target.appendChild(h('div', { class: 'lnkup-om__card lnkup-om__table-wrap' }, [h('h3', { text: 'Tracked links' }), table]));
    }).catch(function (err) { errorCard(target, err.message, renderPerformance); });
  }

  boot();
})();
