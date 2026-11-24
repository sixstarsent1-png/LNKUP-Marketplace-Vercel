/**
 * Connector dashboard → Share & Track (LNKup_Connector_Marketing).
 *
 * Tracked links (with the connector's referral code), local QR, weekly digest links and a market
 * share graphic with connector branding. Renders into the existing connector panel when the
 * "marketing" view is active.
 */
(function () {
  'use strict';

  var cfg = window.lnkupConnectorMarketing || {};
  var panel = document.querySelector('[data-connector-panel]');
  if (!panel || !cfg.rest) return;

  var CHANNELS = { instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', x: 'X', linkedin: 'LinkedIn', email: 'Email', sms: 'Text message', whatsapp: 'WhatsApp', qr: 'QR / print', print: 'Flyer', website: 'Website', partner: 'Partner', app: 'App share', other: 'Other' };
  var state = { ctx: null, links: [] };

  function api(path, opts) {
    opts = opts || {};
    return fetch(cfg.rest + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      if ((res.headers.get('content-type') || '').indexOf('application/json') === -1) throw new Error('Something went wrong. Please refresh and try again.');
      return res.json().then(function (json) {
        if (!res.ok) throw new Error((json && json.message) || 'Something went wrong.');
        return json;
      });
    });
  }

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  function copy(text, note) {
    var done = function () { note.textContent = 'Copied.'; note.hidden = false; };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function marketSvg(market, link, name) {
    var W = 1080, H = 1350, pad = 76, font = 'Arial, Helvetica, sans-serif';
    var qr = window.LNKupQR ? window.LNKupQR.dataUrl(link || market.url, 300) : '';
    var cats = (market.categories || []).slice(0, 4).map(function (c) { return c.label; });
    var out = ['<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">',
      '<rect width="100%" height="100%" fill="#0b0b0c"/>',
      '<rect x="' + pad + '" y="' + pad + '" rx="26" width="420" height="56" fill="#c8f542"/>',
      '<text x="' + (pad + 26) + '" y="' + (pad + 38) + '" font-family="' + font + '" font-size="30" font-weight="700" fill="#1a2400">Vendors wanted</text>',
      '<text x="' + pad + '" y="330" font-family="' + font + '" font-size="96" font-weight="800" fill="#fff">' + esc(market.name) + '</text>'];
    var y = 420;
    out.push('<text x="' + pad + '" y="' + y + '" font-family="' + font + '" font-size="40" fill="#e8ebe2">' + esc(market.open_events > 0 ? market.open_events + (market.open_events === 1 ? ' event booking vendors' : ' events booking vendors') : 'Get alerts when events open') + '</text>');
    y += 80;
    cats.forEach(function (c) { out.push('<text x="' + pad + '" y="' + y + '" font-family="' + font + '" font-size="44" font-weight="700" fill="#c8f542">• ' + esc(c) + '</text>'); y += 62; });
    if (qr) out.push('<rect x="' + (pad - 12) + '" y="' + (H - pad - 372) + '" width="324" height="324" rx="18" fill="#fff"/><image href="' + qr + '" x="' + pad + '" y="' + (H - pad - 360) + '" width="300" height="300"/>');
    out.push('<text x="' + (pad + 340) + '" y="' + (H - pad - 220) + '" font-family="' + font + '" font-size="38" font-weight="700" fill="#fff">Scan to apply free</text>');
    if (name) out.push('<text x="' + (pad + 340) + '" y="' + (H - pad - 160) + '" font-family="' + font + '" font-size="28" fill="#e8ebe2">Shared by ' + esc(name) + ', LNKup City Connector</text>');
    out.push('<text x="' + (pad + 340) + '" y="' + (H - pad - 110) + '" font-family="' + font + '" font-size="28" font-weight="700" fill="#c8f542">Find vendor spots on LNKup</text>');
    out.push('</svg>');
    return out.join('');
  }

  function downloadPng(svg, filename) {
    var img = new Image();
    img.onload = function () {
      var c = document.createElement('canvas');
      c.width = 1080; c.height = 1350;
      c.getContext('2d').drawImage(img, 0, 0);
      c.toBlob(function (b) {
        if (!b) return;
        var a = h('a', { href: URL.createObjectURL(b), download: filename });
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      }, 'image/png');
    };
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }

  var busy = false;

  function render() {
    busy = true;
    panel.innerHTML = '';
    panel.appendChild(h('div', { class: 'lnkup-skeleton lnkup-skeleton--card', 'aria-hidden': 'true' }));
    Promise.all([api('context'), api('links')]).then(function (res) {
      state.ctx = res[0];
      state.links = res[1].links || [];
      panel.innerHTML = '';
      var root = h('div', { class: 'lnkup-connector-panel lnkup-om', 'data-testid': 'lnkup-connector-marketing' });
      var note = h('p', { class: 'lnkup-om__toast', role: 'status', 'aria-live': 'polite', hidden: true });
      root.appendChild(note);
      root.appendChild(h('p', { class: 'lnkup-om__muted', text: 'Every link carries your referral code, so sign-ups and bookings are credited to you exactly as with your regular referral links. Links show clicks, sign-ups, applications and bookings.' }));

      var markets = state.ctx.markets || [];
      if (!markets.length) {
        root.appendChild(h('div', { class: 'lnkup-om__empty' }, [h('p', { text: 'No city market pages are live yet. Your regular referral links still work.' })]));
      }
      var marketSel = h('select', { name: 'market' });
      markets.forEach(function (m) { marketSel.appendChild(h('option', { value: m.slug, text: m.name + ', ' + m.state + (m.mine ? ' (your market)' : '') })); });
      var channelSel = h('select', { name: 'channel' });
      (state.ctx.channels || Object.keys(CHANNELS)).forEach(function (c) { channelSel.appendChild(h('option', { value: c, text: CHANNELS[c] || c })); });
      var targetSel = h('select', { name: 'target' }, [h('option', { value: 'market', text: 'City market page' }), h('option', { value: 'signup_vendor', text: 'Vendor sign-up' }), h('option', { value: 'signup_organizer', text: 'Organizer sign-up' }), h('option', { value: 'feed', text: 'All open events' })]);
      var label = h('input', { name: 'label', maxlength: '120', placeholder: 'e.g. Farmers market flyer' });
      var form = h('form', { class: 'lnkup-om__card' }, [
        h('h3', { text: 'Create a tracked link' }),
        h('div', { class: 'lnkup-om__row' }, [
          h('label', {}, [h('span', { text: 'Opens' }), targetSel]),
          h('label', {}, [h('span', { text: 'Market' }), marketSel]),
          h('label', {}, [h('span', { text: 'Where you share it' }), channelSel]),
          h('label', {}, [h('span', { text: 'Name (optional)' }), label]),
          h('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary', text: 'Create link' })
        ])
      ]);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var t = targetSel.value;
        var body = { channel: channelSel.value, label: label.value, target_type: t.indexOf('signup') === 0 ? 'signup' : t, target_key: t === 'market' ? marketSel.value : (t === 'signup_vendor' ? 'vendor' : (t === 'signup_organizer' ? 'organizer' : '')) };
        api('links', { method: 'POST', body: body }).then(render).catch(function (err) { note.textContent = err.message; note.hidden = false; });
      });
      root.appendChild(form);

      markets.slice(0, 6).forEach(function (m) {
        var tracked = state.links.filter(function (l) { return l.status === 'active' && l.target_type === 'market' && l.target_key === m.slug; })[0];
        var card = h('div', { class: 'lnkup-om__card' }, [
          h('h3', { text: m.name + ', ' + m.state }),
          h('p', { class: 'lnkup-om__muted', text: (m.open_events > 0 ? m.open_events + ' events booking vendors' : 'No open events right now') + (m.categories.length ? ' · Wanted: ' + m.categories.map(function (c) { return c.label; }).join(', ') : '') }),
          h('div', { class: 'lnkup-om__row' }, [
            h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy page link', onclick: function () { copy(tracked ? tracked.url : m.url, note); } }),
            m.digest_url ? h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Copy "next two weeks" link', onclick: function () { copy(m.digest_url, note); } }) : null,
            h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Download share graphic', onclick: function () { downloadPng(marketSvg(m, tracked ? tracked.url : '', state.ctx.connector.name), 'lnkup-' + m.slug + '.png'); } })
          ]),
          tracked ? null : h('p', { class: 'lnkup-om__muted', text: 'Tip: create a tracked link for this market so the graphic\'s QR code is counted.' })
        ]);
        root.appendChild(card);
      });

      if (state.links.length) {
        var list = h('ul', { class: 'lnkup-om__links', 'data-testid': 'lnkup-connector-links' });
        state.links.forEach(function (l) {
          var qr = h('div', { class: 'lnkup-om__qr', hidden: true });
          list.appendChild(h('li', { class: 'lnkup-om__link' + (l.status !== 'active' ? ' is-paused' : '') }, [
            h('div', { class: 'lnkup-om__link-main' }, [
              h('strong', { text: l.label || CHANNELS[l.channel] || l.channel }),
              h('code', { text: l.url }),
              h('span', { class: 'lnkup-om__muted', text: l.clicks + ' clicks · ' + l.signups + ' sign-ups · ' + l.applications + ' applications · ' + l.bookings + ' bookings' })
            ]),
            h('div', { class: 'lnkup-om__link-actions' }, [
              h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Copy', onclick: function () { copy(l.url, note); } }),
              h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'QR code', onclick: function () {
                qr.hidden = !qr.hidden;
                if (!qr.childNodes.length && window.LNKupQR) qr.appendChild(h('img', { src: window.LNKupQR.dataUrl(l.url, 200), width: '200', height: '200', alt: 'QR code' }));
              } }),
              h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost', text: l.status === 'active' ? 'Pause' : 'Resume', onclick: function () {
                api('links/' + l.id, { method: 'POST', body: { status: l.status === 'active' ? 'paused' : 'active' } }).then(render).catch(function (err) { note.textContent = err.message; note.hidden = false; });
              } })
            ]),
            qr
          ]));
        });
        root.appendChild(h('div', { class: 'lnkup-om__card' }, [h('h3', { text: 'Your tracked links' }), list]));
      } else {
        root.appendChild(h('div', { class: 'lnkup-om__empty' }, [h('p', { text: 'No tracked links yet. Create one per place you share to see what works.' })]));
      }
      panel.appendChild(root);
    }).catch(function (err) {
      panel.innerHTML = '';
      panel.appendChild(h('div', { class: 'lnkup-empty-state lnkup-empty-state--compact', role: 'alert', 'data-testid': 'lnkup-connector-marketing' }, [h('p', { text: err.message }), h('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm', text: 'Try again', onclick: render })]));
    }).then(function () {
      busy = false;
    });
  }

  function maybeRender() {
    if (!busy && panel.getAttribute('data-view') === 'marketing' && !panel.querySelector('[data-testid="lnkup-connector-marketing"]')) {
      render();
    }
  }

  new MutationObserver(function () { setTimeout(maybeRender, 0); }).observe(panel, { attributes: true, attributeFilter: ['data-view'], childList: true });
  maybeRender();
})();
