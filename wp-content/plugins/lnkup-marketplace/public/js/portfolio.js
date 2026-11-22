/**
 * Organizer Tours & Series workspace (lnkup/v1/portfolio).
 *
 * Views (URL driven, ?view=tours):
 *   list:   portfolio of tours / series, templates and brands
 *   &new=:  identity step of the setup wizard (tour | recurring | series)
 *   &tour=: tour dashboard with tabs overview | stops | setup | pricing | applications | vendors | settings
 *
 * WordPress stays the system of record: every change is a REST call and the view re-reads the
 * server response. Each panel has loading, empty and error states; nothing spins forever.
 */
(function () {
  'use strict';

  var cfg = window.lnkupPortfolio || {};
  var mount = document.getElementById('lnkup-portfolio');
  if (!mount || !cfg.root) {
    return;
  }

  var TYPE_LABELS = { tour: 'Multi-city tour', recurring: 'Recurring market', series: 'Event series' };
  var STATE_LABELS = { draft: 'Draft', published: 'Live', past: 'Past', cancelled: 'Cancelled', postponed: 'Postponed', skipped: 'Skipped' };
  var APP_LABELS = {
    submitted: 'New', under_review: 'In review', info_requested: 'Info requested', approved: 'Approved',
    payment_required: 'Awaiting payment', paid: 'Paid', completed: 'Completed', waitlisted: 'Waitlisted',
    rejected: 'Declined', cancelled: 'Withdrawn'
  };
  var OUTCOME_LABELS = { change: 'Will change', confirm: 'Live stop: needs confirmation', blocked: 'Not changed', unchanged: 'No change' };
  var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var overview = null;
  var lastPublish = null;
  var pendingFlash = null;
  var renderToken = 0;

  // ------------------------------------------------------------------ helpers

  function h(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) {
        return;
      }
      if (k === 'className') {
        node.className = v;
      } else if (k === 'text') {
        node.textContent = v;
      } else if (k.indexOf('on') === 0 && typeof v === 'function') {
        node.addEventListener(k.slice(2).toLowerCase(), v);
      } else if (k === 'value') {
        node.value = v;
      } else if (k === 'checked' || k === 'disabled' || k === 'hidden' || k === 'required' || k === 'selected') {
        node[k] = !!v;
      } else {
        node.setAttribute(k, v === true ? '' : v);
      }
    });
    (Array.isArray(children) ? children : [children]).forEach(function (c) {
      if (c === null || c === undefined || c === false) {
        return;
      }
      node.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return node;
  }

  function api(method, path, body, headers) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: Object.assign({ 'X-WP-Nonce': cfg.nonce || '' }, headers || {})
    };
    var url = cfg.root.replace(/\/$/, '') + path;
    if (body && method === 'GET') {
      var qs = new URLSearchParams();
      Object.keys(body).forEach(function (k) {
        if (body[k] !== '' && body[k] !== null && body[k] !== undefined) {
          qs.append(k, body[k]);
        }
      });
      url += (url.indexOf('?') === -1 ? '?' : '&') + qs.toString();
    } else if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        return res.text().then(function (t) {
          console.error('LNKup portfolio: expected JSON', t.slice(0, 300));
          return { ok: false, status: res.status, data: null, message: 'We could not reach the server. Try again.' };
        });
      }
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data, message: res.ok ? '' : (data && data.message) || 'Something went wrong. Try again.' };
      });
    }).catch(function (err) {
      console.error('LNKup portfolio API error', err);
      return { ok: false, status: 0, data: null, message: 'You appear to be offline. Check your connection and try again.' };
    });
  }

  function requestKey() {
    var a = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return 'pf' + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function money(n) {
    var v = Number(n || 0);
    return '$' + v.toLocaleString(undefined, { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
  }

  function dateLabel(iso) {
    if (!iso) {
      return 'Date TBA';
    }
    var d = new Date(iso + 'T12:00:00');
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function params() {
    return new URLSearchParams(window.location.search);
  }

  function go(query) {
    var url = new URL(cfg.viewUrl, window.location.origin);
    Object.keys(query || {}).forEach(function (k) {
      if (query[k] !== null && query[k] !== undefined && query[k] !== '') {
        url.searchParams.set(k, query[k]);
      }
    });
    window.history.pushState({}, '', url.toString());
    route();
    mount.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  function setBusy(el) {
    el.innerHTML = '';
    el.appendChild(h('div', { className: 'lnkup-portfolio__loading', role: 'status' }, [
      h('span', { className: 'lnkup-skeleton lnkup-skeleton--line' }),
      h('span', { className: 'lnkup-skeleton lnkup-skeleton--line' }),
      h('span', { className: 'screen-reader-text', text: 'Loading…' })
    ]));
  }

  function errorCard(el, message, retry) {
    el.innerHTML = '';
    el.appendChild(h('div', { className: 'lnkup-alert lnkup-alert--error', role: 'alert' }, [
      h('p', { text: message || 'We could not load this section. Try refreshing.' }),
      retry ? h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', onclick: retry, text: 'Try again' }) : null
    ]));
  }

  function flash(el, message, tone) {
    var box = h('p', { className: 'lnkup-alert lnkup-alert--' + (tone || 'success'), role: tone === 'error' ? 'alert' : 'status', text: message });
    el.insertBefore(box, el.firstChild);
    window.setTimeout(function () {
      if (box.parentNode) {
        box.parentNode.removeChild(box);
      }
    }, 8000);
  }

  function badge(text, tone) {
    return h('span', { className: 'lnkup-badge' + (tone ? ' lnkup-badge--' + tone : ''), text: text });
  }

  function stateBadge(state) {
    var tone = { published: 'success', draft: 'muted', cancelled: 'danger', skipped: 'muted', past: 'muted', postponed: 'warning' }[state];
    return badge(STATE_LABELS[state] || state, tone);
  }

  function scope(text) {
    return h('p', { className: 'lnkup-portfolio__scope', text: text });
  }

  function field(label, input, help) {
    var id = input.id || ('pf-' + Math.random().toString(36).slice(2, 9));
    input.id = id;
    return h('div', { className: 'lnkup-form-row' }, [
      h('label', { for: id, text: label }),
      input,
      help ? h('small', { className: 'lnkup-muted', text: help }) : null
    ]);
  }

  function input(name, value, attrs) {
    return h('input', Object.assign({ type: 'text', name: name, value: value === undefined || value === null ? '' : String(value) }, attrs || {}));
  }

  function select(name, options, value, attrs) {
    var el = h('select', Object.assign({ name: name }, attrs || {}));
    options.forEach(function (o) {
      el.appendChild(h('option', { value: o[0], selected: String(o[0]) === String(value), text: o[1] }));
    });
    return el;
  }

  function timezoneSelect(name, value, attrs) {
    var zones = (overview && overview.timezones) || [];
    var opts = [['', 'Choose time zone']].concat(zones.map(function (z) { return [z, z.replace(/_/g, ' ')]; }));
    return select(name, opts, value || (overview && overview.site_timezone) || '', attrs);
  }

  function formData(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) {
        return;
      }
      if (el.type === 'checkbox') {
        out[el.name] = el.checked;
      } else {
        out[el.name] = el.value;
      }
    });
    return out;
  }

  function loadOverview(force) {
    if (overview && !force) {
      return Promise.resolve({ ok: true, data: overview });
    }
    return api('GET', '/portfolio/overview').then(function (res) {
      if (res.ok) {
        overview = res.data;
      }
      return res;
    });
  }

  // ------------------------------------------------------------------ router

  function route() {
    var p = params();
    var tourId = parseInt(p.get('tour') || '0', 10);
    if (tourId) {
      renderTour(tourId, p.get('tab') || 'overview');
    } else if (p.get('new')) {
      renderCreate(p.get('new'));
    } else {
      renderList();
    }
  }

  window.addEventListener('popstate', route);

  // ------------------------------------------------------------------ list

  function renderList() {
    var token = ++renderToken;
    setBusy(mount);
    loadOverview(true).then(function (res) {
      if (token !== renderToken) {
        return;
      }
      if (!res.ok) {
        errorCard(mount, res.message, renderList);
        return;
      }
      mount.innerHTML = '';
      var filter = { type: '', brand: '', archived: false };
      var start = h('section', { className: 'lnkup-portfolio__start', 'aria-labelledby': 'pf-start-title' }, [
        h('h2', { id: 'pf-start-title', text: 'Start something new' }),
        h('div', { className: 'lnkup-portfolio__choices' }, [
          choice('tour', 'Multi-city tour', 'One brand, many cities. Add every stop in a table.'),
          choice('recurring', 'Recurring market', 'Weekly, monthly or custom dates generated for you.'),
          choice('series', 'Event series', 'Related events under one brand with different dates or venues.')
        ])
      ]);
      mount.appendChild(start);

      var grid = h('div', { className: 'lnkup-portfolio__grid', 'data-testid': 'lnkup-portfolio-tours' });
      var brandOpts = [['', 'All brands']].concat((overview.brands || []).map(function (b) { return [b.id, b.name]; }));
      var bar = h('div', { className: 'lnkup-portfolio__filters', role: 'group', 'aria-label': 'Filter tours' }, [
        select('type', [['', 'All types'], ['tour', 'Tours'], ['recurring', 'Recurring markets'], ['series', 'Series']], '', { 'aria-label': 'Type', onchange: function (e) { filter.type = e.target.value; draw(); } }),
        brandOpts.length > 1 ? select('brand', brandOpts, '', { 'aria-label': 'Brand', onchange: function (e) { filter.brand = e.target.value; draw(); } }) : null,
        h('label', { className: 'lnkup-portfolio__toggle' }, [
          h('input', { type: 'checkbox', onchange: function (e) { filter.archived = e.target.checked; draw(); } }),
          ' Show archived'
        ])
      ]);
      mount.appendChild(h('section', { className: 'lnkup-portfolio__section', 'aria-labelledby': 'pf-tours-title' }, [
        h('h2', { id: 'pf-tours-title', text: 'Your tours & series' }), bar, grid
      ]));

      function draw() {
        grid.innerHTML = '';
        var items = (overview.tours || []).filter(function (t) {
          return (!filter.type || t.type === filter.type)
            && (!filter.brand || (t.brand && String(t.brand.id) === String(filter.brand)))
            && (filter.archived ? t.status === 'archived' : t.status !== 'archived');
        });
        if (!items.length) {
          grid.appendChild(h('p', { className: 'lnkup-empty-state', text: (overview.tours || []).length ? 'No tours match these filters.' : 'No tours or series yet. Pick a format above to set up your first one in a few minutes.' }));
          return;
        }
        items.forEach(function (t) {
          grid.appendChild(tourCard(t));
        });
      }
      draw();

      mount.appendChild(templatesSection());
      mount.appendChild(brandsSection());
    });
  }

  function choice(type, title, text) {
    return h('button', { type: 'button', className: 'lnkup-portfolio__choice', 'data-testid': 'lnkup-portfolio-new-' + type, onclick: function () { go({ new: type }); } }, [
      h('strong', { text: title }),
      h('span', { className: 'lnkup-muted', text: text })
    ]);
  }

  function tourCard(t) {
    var counts = t.counts || {};
    return h('article', { className: 'lnkup-portfolio__card', 'data-tour-id': t.id }, [
      t.cover_url ? h('img', { src: t.cover_url, alt: '', className: 'lnkup-portfolio__cover', loading: 'lazy' }) : h('div', { className: 'lnkup-portfolio__cover lnkup-portfolio__cover--blank', 'aria-hidden': 'true' }),
      h('div', { className: 'lnkup-portfolio__card-body' }, [
        h('p', { className: 'lnkup-portfolio__eyebrow', text: (TYPE_LABELS[t.type] || 'Tour') + (t.season ? ' · ' + t.season : '') + (t.brand ? ' · ' + t.brand.name : '') }),
        h('h3', {}, h('a', { href: cfg.viewUrl + '&tour=' + t.id, onclick: function (e) { e.preventDefault(); go({ tour: t.id }); }, text: t.title })),
        h('p', { className: 'lnkup-muted', text: t.stop_count + (t.stop_count === 1 ? ' date' : ' dates') + ' · ' + (counts.published || 0) + ' live · ' + (counts.draft || 0) + ' draft' + (t.next_date ? ' · next ' + dateLabel(t.next_date) : '') }),
        h('div', { className: 'lnkup-portfolio__badges' }, [
          badge(t.status === 'active' ? 'Live' : t.status === 'archived' ? 'Archived' : 'Draft', t.status === 'active' ? 'success' : 'muted')
        ])
      ])
    ]);
  }

  function templatesSection() {
    var list = h('ul', { className: 'lnkup-portfolio__rows' });
    var section = h('section', { className: 'lnkup-portfolio__section', 'aria-labelledby': 'pf-templates-title' }, [
      h('h2', { id: 'pf-templates-title', text: 'Templates' }),
      h('p', { className: 'lnkup-muted', text: 'Saved booth types, prices, questions and document rules. Save one from any tour\'s Settings tab. Editing a template never changes events already built from it.' }),
      list
    ]);
    var items = (overview && overview.templates) || [];
    if (!items.length) {
      list.appendChild(h('li', { className: 'lnkup-empty-state', text: 'No templates yet.' }));
    }
    items.forEach(function (tpl) {
      list.appendChild(h('li', {}, [
        h('div', {}, [
          h('strong', { text: tpl.name }),
          h('span', { className: 'lnkup-muted', text: ' · v' + tpl.version + ' · ' + tpl.summary.booth_types + ' booth types · ' + tpl.summary.questions + ' questions' })
        ]),
        h('div', { className: 'lnkup-portfolio__actions' }, [
          h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Rename', onclick: function () {
            var name = window.prompt('New template name', tpl.name);
            if (name) {
              api('PATCH', '/portfolio/templates/' + tpl.id, { name: name }).then(function (r) { r.ok ? renderList() : flash(section, r.message, 'error'); });
            }
          } }),
          h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Duplicate', onclick: function () {
            api('POST', '/portfolio/templates/' + tpl.id + '/duplicate', {}).then(function (r) { r.ok ? renderList() : flash(section, r.message, 'error'); });
          } }),
          h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Delete', onclick: function () {
            if (window.confirm('Delete "' + tpl.name + '"? Tours already built from it are not affected.')) {
              api('DELETE', '/portfolio/templates/' + tpl.id).then(function (r) { r.ok ? renderList() : flash(section, r.message, 'error'); });
            }
          } })
        ])
      ]));
    });
    return section;
  }

  function brandsSection() {
    var form = h('form', { className: 'lnkup-portfolio__inline-form' }, [
      field('New brand name', input('name', '', { maxlength: '160', required: true })),
      h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Add brand' })
    ]);
    var section = h('section', { className: 'lnkup-portfolio__section', 'aria-labelledby': 'pf-brands-title' }, [
      h('h2', { id: 'pf-brands-title', text: 'Event brands' }),
      h('p', { className: 'lnkup-muted', text: 'Optional. Group tours under a recognizable event brand, for example one festival that tours every year.' }),
      h('ul', { className: 'lnkup-portfolio__chips' }, ((overview && overview.brands) || []).map(function (b) { return h('li', { text: b.name }); })),
      form
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      api('POST', '/portfolio/brands', formData(form)).then(function (r) { r.ok ? renderList() : flash(section, r.message, 'error'); });
    });
    return section;
  }

  // ------------------------------------------------------------------ create (wizard step 1)

  function steps(current) {
    var labels = [['identity', 'Identity'], ['stops', 'Dates'], ['setup', 'Booths & questions'], ['pricing', 'Pricing'], ['review', 'Review & publish']];
    return h('ol', { className: 'lnkup-portfolio__steps', 'aria-label': 'Setup progress' }, labels.map(function (s, i) {
      return h('li', { className: s[0] === current ? 'is-current' : '', 'aria-current': s[0] === current ? 'step' : null }, [h('span', { text: String(i + 1) }), ' ' + s[1]]);
    }));
  }

  function renderCreate(type) {
    if (!TYPE_LABELS[type]) {
      go({});
      return;
    }
    var token = ++renderToken;
    setBusy(mount);
    loadOverview().then(function (res) {
      if (token !== renderToken) {
        return;
      }
      if (!res.ok) {
        errorCard(mount, res.message, function () { renderCreate(type); });
        return;
      }
      mount.innerHTML = '';
      var brands = [['', 'No brand']].concat((overview.brands || []).map(function (b) { return [b.id, b.name]; }));
      var templates = [['', 'Start blank']].concat((overview.templates || []).map(function (t) { return [t.id, t.name + ' (v' + t.version + ')']; }));
      var form = h('form', { className: 'lnkup-form lnkup-portfolio__panel', 'data-testid': 'lnkup-portfolio-create' }, [
        h('h2', { text: 'New ' + TYPE_LABELS[type].toLowerCase() }),
        field('Name', input('title', '', { required: true, maxlength: '200', placeholder: type === 'tour' ? 'Dirty South Rodeo Festival Tour 2027' : 'First Saturday Vintage Market' })),
        h('div', { className: 'lnkup-grid lnkup-grid--2' }, [
          field('Brand', select('brand_id', brands, '')),
          field('Year or season', input('season', '', { maxlength: '60', placeholder: '2027' }))
        ]),
        h('div', { className: 'lnkup-grid lnkup-grid--2' }, [
          field('Event category', input('category', '', { maxlength: '120', placeholder: 'Festival' })),
          field('Website (optional)', input('website', '', { type: 'url', placeholder: 'https://' }))
        ]),
        field('Public description', h('textarea', { name: 'description', rows: '4', maxlength: '5000' })),
        field('Who can apply', h('textarea', { name: 'eligibility', rows: '2', maxlength: '2000' }), 'Shown to vendors on the tour page.'),
        templates.length > 1 ? field('Start from a template', select('template_id', templates, '')) : null,
        h('div', { className: 'lnkup-portfolio__actions' }, [
          h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: function () { go({}); } }),
          h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary', text: 'Save and add dates' })
        ])
      ]);
      mount.appendChild(steps('identity'));
      mount.appendChild(form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type=submit]');
        btn.disabled = true;
        var body = formData(form);
        body.type = type;
        api('POST', '/portfolio/tours', body).then(function (r) {
          btn.disabled = false;
          if (!r.ok) {
            flash(form, r.message, 'error');
            return;
          }
          overview = null;
          go({ tour: r.data.id, tab: 'stops' });
        });
      });
    });
  }

  // ------------------------------------------------------------------ tour dashboard

  var TABS = [['overview', 'Overview'], ['stops', 'Dates & stops'], ['setup', 'Booths & questions'], ['pricing', 'Pricing'], ['applications', 'Applications'], ['vendors', 'Vendors'], ['settings', 'Settings']];

  function renderTour(id, tab) {
    var token = ++renderToken;
    setBusy(mount);
    Promise.all([loadOverview(), api('GET', '/portfolio/tours/' + id)]).then(function (all) {
      if (token !== renderToken) {
        return;
      }
      var res = all[1];
      if (!res.ok) {
        errorCard(mount, res.status === 404 ? 'This tour was not found.' : res.message, function () { renderTour(id, tab); });
        return;
      }
      var tour = res.data;
      mount.innerHTML = '';
      var publicLink = tour.public_url ? h('a', { href: tour.public_url, className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', target: '_blank', rel: 'noopener', text: 'View public page' }) : null;
      mount.appendChild(h('header', { className: 'lnkup-portfolio__head' }, [
        h('a', { href: cfg.viewUrl, className: 'lnkup-portfolio__back', onclick: function (e) { e.preventDefault(); go({}); }, text: '← All tours & series' }),
        h('div', { className: 'lnkup-portfolio__title' }, [
          h('h2', { text: tour.title }),
          badge(TYPE_LABELS[tour.type] || 'Tour'),
          badge(tour.status === 'active' ? 'Live' : tour.status === 'archived' ? 'Archived' : 'Draft', tour.status === 'active' ? 'success' : 'muted')
        ]),
        h('div', { className: 'lnkup-portfolio__actions' }, [publicLink])
      ]));
      var nav = h('nav', { className: 'lnkup-portfolio__tabs', 'aria-label': 'Tour sections' }, TABS.map(function (t) {
        var label = t[0] === 'stops' && tour.type === 'recurring' ? 'Dates' : t[1];
        return h('a', {
          href: cfg.viewUrl + '&tour=' + id + '&tab=' + t[0],
          className: t[0] === tab ? 'is-active' : '',
          'aria-current': t[0] === tab ? 'page' : null,
          onclick: function (e) { e.preventDefault(); go({ tour: id, tab: t[0] }); },
          text: label
        });
      }));
      mount.appendChild(nav);
      var panel = h('section', { className: 'lnkup-portfolio__panel', 'data-testid': 'lnkup-portfolio-tab-' + tab });
      mount.appendChild(nextStepHint(tour, tab));
      mount.appendChild(panel);
      var reload = function () { route(); };
      var renderers = { overview: tabOverview, stops: tabStops, setup: tabSetup, pricing: tabPricing, applications: tabApplications, vendors: tabVendors, settings: tabSettings };
      (renderers[tab] || tabOverview)(panel, tour, reload);
      if (pendingFlash) {
        flash(panel, pendingFlash);
        pendingFlash = null;
      }
    });
  }

  function nextStepHint(tour, tab) {
    var text = '';
    var target = '';
    if (!tour.stops.length) {
      text = tour.type === 'recurring' ? 'Next: generate your dates.' : 'Next: add your stops.';
      target = 'stops';
    } else if (!tour.defaults.booth_types.length) {
      text = 'Next: set the booth types and prices every stop offers.';
      target = 'setup';
    } else if (tour.counts && !tour.counts.published) {
      text = 'Next: review readiness and publish the stops that are ready.';
      target = 'stops';
    }
    if (!text || target === tab) {
      return document.createComment('');
    }
    return h('p', { className: 'lnkup-portfolio__hint' }, [text + ' ', h('a', { href: '#', onclick: function (e) { e.preventDefault(); go({ tour: tour.id, tab: target }); }, text: 'Go' })]);
  }

  // ---------- overview

  function tabOverview(panel, tour) {
    setBusy(panel);
    api('GET', '/portfolio/tours/' + tour.id + '/summary').then(function (res) {
      if (!res.ok) {
        errorCard(panel, res.message, function () { tabOverview(panel, tour); });
        return;
      }
      var s = res.data;
      panel.innerHTML = '';
      panel.appendChild(h('div', { className: 'lnkup-portfolio__kpis' }, [
        kpi('Live stops', (s.tour.counts.published || 0) + ' / ' + s.tour.stop_count),
        kpi('Applications', s.applications.total + (s.applications.pending ? ' (' + s.applications.pending + ' to review)' : '')),
        kpi('Verified paid bookings', s.money.paid_bookings),
        kpi('Booths open', s.inventory.spots_open + ' of ' + s.inventory.spots_total),
        kpi('Collected', money(s.money.collected)),
        kpi('Platform fees', money(s.money.platform_fees)),
        kpi('Awaiting payment', money(s.money.pending)),
        kpi('Refunds', money(s.money.refunds))
      ]));
      panel.appendChild(h('p', { className: 'lnkup-muted lnkup-portfolio__note', text: s.money.reconciled
        ? 'Totals are added up from each stop\'s own payments, counting every payment once.'
        : 'Tour totals and stop totals differ. Contact support before relying on these numbers.' }));
      if (s.tasks.length) {
        panel.appendChild(h('ul', { className: 'lnkup-portfolio__tasks', 'aria-label': 'Outstanding tasks' }, s.tasks.map(function (t) { return h('li', { text: t.message }); })));
      }
      if (!s.stops.length) {
        panel.appendChild(h('p', { className: 'lnkup-empty-state', text: 'No stops yet. Add dates from the Dates & stops tab.' }));
        return;
      }
      panel.appendChild(h('div', { className: 'lnkup-portfolio__stop-cards' }, s.stops.map(function (st) {
        return h('article', { className: 'lnkup-portfolio__stop-card', 'data-testid': 'lnkup-portfolio-stop-card' }, [
          h('header', {}, [h('strong', { text: st.city || st.title }), stateBadge(st.state)]),
          h('p', { className: 'lnkup-muted', text: dateLabel(st.event_date) + (st.venue_name ? ' · ' + st.venue_name : '') }),
          h('dl', {}, [
            h('dt', { text: 'Applications' }), h('dd', { text: String(st.applications.total) }),
            h('dt', { text: 'Confirmed' }), h('dd', { text: String(st.applications.paid) }),
            h('dt', { text: 'Booths open' }), h('dd', { text: st.spots_open + ' / ' + st.spots_total }),
            h('dt', { text: 'Collected' }), h('dd', { text: money(st.money.collected) }),
            st.next_deadline ? h('dt', { text: 'Apply by' }) : null, st.next_deadline ? h('dd', { text: dateLabel(st.next_deadline) }) : null
          ]),
          st.state !== 'draft' ? h('a', { href: st.manage_url, className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Manage this stop' }) : null
        ]);
      })));
    });
  }

  function kpi(label, value) {
    return h('div', { className: 'lnkup-portfolio__kpi' }, [h('span', { text: label }), h('strong', { text: String(value) })]);
  }

  // ---------- stops

  function tabStops(panel, tour, reload) {
    panel.innerHTML = '';
    panel.appendChild(scope('Changes in this tab apply to one stop at a time unless you select several stops to publish or cancel.'));
    if (tour.type === 'recurring') {
      panel.appendChild(recurrenceBuilder(tour, reload));
    }
    panel.appendChild(stopsTable(tour, reload));
    panel.appendChild(addStopsForm(tour, reload));
  }

  function stopsTable(tour, reload) {
    var wrap = h('div', { className: 'lnkup-portfolio__block' });
    wrap.appendChild(h('h3', { text: tour.type === 'recurring' ? 'Dates' : 'Stops' }));
    if (!tour.stops.length) {
      wrap.appendChild(h('p', { className: 'lnkup-empty-state', text: tour.type === 'recurring' ? 'No dates yet. Use the schedule above to generate them.' : 'No stops yet. Add them below. Stops without a confirmed venue stay drafts.' }));
      return wrap;
    }
    var results = h('div', { 'aria-live': 'polite' });
    var table = h('table', { className: 'lnkup-portfolio__table', 'data-testid': 'lnkup-portfolio-stops' });
    table.appendChild(h('thead', {}, h('tr', {}, [
      h('th', { scope: 'col' }, h('span', { className: 'screen-reader-text', text: 'Select' })),
      h('th', { scope: 'col', text: 'Date' }), h('th', { scope: 'col', text: 'City' }), h('th', { scope: 'col', text: 'Venue' }),
      h('th', { scope: 'col', text: 'Status' }), h('th', { scope: 'col', text: 'Ready?' }), h('th', { scope: 'col', text: 'Actions' })
    ])));
    var body = h('tbody');
    tour.stops.forEach(function (st, index) {
      var row = h('tr', { 'data-event-id': st.event_id });
      var missing = st.readiness.missing.map(function (m) { return m.message; });
      row.appendChild(h('td', {}, h('input', { type: 'checkbox', value: st.event_id, 'aria-label': 'Select ' + (st.city || st.title), 'data-stop-select': '1' })));
      row.appendChild(h('td', { text: dateLabel(st.event_date) + (st.start_time ? ' ' + st.start_time : '') }));
      row.appendChild(h('td', { text: [st.city, st.state_code].filter(Boolean).join(', ') || '—' }));
      row.appendChild(h('td', { text: st.venue_name || 'Venue to be confirmed' }));
      row.appendChild(h('td', {}, stateBadge(st.state)));
      row.appendChild(h('td', {}, st.state === 'draft'
        ? (st.readiness.ready ? badge('Ready', 'success') : h('details', {}, [h('summary', { text: missing.length + ' to do' }), h('ul', {}, missing.map(function (m) { return h('li', { text: m }); }))]))
        : '—'));
      var actions = h('div', { className: 'lnkup-portfolio__actions' });
      actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Move up', disabled: index === 0, text: '↑', onclick: function () { reorder(index, -1); } }));
      actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Move down', disabled: index === tour.stops.length - 1, text: '↓', onclick: function () { reorder(index, 1); } }));
      if (['draft', 'published', 'skipped'].indexOf(st.state) !== -1) {
        actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Edit', onclick: function () { editStop(row, st); } }));
      }
      actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Duplicate', onclick: function () {
        api('POST', '/portfolio/tours/' + tour.id + '/stops/' + st.event_id + '/duplicate', { client_token: requestKey() }).then(done('Stop duplicated as a draft.'));
      } }));
      if (tour.type === 'recurring' && (st.state === 'draft' || st.state === 'published')) {
        actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Skip', onclick: function () {
          if (window.confirm('Skip ' + dateLabel(st.event_date) + '? Only this date changes.')) {
            api('POST', '/portfolio/tours/' + tour.id + '/occurrences/' + st.event_id + '/skip', {}).then(done('Date skipped.'));
          }
        } }));
      }
      if (st.state === 'skipped') {
        actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Restore', onclick: function () {
          api('POST', '/portfolio/tours/' + tour.id + '/occurrences/' + st.event_id + '/skip', { undo: true }).then(done('Date restored as a draft.'));
        } }));
      }
      if (st.state === 'draft') {
        actions.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Remove', onclick: function () {
          if (window.confirm('Remove this draft stop?')) {
            api('DELETE', '/portfolio/tours/' + tour.id + '/stops/' + st.event_id).then(done('Draft stop removed.'));
          }
        } }));
      }
      if (st.state === 'published') {
        actions.appendChild(h('a', { href: st.manage_url, className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Manage' }));
      }
      row.appendChild(h('td', {}, actions));
      body.appendChild(row);
    });
    table.appendChild(body);

    function selected() {
      return Array.prototype.map.call(table.querySelectorAll('[data-stop-select]:checked'), function (c) { return parseInt(c.value, 10); });
    }
    function done(message) {
      return function (r) {
        if (!r.ok) {
          flash(wrap, r.message, 'error');
          return;
        }
        pendingFlash = message;
        reload();
      };
    }
    function reorder(index, delta) {
      var ids = tour.stops.map(function (s) { return s.event_id; });
      var moved = ids.splice(index, 1)[0];
      ids.splice(index + delta, 0, moved);
      api('POST', '/portfolio/tours/' + tour.id + '/stops/reorder', { event_ids: ids }).then(done('Order saved.'));
    }
    function editStop(row, st) {
      var form = h('form', { className: 'lnkup-portfolio__edit' }, [
        h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
          field('Date', input('event_date', st.event_date, { type: 'date' })),
          field('Start', input('start_time', st.start_time, { type: 'time' })),
          field('End', input('end_time', st.end_time, { type: 'time' })),
          field('City', input('city', st.city)),
          field('State', input('state', st.state_code, { maxlength: '60' })),
          field('Time zone', timezoneSelect('timezone', st.timezone)),
          field('Venue', input('venue_name', st.venue_name)),
          field('Address', input('venue_address', st.venue_address)),
          field('Expected attendance', input('expected_attendance', st.expected_attendance || '', { type: 'number', min: '0' }))
        ]),
        st.state === 'published' ? h('p', { className: 'lnkup-muted', text: 'This stop is live. Vendors who are booked will be told about date, time or venue changes. Prices are not changed here.' }) : null,
        h('div', { className: 'lnkup-portfolio__actions' }, [
          h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Cancel', onclick: function () { cell.parentNode.removeChild(cell); } }),
          h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Save stop' })
        ])
      ]);
      var cell = h('tr', { className: 'lnkup-portfolio__edit-row' }, h('td', { colspan: '7' }, form));
      row.parentNode.insertBefore(cell, row.nextSibling);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        api('PATCH', '/portfolio/tours/' + tour.id + '/stops/' + st.event_id, formData(form)).then(function (r) {
          if (!r.ok) {
            flash(form, r.message, 'error');
            return;
          }
          reload();
        });
      });
    }

    var toolbar = h('div', { className: 'lnkup-portfolio__toolbar' }, [
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', 'data-testid': 'lnkup-portfolio-publish-selected', text: 'Publish selected', onclick: function () {
        var ids = selected();
        if (!ids.length) {
          flash(wrap, 'Select the stops to publish.', 'error');
          return;
        }
        publish({ event_ids: ids });
      } }),
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', 'data-testid': 'lnkup-portfolio-publish-ready', text: 'Publish all ready stops', onclick: function () { publish({ all: true }); } }),
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Cancel selected', onclick: function () {
        var ids = selected();
        if (!ids.length) {
          flash(wrap, 'Select the stops to cancel.', 'error');
          return;
        }
        var note = window.prompt('Cancel ' + ids.length + ' stop(s)? Booked vendors will be notified. Add a short note for them:', '');
        if (note === null) {
          return;
        }
        api('POST', '/portfolio/tours/' + tour.id + '/cancel', { event_ids: ids, note: note }).then(done('Stops cancelled. Refunds, if any, are handled from Money.'));
      } })
    ]);
    function publish(body) {
      results.innerHTML = '';
      results.appendChild(h('p', { className: 'lnkup-muted', role: 'status', text: 'Publishing… this can take a moment for many stops.' }));
      Array.prototype.forEach.call(toolbar.querySelectorAll('button'), function (b) { b.disabled = true; });
      api('POST', '/portfolio/tours/' + tour.id + '/publish', body).then(function (r) {
        Array.prototype.forEach.call(toolbar.querySelectorAll('button'), function (b) { b.disabled = false; });
        if (!r.ok) {
          results.innerHTML = '';
          flash(wrap, r.message, 'error');
          return;
        }
        lastPublish = { tourId: tour.id, data: r.data };
        reload();
      });
    }
    function publishSummary(data) {
      return h('div', { className: 'lnkup-alert lnkup-alert--' + (data.not_ready || data.failed ? 'warning' : 'success'), role: 'status', 'data-testid': 'lnkup-portfolio-publish-result' }, [
        h('p', { text: data.published + ' published · ' + data.not_ready + ' not ready · ' + data.failed + ' failed' }),
        h('ul', {}, data.results.filter(function (x) { return x.result !== 'published' && x.result !== 'already_published'; }).map(function (x) {
          return h('li', { text: (x.city || x.title || ('Stop ' + x.event_id)) + ': ' + (x.missing ? x.missing.map(function (m) { return m.message; }).join(' ') : x.message || x.result) });
        }))
      ]);
    }
    if (lastPublish && lastPublish.tourId === tour.id) {
      results.appendChild(publishSummary(lastPublish.data));
      lastPublish = null;
    }

    wrap.appendChild(toolbar);
    wrap.appendChild(results);
    wrap.appendChild(h('div', { className: 'lnkup-portfolio__scroll' }, table));
    return wrap;
  }

  function addStopsForm(tour, reload) {
    var key = requestKey();
    var tz = (overview && overview.site_timezone) || '';
    var tbody = h('tbody');
    var form = h('form', { className: 'lnkup-portfolio__block', 'data-testid': 'lnkup-portfolio-add-stops' });
    function addRow(values) {
      var v = values || {};
      tbody.appendChild(h('tr', {}, [
        h('td', {}, input('city', v.city, { 'aria-label': 'City' })),
        h('td', {}, input('state', v.state, { 'aria-label': 'State', maxlength: '60', size: '4' })),
        h('td', {}, input('event_date', v.event_date, { type: 'date', 'aria-label': 'Date' })),
        h('td', {}, input('venue_name', v.venue_name, { 'aria-label': 'Venue' })),
        h('td', {}, input('venue_address', v.venue_address, { 'aria-label': 'Address' })),
        h('td', {}, input('start_time', v.start_time, { type: 'time', 'aria-label': 'Start time' })),
        h('td', {}, input('end_time', v.end_time, { type: 'time', 'aria-label': 'End time' })),
        h('td', {}, timezoneSelect('timezone', v.timezone || tz, { 'aria-label': 'Time zone' })),
        h('td', {}, h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Remove row', text: '×', onclick: function (e) { var tr = e.target.closest('tr'); tr.parentNode.removeChild(tr); } }))
      ]));
    }
    var paste = h('textarea', { rows: '4', placeholder: 'Houston, TX, 2027-03-13, BH Ranch, 123 Ranch Rd\nAtlanta, GA, 2027-05-29, Smith Family Ranch' });
    form.appendChild(h('h3', { text: tour.type === 'recurring' ? 'Add individual dates' : 'Add stops' }));
    form.appendChild(h('p', { className: 'lnkup-muted', text: 'Leave the venue blank if it is not confirmed yet. The stop is saved as a draft and cannot be published until the venue is added.' }));
    form.appendChild(h('div', { className: 'lnkup-portfolio__scroll' }, h('table', { className: 'lnkup-portfolio__table lnkup-portfolio__table--edit' }, [
      h('thead', {}, h('tr', {}, ['City', 'State', 'Date', 'Venue', 'Address', 'Start', 'End', 'Time zone', ''].map(function (c) { return h('th', { scope: 'col', text: c }); }))),
      tbody
    ])));
    form.appendChild(h('div', { className: 'lnkup-portfolio__toolbar' }, [
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: '+ Add row', onclick: function () { addRow(); } })
    ]));
    form.appendChild(h('details', { className: 'lnkup-portfolio__paste' }, [
      h('summary', { text: 'Paste from a spreadsheet' }),
      field('One stop per line: City, State, Date (YYYY-MM-DD), Venue, Address', paste),
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Add pasted rows', onclick: function () {
        paste.value.split(/\r?\n/).forEach(function (line) {
          var cols = line.split(line.indexOf('\t') !== -1 ? '\t' : ',').map(function (c) { return c.trim(); });
          if (cols[0]) {
            addRow({ city: cols[0], state: cols[1], event_date: cols[2], venue_name: cols[3], venue_address: cols[4] });
          }
        });
        paste.value = '';
      } })
    ]));
    var status = h('div', { 'aria-live': 'polite' });
    form.appendChild(status);
    form.appendChild(h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary', text: 'Save stops as drafts' }));
    addRow();
    addRow();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var rows = Array.prototype.map.call(tbody.querySelectorAll('tr'), function (tr) {
        var row = {};
        Array.prototype.forEach.call(tr.querySelectorAll('input,select'), function (el) { row[el.name] = el.value; });
        return row;
      }).filter(function (r) { return r.city || r.event_date || r.venue_name; });
      if (!rows.length) {
        flash(form, 'Add at least one stop.', 'error');
        return;
      }
      var btn = form.querySelector('[type=submit]');
      btn.disabled = true;
      status.textContent = 'Saving ' + rows.length + ' stop(s)…';
      api('POST', '/portfolio/tours/' + tour.id + '/stops', { stops: rows }, { 'Idempotency-Key': key }).then(function (r) {
        btn.disabled = false;
        handleStopsResult(r, status, reload, function () { key = requestKey(); });
      });
    });
    return form;
  }

  function handleStopsResult(r, status, reload, onDone) {
    if (!r.ok) {
      status.textContent = '';
      flash(status.parentNode, r.message, 'error');
      return;
    }
    if (r.data.mode === 'job') {
      pollJob(r.data.job, status, reload, onDone);
      return;
    }
    var res = r.data.result;
    status.textContent = res.created + ' created · ' + res.existing + ' already saved · ' + res.failed + ' failed';
    (res.warnings || []).forEach(function (w) { status.appendChild(h('p', { className: 'lnkup-muted', text: w.message })); });
    onDone();
    window.setTimeout(reload, res.failed ? 4000 : 900);
  }

  function pollJob(job, status, reload, onDone) {
    status.textContent = 'Creating dates: ' + job.done + ' of ' + job.total + '…';
    if (job.status !== 'running') {
      status.textContent = 'Created ' + (job.done - job.failed) + ' of ' + job.total + (job.failed ? ' (' + job.failed + ' failed — retry to finish)' : '') + '.';
      onDone();
      window.setTimeout(reload, 1200);
      return;
    }
    window.setTimeout(function () {
      api('GET', '/portfolio/jobs/' + job.id).then(function (r) {
        if (!r.ok) {
          status.textContent = 'Still working in the background. Refresh in a minute to see every date.';
          return;
        }
        pollJob(r.data, status, reload, onDone);
      });
    }, 2500);
  }

  function recurrenceBuilder(tour, reload) {
    var rule = tour.recurrence_rule || {};
    var key = requestKey();
    var weekdays = h('fieldset', { className: 'lnkup-portfolio__weekdays' }, [h('legend', { text: 'On' })].concat(WEEKDAYS.map(function (d, i) {
      return h('label', {}, [h('input', { type: 'checkbox', name: 'wd' + i, checked: (rule.weekdays || []).indexOf(i) !== -1 }), ' ' + d]);
    })));
    var nth = select('nth', [['1', 'First'], ['2', 'Second'], ['3', 'Third'], ['4', 'Fourth'], ['-1', 'Last']], rule.nth || '1');
    var weekday = select('weekday', WEEKDAYS.map(function (d, i) { return [String(i), d]; }), rule.weekday >= 0 ? rule.weekday : '6');
    var freq = select('frequency', [['weekly', 'Every week'], ['biweekly', 'Every other week'], ['monthly_weekday', 'Monthly (e.g. first Saturday)'], ['monthly_date', 'Monthly on the same date'], ['custom', 'Pick exact dates']], rule.frequency || 'monthly_weekday');
    var monthlyBox = h('div', { className: 'lnkup-grid lnkup-grid--2' }, [field('Which', nth), field('Day', weekday)]);
    var customBox = field('Dates (YYYY-MM-DD, comma separated)', input('custom_dates', (rule.custom_dates || []).join(', ')));
    var stop = rule.stop || {};
    var form = h('form', { className: 'lnkup-portfolio__block lnkup-form', 'data-testid': 'lnkup-portfolio-recurrence' }, [
      h('h3', { text: 'Schedule' }),
      h('p', { className: 'lnkup-muted', text: 'Dates are generated as drafts in the market\'s local time zone, up to 52 at a time. You can skip, move or cancel any single date later.' }),
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('Repeats', freq),
        field('First date', input('start_date', rule.start_date, { type: 'date' })),
        field('Time zone', timezoneSelect('timezone', rule.timezone))
      ]),
      weekdays, monthlyBox, customBox,
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('Number of dates', input('count', rule.count || '6', { type: 'number', min: '1', max: '52' })),
        field('Or end on', input('end_date', rule.end_date, { type: 'date' })),
        field('Skip these dates', input('exclude_dates', (rule.exclude_dates || []).join(', '), { placeholder: '2027-07-04' }))
      ]),
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('Start time', input('start_time', rule.start_time, { type: 'time' })),
        field('End time', input('end_time', rule.end_time, { type: 'time' })),
        field('City', input('city', stop.city))
      ]),
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('State', input('state', stop.state)),
        field('Venue', input('venue_name', stop.venue_name)),
        field('Address', input('venue_address', stop.venue_address))
      ]),
      tour.stops.length ? h('label', { className: 'lnkup-portfolio__toggle' }, [h('input', { type: 'checkbox', name: 'replace' }), ' Replace the remaining schedule (only untouched future drafts are removed; live or booked dates are kept)']) : null,
      h('div', { className: 'lnkup-portfolio__toolbar' }, [
        h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Preview dates', onclick: function () { preview(); } }),
        h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Create dates' })
      ])
    ]);
    var out = h('div', { 'aria-live': 'polite' });
    form.appendChild(out);
    function sync() {
      var f = freq.value;
      weekdays.hidden = f !== 'weekly' && f !== 'biweekly';
      monthlyBox.hidden = f !== 'monthly_weekday';
      customBox.hidden = f !== 'custom';
    }
    freq.addEventListener('change', sync);
    sync();
    function collect() {
      var d = formData(form);
      var days = [];
      for (var i = 0; i < 7; i++) {
        if (d['wd' + i]) {
          days.push(i);
        }
      }
      var list = function (s) { return String(s || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean); };
      return {
        frequency: d.frequency, start_date: d.start_date, end_date: d.end_date, count: d.end_date ? '' : d.count,
        timezone: d.timezone, start_time: d.start_time, end_time: d.end_time, weekdays: days,
        nth: d.nth, weekday: d.weekday, exclude_dates: list(d.exclude_dates), custom_dates: list(d.custom_dates),
        stop: { city: d.city, state: d.state, venue_name: d.venue_name, venue_address: d.venue_address }
      };
    }
    function preview() {
      out.innerHTML = '';
      api('POST', '/portfolio/tours/' + tour.id + '/recurrence/preview', { rule: collect() }).then(function (r) {
        if (!r.ok) {
          flash(out, r.message, 'error');
          return;
        }
        out.appendChild(h('p', { text: r.data.new_count + ' new date(s) will be created' + (r.data.capped ? ' (limit of 52 reached — generate more later)' : '') + '.' }));
        out.appendChild(h('ol', { className: 'lnkup-portfolio__preview' }, r.data.occurrences.map(function (o) {
          return h('li', { className: o.excluded || o.exists ? 'is-muted' : '' }, [
            dateLabel(o.date),
            o.excluded ? ' — skipped' : o.exists ? ' — already created' : ''
          ]);
        })));
      });
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = formData(form);
      var path = d.replace ? '/recurrence/change-remaining' : '/recurrence/generate';
      if (d.replace && !window.confirm('Replace the remaining schedule? Untouched future draft dates will be removed and new ones created.')) {
        return;
      }
      out.textContent = 'Creating dates…';
      api('POST', '/portfolio/tours/' + tour.id + path, { rule: collect() }, { 'Idempotency-Key': key }).then(function (r) {
        if (r.ok && d.replace) {
          r = { ok: true, data: r.data.generated };
        }
        handleStopsResult(r, out, reload, function () { key = requestKey(); });
      });
    });
    return form;
  }

  // ---------- setup (defaults)

  function tabSetup(panel, tour, reload) {
    var d = tour.defaults;
    panel.innerHTML = '';
    panel.appendChild(scope('Tour defaults: these apply to every draft stop. Live stops keep the booth listings they were published with, and stop-specific prices on the Pricing tab always win.'));
    var templates = (overview && overview.templates) || [];
    if (templates.length) {
      var tplSel = select('template', [['', 'Choose a template']].concat(templates.map(function (t) { return [t.id, t.name + ' (v' + t.version + ')']; })), '');
      panel.appendChild(h('div', { className: 'lnkup-portfolio__inline-form' }, [
        field('Load from template', tplSel),
        h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Apply template', onclick: function () {
          if (!tplSel.value || !window.confirm('Replace this tour\'s defaults with the template? Draft stop overrides are kept.')) {
            return;
          }
          api('POST', '/portfolio/templates/' + tplSel.value + '/apply', { tour_id: tour.id }).then(function (r) { r.ok ? reload() : flash(panel, r.message, 'error'); });
        } })
      ]));
    }
    var booths = h('tbody');
    function boothRow(b) {
      b = b || {};
      booths.appendChild(h('tr', {}, [
        h('td', {}, [input('label', b.label, { 'aria-label': 'Booth type', placeholder: 'Standard booth', required: true }), h('input', { type: 'hidden', name: 'key', value: b.key || '' })]),
        h('td', {}, input('category', b.category, { 'aria-label': 'Vendor category', placeholder: 'Food vendors' })),
        h('td', {}, input('price', b.price !== undefined ? b.price : '', { type: 'number', min: '0', step: '0.01', 'aria-label': 'Price' })),
        h('td', {}, input('capacity', b.capacity || '', { type: 'number', min: '1', 'aria-label': 'Booths per stop' })),
        h('td', {}, h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Remove booth type', text: '×', onclick: function (e) { var tr = e.target.closest('tr'); tr.parentNode.removeChild(tr); } }))
      ]));
    }
    (d.booth_types.length ? d.booth_types : [{}]).forEach(boothRow);

    var questions = h('div', { className: 'lnkup-portfolio__questions' });
    function questionRow(q) {
      q = q || {};
      var type = select('type', [['text', 'Short answer'], ['textarea', 'Paragraph'], ['select', 'Choice'], ['checkbox', 'Yes / no'], ['file', 'Document']], q.type || 'text');
      var opts = input('options', (q.options || []).join(', '), { placeholder: 'Choices, comma separated', 'aria-label': 'Choices' });
      var row = h('div', { className: 'lnkup-portfolio__question' }, [
        h('input', { type: 'hidden', name: 'id', value: q.id || '' }),
        input('label', q.label, { placeholder: 'Question', 'aria-label': 'Question' }),
        type, opts,
        h('label', {}, [h('input', { type: 'checkbox', name: 'required', checked: !!q.required }), ' Required']),
        h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Remove question', text: '×', onclick: function () { row.parentNode.removeChild(row); } })
      ]);
      var syncOpts = function () { opts.hidden = type.value !== 'select'; };
      type.addEventListener('change', syncOpts);
      syncOpts();
      questions.appendChild(row);
    }
    d.questions.forEach(questionRow);

    var docTypes = cfg.documents || {};
    var docsBox = h('fieldset', { className: 'lnkup-portfolio__docs' }, [h('legend', { text: 'Required documents' })].concat(Object.keys(docTypes).map(function (k) {
      return h('label', {}, [h('input', { type: 'checkbox', name: 'doc_' + k, checked: d.required_documents.indexOf(k) !== -1 }), ' ' + docTypes[k]]);
    })));
    var mode = select('document_mode', [['none', 'No documents required'], ['platform_defaults', 'LNKup standard documents'], ['custom', 'Choose documents']], d.document_mode);
    var syncDocs = function () { docsBox.hidden = mode.value !== 'custom'; };
    mode.addEventListener('change', syncDocs);
    syncDocs();

    var form = h('form', { className: 'lnkup-form', 'data-testid': 'lnkup-portfolio-defaults' }, [
      h('h3', { text: 'Booth types & default prices' }),
      h('div', { className: 'lnkup-portfolio__scroll' }, h('table', { className: 'lnkup-portfolio__table lnkup-portfolio__table--edit' }, [
        h('thead', {}, h('tr', {}, ['Booth type', 'Vendor category', 'Price', 'Booths per stop', ''].map(function (c) { return h('th', { scope: 'col', text: c }); }))),
        booths
      ])),
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: '+ Add booth type', onclick: function () { boothRow(); } }),
      h('h3', { text: 'Application questions' }),
      h('p', { className: 'lnkup-muted', text: 'Vendors answer these once per stop. Business name, contact details, photos and saved documents come from their profile automatically.' }),
      questions,
      h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: '+ Add question', onclick: function () { questionRow(); } }),
      h('h3', { text: 'Documents & deadline' }),
      h('div', { className: 'lnkup-grid lnkup-grid--2' }, [
        field('Documents', mode),
        field('Application deadline', input('deadline_days_before', d.deadline_days_before, { type: 'number', min: '0', max: '365' }), 'Days before each stop\'s event date.')
      ]),
      docsBox,
      field('What vendors should know', h('textarea', { name: 'vendor_description', rows: '3', maxlength: '4000', text: d.vendor_description })),
      h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary', text: 'Save defaults' })
    ]);
    panel.appendChild(form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = {
        booth_types: Array.prototype.map.call(booths.querySelectorAll('tr'), function (tr) {
          var o = {};
          Array.prototype.forEach.call(tr.querySelectorAll('input'), function (el) { o[el.name] = el.value; });
          return o;
        }),
        questions: Array.prototype.map.call(questions.querySelectorAll('.lnkup-portfolio__question'), function (row) {
          var o = {};
          Array.prototype.forEach.call(row.querySelectorAll('input,select'), function (el) { o[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
          o.options = String(o.options || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
          return o;
        }),
        document_mode: mode.value,
        required_documents: Object.keys(docTypes).filter(function (k) { var c = form.querySelector('[name="doc_' + k + '"]'); return c && c.checked; }),
        deadline_days_before: form.querySelector('[name=deadline_days_before]').value,
        vendor_description: form.querySelector('[name=vendor_description]').value
      };
      api('PUT', '/portfolio/tours/' + tour.id + '/defaults', body).then(function (r) {
        if (!r.ok) {
          flash(form, r.message, 'error');
          return;
        }
        pendingFlash = 'Defaults saved. Draft stops now use these booth types and questions.';
        reload();
      });
    });
  }

  // ---------- pricing

  function tabPricing(panel, tour, reload) {
    panel.innerHTML = '';
    var booths = tour.defaults.booth_types;
    if (!booths.length) {
      panel.appendChild(h('p', { className: 'lnkup-empty-state' }, ['Add booth types first on the ', h('a', { href: '#', onclick: function (e) { e.preventDefault(); go({ tour: tour.id, tab: 'setup' }); }, text: 'Booths & questions' }), ' tab.']));
      return;
    }
    panel.appendChild(bulkEditor(tour, reload));
    var drafts = tour.stops.filter(function (s) { return s.state === 'draft'; });
    var block = h('div', { className: 'lnkup-portfolio__block' }, [
      h('h3', { text: 'Stop-specific prices (draft stops)' }),
      scope('One stop: a value here overrides the tour default for that stop only. Leave blank to use the default.')
    ]);
    if (!drafts.length) {
      block.appendChild(h('p', { className: 'lnkup-muted', text: 'No draft stops. Change live stops with the bulk editor above so paid vendors keep their terms.' }));
    }
    drafts.forEach(function (st) {
      var form = h('form', { className: 'lnkup-portfolio__override', 'data-testid': 'lnkup-portfolio-override' });
      form.appendChild(h('h4', { text: (st.city || st.title) + ' · ' + dateLabel(st.event_date) }));
      st.config.booth_types.forEach(function (b) {
        var overridden = b.overridden || [];
        form.appendChild(h('div', { className: 'lnkup-portfolio__override-row' }, [
          h('strong', { text: b.label }),
          field('Price', input('price_' + b.key, overridden.indexOf('price') !== -1 ? b.price : '', { type: 'number', min: '0', step: '0.01', placeholder: String(defaultFor(b.key, 'price')) })),
          field('Booths', input('capacity_' + b.key, overridden.indexOf('capacity') !== -1 ? b.capacity : '', { type: 'number', min: '0', placeholder: String(defaultFor(b.key, 'capacity')) })),
          h('label', {}, [h('input', { type: 'checkbox', name: 'enabled_' + b.key, checked: b.enabled }), ' Offered'])
        ]));
      });
      form.appendChild(field('Deadline for this stop', input('deadline', st.config.deadline_overridden ? st.config.deadline : '', { type: 'date', placeholder: st.config.deadline })));
      form.appendChild(h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Save this stop' }));
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var d = formData(form);
        var overrides = { booth_types: {} };
        booths.forEach(function (b) {
          var o = {};
          if (d['price_' + b.key] !== '') { o.price = d['price_' + b.key]; }
          if (d['capacity_' + b.key] !== '') { o.capacity = d['capacity_' + b.key]; }
          if (!d['enabled_' + b.key]) { o.enabled = false; }
          overrides.booth_types[b.key] = o;
        });
        if (d.deadline) {
          overrides.deadline = d.deadline;
        }
        api('PATCH', '/portfolio/tours/' + tour.id + '/stops/' + st.event_id, { overrides: overrides }).then(function (r) {
          r.ok ? flash(form, 'Saved.') : flash(form, r.message, 'error');
        });
      });
      block.appendChild(form);
    });
    panel.appendChild(block);

    function defaultFor(key, prop) {
      var b = booths.filter(function (x) { return x.key === key; })[0];
      return b ? b[prop] : '';
    }
  }

  function bulkEditor(tour, reload) {
    var eligible = tour.stops.filter(function (s) { return ['draft', 'published'].indexOf(s.state) !== -1; });
    var form = h('form', { className: 'lnkup-portfolio__block lnkup-form', 'data-testid': 'lnkup-portfolio-bulk' }, [
      h('h3', { text: 'Bulk price & booth editor' }),
      scope('Selected stops: preview first. Live stops change only if you confirm, and vendors who already paid keep the price they paid.'),
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('Booth type', select('booth_type_key', tour.defaults.booth_types.map(function (b) { return [b.key, b.label]; }), '')),
        field('New price', input('price', '', { type: 'number', min: '0', step: '0.01' })),
        field('New booth count', input('capacity', '', { type: 'number', min: '1' }))
      ]),
      h('fieldset', { className: 'lnkup-portfolio__stop-picks' }, [h('legend', { text: 'Stops' })].concat(eligible.map(function (s) {
        return h('label', {}, [h('input', { type: 'checkbox', name: 'stop', value: s.event_id }), ' ' + (s.city || s.title) + ' · ' + dateLabel(s.event_date) + (s.state === 'published' ? ' (live)' : '')]);
      }))),
      h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Preview changes' })
    ]);
    var out = h('div', { 'aria-live': 'polite' });
    form.appendChild(out);
    function body() {
      var d = formData(form);
      return {
        booth_type_key: d.booth_type_key,
        price: d.price,
        capacity: d.capacity,
        event_ids: Array.prototype.map.call(form.querySelectorAll('[name=stop]:checked'), function (c) { return parseInt(c.value, 10); })
      };
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var b = body();
      out.innerHTML = '';
      api('POST', '/portfolio/tours/' + tour.id + '/bulk/preview', b).then(function (r) {
        if (!r.ok) {
          flash(out, r.message, 'error');
          return;
        }
        var p = r.data;
        out.appendChild(previewTable(p.rows));
        var confirmBox = p.counts.confirm ? h('label', { className: 'lnkup-portfolio__toggle' }, [h('input', { type: 'checkbox', name: 'confirm_published' }), ' Also update ' + p.counts.confirm + ' live stop(s)']) : null;
        if (confirmBox) {
          out.appendChild(confirmBox);
        }
        out.appendChild(h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', 'data-testid': 'lnkup-portfolio-bulk-apply', text: 'Apply changes', disabled: !(p.counts.change + p.counts.confirm), onclick: function () {
          b.confirm_published = !!(confirmBox && confirmBox.querySelector('input').checked);
          api('POST', '/portfolio/tours/' + tour.id + '/bulk/apply', b).then(function (ar) {
            if (!ar.ok) {
              flash(out, ar.message, 'error');
              return;
            }
            out.innerHTML = '';
            out.appendChild(h('p', { className: 'lnkup-alert lnkup-alert--success', role: 'status', text: ar.data.counts.applied + ' updated · ' + ar.data.counts.skipped + ' skipped · ' + ar.data.counts.failed + ' failed' }));
            out.appendChild(previewTable(ar.data.rows));
            window.setTimeout(reload, 3500);
          });
        } }));
      });
    });
    return form;
  }

  function previewTable(rows) {
    return h('div', { className: 'lnkup-portfolio__scroll' }, h('table', { className: 'lnkup-portfolio__table' }, [
      h('thead', {}, h('tr', {}, ['Stop', 'Current', 'New', 'Result', 'Note'].map(function (c) { return h('th', { scope: 'col', text: c }); }))),
      h('tbody', {}, rows.map(function (r) {
        return h('tr', { className: 'is-' + r.outcome }, [
          h('td', { text: (r.city || r.title) + ' · ' + dateLabel(r.date) }),
          h('td', { text: money(r.current.price) + ' · ' + (r.current.capacity || 0) + ' booths' }),
          h('td', { text: money(r.proposed.price) + ' · ' + (r.proposed.capacity || 0) + ' booths' }),
          h('td', { text: r.result ? r.result : (OUTCOME_LABELS[r.outcome] || r.outcome) }),
          h('td', { text: r.message || r.reason || '' })
        ]);
      }))
    ]));
  }

  // ---------- applications

  function tabApplications(panel, tour) {
    var state = { event_id: '', status: '', payment: '', page: 1 };
    panel.innerHTML = '';
    var stopOpts = [['', 'All stops']].concat(tour.stops.filter(function (s) { return s.state !== 'draft'; }).map(function (s) { return [s.event_id, (s.city || s.title) + ' · ' + dateLabel(s.event_date)]; }));
    var filters = h('div', { className: 'lnkup-portfolio__filters', role: 'group', 'aria-label': 'Filter applications' }, [
      select('event_id', stopOpts, '', { 'aria-label': 'Stop', onchange: function (e) { state.event_id = e.target.value; state.page = 1; load(); } }),
      select('status', [['', 'All statuses'], ['pending', 'Needs review'], ['approved_unpaid', 'Approved, unpaid'], ['paid', 'Paid'], ['waitlisted', 'Waitlisted'], ['rejected', 'Declined']], '', { 'aria-label': 'Status', onchange: function (e) { state.status = e.target.value; state.page = 1; load(); } }),
      select('payment', [['', 'Any payment'], ['paid', 'Paid'], ['unpaid', 'Unpaid'], ['none', 'No invoice yet']], '', { 'aria-label': 'Payment', onchange: function (e) { state.payment = e.target.value; state.page = 1; load(); } })
    ]);
    var list = h('div');
    panel.appendChild(scope('Each application belongs to one stop. Approving a vendor for one stop never approves them for another.'));
    panel.appendChild(filters);
    panel.appendChild(list);

    function load() {
      setBusy(list);
      api('GET', '/portfolio/tours/' + tour.id + '/applications', state).then(function (r) {
        if (!r.ok) {
          errorCard(list, r.message, load);
          return;
        }
        list.innerHTML = '';
        var items = r.data.items;
        if (!items.length) {
          list.appendChild(h('p', { className: 'lnkup-empty-state', text: 'Applications will appear here once vendors apply to your stops.' }));
          return;
        }
        var table = h('table', { className: 'lnkup-portfolio__table', 'data-testid': 'lnkup-portfolio-applications' }, [
          h('thead', {}, h('tr', {}, [
            h('th', { scope: 'col' }, h('input', { type: 'checkbox', 'aria-label': 'Select all', onchange: function (e) { Array.prototype.forEach.call(table.querySelectorAll('[data-app]'), function (c) { c.checked = e.target.checked; }); } })),
            h('th', { scope: 'col', text: 'Vendor' }), h('th', { scope: 'col', text: 'Stop' }), h('th', { scope: 'col', text: 'Booth' }),
            h('th', { scope: 'col', text: 'Status' }), h('th', { scope: 'col', text: 'Payment' }), h('th', { scope: 'col', text: 'Submitted' })
          ])),
          h('tbody', {}, items.map(function (a) {
            return h('tr', {}, [
              h('td', {}, h('input', { type: 'checkbox', 'data-app': a.application_id, 'aria-label': 'Select ' + (a.business_name || 'application') })),
              h('td', {}, [
                a.vendor_profile_id ? h('a', { href: cfg.dashboard + (cfg.dashboard.indexOf('?') === -1 ? '?' : '&') + 'vendor=' + a.vendor_profile_id, text: a.business_name || 'Vendor' }) : (a.business_name || 'Vendor'),
                a.group_id ? h('span', { className: 'lnkup-muted', text: ' · multi-stop' }) : null,
                a.documents_missing ? badge('Docs missing', 'warning') : null
              ]),
              h('td', { text: a.stop }), h('td', { text: a.opportunity }),
              h('td', {}, badge(APP_LABELS[a.status] || a.status)),
              h('td', { text: a.payment_status || '—' }),
              h('td', { text: a.submitted_at ? a.submitted_at.slice(0, 10) : '' })
            ]);
          }))
        ]);
        var out = h('div', { 'aria-live': 'polite' });
        var actions = h('div', { className: 'lnkup-portfolio__toolbar' }, [['approve', 'Approve'], ['waitlist', 'Waitlist'], ['reject', 'Decline'], ['request_info', 'Request info']].map(function (a) {
          return h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--' + (a[0] === 'approve' ? 'primary' : 'outline') + ' lnkup-btn--sm', text: a[1], onclick: function () { bulk(a[0], table, out); } });
        }));
        list.appendChild(actions);
        list.appendChild(out);
        list.appendChild(h('div', { className: 'lnkup-portfolio__scroll' }, table));
        if (r.data.pages > 1) {
          list.appendChild(h('div', { className: 'lnkup-portfolio__toolbar' }, [
            h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', disabled: state.page <= 1, text: 'Previous', onclick: function () { state.page--; load(); } }),
            h('span', { className: 'lnkup-muted', text: 'Page ' + state.page + ' of ' + r.data.pages }),
            h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', disabled: state.page >= r.data.pages, text: 'Next', onclick: function () { state.page++; load(); } })
          ]));
        }
      });
    }

    function bulk(action, table, out) {
      var ids = Array.prototype.map.call(table.querySelectorAll('[data-app]:checked'), function (c) { return parseInt(c.getAttribute('data-app'), 10); });
      if (!ids.length) {
        flash(out, 'Select at least one application.', 'error');
        return;
      }
      var body = { application_ids: ids, action: action };
      if (action === 'request_info') {
        var msg = window.prompt('What do you need from these vendors?', '');
        if (!msg) {
          return;
        }
        body.info_message = msg;
      } else if (action === 'reject' && !window.confirm('Decline ' + ids.length + ' application(s)?')) {
        return;
      }
      api('POST', '/portfolio/tours/' + tour.id + '/applications/bulk', body).then(function (r) {
        if (!r.ok) {
          flash(out, r.message, 'error');
          return;
        }
        var failed = r.data.results.filter(function (x) { return x.result !== 'ok'; });
        load();
        window.setTimeout(function () {
          flash(list, r.data.ok + ' updated' + (failed.length ? ' · ' + failed.length + ' not changed: ' + failed.map(function (f) { return f.message; }).join(' ') : ''), failed.length ? 'warning' : 'success');
        }, 600);
      });
    }

    load();
  }

  // ---------- vendors (interest + invites)

  function tabVendors(panel, tour) {
    setBusy(panel);
    Promise.all([
      api('GET', '/portfolio/tours/' + tour.id + '/interest'),
      api('GET', '/portfolio/tours/' + tour.id + '/applications', { page: 1 })
    ]).then(function (all) {
      if (!all[0].ok || !all[1].ok) {
        errorCard(panel, all[0].message || all[1].message, function () { tabVendors(panel, tour); });
        return;
      }
      panel.innerHTML = '';
      var vendors = {};
      all[0].data.items.forEach(function (v) { vendors[v.vendor_user_id] = { id: v.vendor_user_id, name: v.business_name || 'Vendor', note: 'Interested in the whole tour' + (v.note ? ': ' + v.note : '') }; });
      all[1].data.items.forEach(function (a) {
        if (a.vendor_user_id && !vendors[a.vendor_user_id]) {
          vendors[a.vendor_user_id] = { id: a.vendor_user_id, name: a.business_name || 'Vendor', note: 'Applied to ' + a.stop };
        }
      });
      var live = tour.stops.filter(function (s) { return s.state === 'published'; });
      panel.appendChild(scope('Invite vendors to specific live stops. An invite is not an approval or a booking; vendors still apply to each stop.'));
      var ids = Object.keys(vendors);
      if (!ids.length) {
        panel.appendChild(h('p', { className: 'lnkup-empty-state', text: 'Vendors who apply or mark interest in this tour will appear here.' }));
        return;
      }
      if (!live.length) {
        panel.appendChild(h('p', { className: 'lnkup-muted', text: 'Publish a stop to start inviting vendors.' }));
      }
      var form = h('form', { className: 'lnkup-form', 'data-testid': 'lnkup-portfolio-invite' }, [
        h('fieldset', { className: 'lnkup-portfolio__stop-picks' }, [h('legend', { text: 'Vendors' })].concat(ids.map(function (id) {
          var v = vendors[id];
          return h('label', {}, [h('input', { type: 'checkbox', name: 'vendor', value: v.id }), ' ' + v.name + ' ', h('span', { className: 'lnkup-muted', text: '· ' + v.note })]);
        }))),
        h('fieldset', { className: 'lnkup-portfolio__stop-picks' }, [h('legend', { text: 'Invite to' })].concat(live.map(function (s) {
          return h('label', {}, [h('input', { type: 'checkbox', name: 'stop', value: s.event_id }), ' ' + (s.city || s.title) + ' · ' + dateLabel(s.event_date)]);
        }))),
        field('Message (optional)', h('textarea', { name: 'message', rows: '2', maxlength: '1000' })),
        h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', disabled: !live.length, text: 'Send invites' })
      ]);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var pick = function (n) { return Array.prototype.map.call(form.querySelectorAll('[name=' + n + ']:checked'), function (c) { return parseInt(c.value, 10); }); };
        api('POST', '/portfolio/tours/' + tour.id + '/invite', { vendor_user_ids: pick('vendor'), event_ids: pick('stop'), message: form.querySelector('[name=message]').value }).then(function (r) {
          r.ok ? flash(form, r.data.invited + ' invite(s) sent · ' + r.data.already + ' already invited · ' + r.data.failed + ' failed', r.data.failed ? 'warning' : 'success') : flash(form, r.message, 'error');
        });
      });
      panel.appendChild(form);
    });
  }

  // ---------- settings

  function tabSettings(panel, tour, reload) {
    panel.innerHTML = '';
    var brands = [['', 'No brand']].concat(((overview && overview.brands) || []).map(function (b) { return [b.id, b.name]; }));
    var form = h('form', { className: 'lnkup-form', 'data-testid': 'lnkup-portfolio-settings' }, [
      h('h3', { text: 'Tour details' }),
      field('Name', input('title', tour.title, { required: true, maxlength: '200' })),
      h('div', { className: 'lnkup-grid lnkup-grid--3' }, [
        field('Type', select('type', [['tour', 'Multi-city tour'], ['recurring', 'Recurring market'], ['series', 'Event series']], tour.type)),
        field('Brand', select('brand_id', brands, tour.brand ? tour.brand.id : '')),
        field('Year or season', input('season', tour.season, { maxlength: '60' }))
      ]),
      h('div', { className: 'lnkup-grid lnkup-grid--2' }, [
        field('Category', input('category', tour.category, { maxlength: '120' })),
        field('Website', input('website', tour.website, { type: 'url' }))
      ]),
      field('Public description', h('textarea', { name: 'description', rows: '4', text: tour.description })),
      field('Who can apply', h('textarea', { name: 'eligibility', rows: '2', text: tour.eligibility })),
      h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary', text: 'Save details' })
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      api('PATCH', '/portfolio/tours/' + tour.id, formData(form)).then(function (r) {
        if (!r.ok) {
          flash(form, r.message, 'error');
          return;
        }
        pendingFlash = 'Tour details saved.';
        reload();
      });
    });
    panel.appendChild(form);

    var tplForm = h('form', { className: 'lnkup-portfolio__inline-form' }, [
      field('Save this setup as a template', input('name', tour.title + ' setup', { maxlength: '160', required: true })),
      h('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Save template' })
    ]);
    tplForm.addEventListener('submit', function (e) {
      e.preventDefault();
      api('POST', '/portfolio/tours/' + tour.id + '/save-as-template', formData(tplForm)).then(function (r) {
        if (r.ok) {
          overview = null;
          flash(tplForm, 'Template saved. Find it on the Tours & Series page.');
        } else {
          flash(tplForm, r.message, 'error');
        }
      });
    });
    panel.appendChild(h('div', { className: 'lnkup-portfolio__block' }, [h('h3', { text: 'Templates' }), tplForm]));

    var archived = tour.status === 'archived';
    var danger = h('div', { className: 'lnkup-portfolio__block lnkup-portfolio__danger' }, [
      h('h3', { text: 'Archive or delete' }),
      h('p', { className: 'lnkup-muted', text: 'Archiving hides the public tour page. Every stop, application, booking and payment is kept for reporting. A tour can only be deleted if no vendor ever applied; its stops are kept as standalone events.' }),
      h('div', { className: 'lnkup-portfolio__actions' }, [
        h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: archived ? 'Restore tour' : 'Archive tour', onclick: function () {
          api('POST', '/portfolio/tours/' + tour.id + '/archive', { archived: !archived }).then(function (r) { r.ok ? reload() : flash(danger, r.message, 'error'); });
        } }),
        h('button', { type: 'button', className: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Delete tour', onclick: function () {
          if (!window.confirm('Delete this tour? Stops are kept as standalone events.')) {
            return;
          }
          api('DELETE', '/portfolio/tours/' + tour.id).then(function (r) {
            if (r.ok) {
              overview = null;
              go({});
            } else {
              flash(danger, r.message, 'error');
            }
          });
        } })
      ])
    ]);
    panel.appendChild(danger);

    var audit = h('div', { className: 'lnkup-portfolio__block' }, [h('h3', { text: 'Recent changes' })]);
    panel.appendChild(audit);
    api('GET', '/portfolio/tours/' + tour.id + '/audit').then(function (r) {
      if (!r.ok) {
        audit.appendChild(h('p', { className: 'lnkup-muted', text: 'Change history is unavailable right now.' }));
        return;
      }
      if (!r.data.items.length) {
        audit.appendChild(h('p', { className: 'lnkup-muted', text: 'No changes recorded yet.' }));
        return;
      }
      audit.appendChild(h('ul', { className: 'lnkup-portfolio__audit' }, r.data.items.map(function (a) {
        return h('li', { text: a.created_at.slice(0, 16) + ' · ' + a.action.replace(/_/g, ' ') + (a.actor ? ' · ' + a.actor : '') });
      })));
    });
  }

  route();
})();
