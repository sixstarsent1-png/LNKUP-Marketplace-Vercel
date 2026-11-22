/**
 * Vendor Passport dashboard (vendor-dashboard?view=passport).
 *
 * All server data is rendered with textContent / attribute setters (never innerHTML).
 */
(function () {
  'use strict';

  var cfg = window.lnkupPassport || {};
  var root = document.getElementById('lnkup-passport');
  if (!root || !cfg.root) {
    return;
  }
  var panel = root.querySelector('[data-passport-panel]');
  var state = { me: null, tab: 'overview', reviews: null, vault: null, history: null, portfolio: null };
  var flags = cfg.flags || {};

  /* ------------------------------------------------------------ helpers */

  function api(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': cfg.nonce || '' };
    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(cfg.root.replace(/\/$/, '') + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: options.body
    })
      .then(function (response) {
        var type = response.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          return response.text().then(function (text) {
            console.error('Passport API returned non-JSON', response.status, text.slice(0, 300));
            throw { message: 'We could not load this section. Try refreshing.' };
          });
        }
        return response.json().then(function (json) {
          if (!response.ok) {
            throw { message: (json && json.message) || 'Request failed.', data: json && json.data };
          }
          return json;
        });
      });
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'text') {
        node.textContent = String(value);
      } else if (key === 'className') {
        node.className = value;
      } else if (key.indexOf('on') === 0 && typeof value === 'function') {
        node.addEventListener(key.slice(2).toLowerCase(), value);
      } else {
        node.setAttribute(key, value === true ? '' : String(value));
      }
    });
    (children || []).forEach(function (child) {
      if (child === null || child === undefined || child === false) {
        return;
      }
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function card(title, children, extraClass) {
    return el('section', { className: 'lnkup-passport-card ' + (extraClass || '') }, [
      title ? el('h2', { className: 'lnkup-passport-card__title', text: title }) : null
    ].concat(children || []));
  }

  function empty(title, desc, ctaLabel, ctaHref) {
    return el('div', { className: 'lnkup-passport-empty' }, [
      el('p', { className: 'lnkup-passport-empty__title', text: title }),
      desc ? el('p', { className: 'lnkup-muted', text: desc }) : null,
      ctaLabel && ctaHref ? el('a', { className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', href: ctaHref, text: ctaLabel }) : null
    ]);
  }

  function errorCard(message, retry) {
    return el('div', { className: 'lnkup-passport-error', role: 'alert' }, [
      el('p', { text: message || 'We could not load this section. Try refreshing or contact support.' }),
      retry ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Try again', onClick: retry }) : null
    ]);
  }

  function loading() {
    return el('div', { className: 'lnkup-passport__skeleton', 'aria-busy': 'true' }, [
      el('span', { className: 'lnkup-skeleton lnkup-skeleton--title' }),
      el('span', { className: 'lnkup-skeleton lnkup-skeleton--line' }),
      el('span', { className: 'lnkup-skeleton lnkup-skeleton--line' })
    ]);
  }

  function toast(message, isError) {
    var t = el('div', { className: 'lnkup-passport-toast' + (isError ? ' is-error' : ''), role: 'status', text: message });
    document.body.appendChild(t);
    setTimeout(function () {
      t.remove();
    }, 3500);
  }

  function chip(text, cls) {
    return el('span', { className: 'lnkup-badge ' + (cls || 'lnkup-badge--outline'), text: text });
  }

  function formatDate(value) {
    if (!value) {
      return '';
    }
    var d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function busy(button, isBusy) {
    if (!button) {
      return;
    }
    button.disabled = !!isBusy;
    button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
  }

  /* ------------------------------------------------------------ tabs */

  var renderers = {
    overview: renderOverview,
    portfolio: renderPortfolio,
    verification: renderVerification,
    history: renderHistory,
    reviews: renderReviews,
    documents: renderDocuments,
    preferences: renderPreferences,
    invitations: renderInvitations,
    settings: renderSettings
  };

  function selectTab(tab) {
    if (!renderers[tab]) {
      tab = 'overview';
    }
    state.tab = tab;
    root.querySelectorAll('[data-passport-tab]').forEach(function (btn) {
      btn.setAttribute('aria-selected', btn.getAttribute('data-passport-tab') === tab ? 'true' : 'false');
    });
    if (window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      window.history.replaceState(null, '', url.toString());
    }
    clear(panel);
    panel.appendChild(loading());
    ensureMe()
      .then(function () {
        clear(panel);
        renderers[tab](panel);
      })
      .catch(function (err) {
        clear(panel);
        panel.appendChild(errorCard(err && err.message, function () {
          state.me = null;
          selectTab(tab);
        }));
      });
  }

  function ensureMe(force) {
    if (state.me && !force) {
      return Promise.resolve(state.me);
    }
    return api('/passport/me').then(function (data) {
      state.me = data;
      return data;
    });
  }

  root.querySelectorAll('[data-passport-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      selectTab(btn.getAttribute('data-passport-tab'));
    });
  });

  /* ------------------------------------------------------------ overview */

  function renderOverview(target) {
    var me = state.me;
    var header = el('div', { className: 'lnkup-passport-hero' + (me.cover ? '' : ' is-plain') }, [
      me.cover ? coverNode(me.cover) : null,
      el('div', { className: 'lnkup-passport-hero__body' }, [
        me.logo ? el('img', { className: 'lnkup-passport-hero__logo', src: me.logo, alt: (me.business_name || 'Business') + ' logo' }) : el('span', { className: 'lnkup-passport-hero__logo is-initials', 'aria-hidden': 'true', text: (me.business_name || 'V').slice(0, 2).toUpperCase() }),
        el('div', {}, [
          el('h2', { className: 'lnkup-passport-hero__name', text: me.business_name || 'Your business' }),
          el('p', { className: 'lnkup-muted', text: [me.city, me.state].filter(Boolean).join(', ') }),
          el('div', { className: 'lnkup-passport-chips' }, (me.categories || []).map(function (c) {
            return chip(c.name, c.is_primary ? 'lnkup-badge--accent' : 'lnkup-badge--outline');
          }))
        ]),
        el('div', { className: 'lnkup-passport-hero__actions' }, [
          el('a', { className: 'lnkup-btn lnkup-btn--sm lnkup-btn--primary', href: cfg.profileUrl, text: 'Edit details' }),
          me.public_url ? el('a', { className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', href: me.public_url, target: '_blank', rel: 'noopener', text: me.is_public ? 'View public Passport' : 'Preview (private)' }) : null
        ])
      ])
    ]);
    target.appendChild(header);

    var strength = me.strength || {};
    var bar = el('div', { className: 'lnkup-passport-meter', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(strength.score || 0), 'aria-label': 'Profile completion' }, [
      el('span', { style: 'width:' + Math.max(0, Math.min(100, strength.score || 0)) + '%' })
    ]);
    var actions = (me.required_actions || []).map(function (a) {
      return el('li', {}, [
        el('button', {
          type: 'button',
          className: 'lnkup-passport-link',
          text: a.label,
          onClick: function () {
            if (!a.target || a.target === 'overview') {
              window.location.href = cfg.profileUrl;
              return;
            }
            selectTab(a.target);
          }
        }),
        a.detail ? el('span', { className: 'lnkup-muted', text: ' — ' + a.detail }) : null
      ]);
    });
    target.appendChild(el('div', { className: 'lnkup-passport-grid' }, [
      card('Profile completion', [
        el('p', { className: 'lnkup-passport-big', text: (strength.score || 0) + '%' }),
        bar,
        el('p', { className: 'lnkup-muted', text: strength.note || '' }),
        actions.length ? el('ul', { className: 'lnkup-passport-actions' }, actions) : el('p', { className: 'lnkup-muted', text: 'Nice work — nothing urgent to do.' })
      ]),
      card('Reputation at a glance', [indicatorList(me.reputation || {})]),
      shareCard(me)
    ]));

    var achievements = (me.reputation && me.reputation.achievements) || [];
    target.appendChild(card('Achievements', achievements.length ? [
      el('ul', { className: 'lnkup-passport-achievements' }, achievements.map(function (a) {
        return el('li', { title: a.rule || '' }, [chip(a.label, 'lnkup-badge--verified'), el('span', { className: 'lnkup-muted', text: ' ' + (a.rule || '') })]);
      }))
    ] : [empty('No achievements yet', 'Achievements are earned automatically from confirmed event participation — never from self-reported claims.')]));
  }

  function coverNode(url) {
    var node = el('div', { className: 'lnkup-passport-hero__cover', 'aria-hidden': 'true' });
    node.style.backgroundImage = 'url("' + String(url).replace(/["\\\n\r]/g, '') + '")';
    return node;
  }

  function indicatorList(rep) {
    var v = rep.verification || {};
    var ex = rep.experience || {};
    var fb = rep.organizer_feedback || {};
    var dr = rep.document_readiness || {};
    var why = rep.explanations || {};
    var rows = [
      ['Business verification', v.is_verified ? v.label : (v.label || 'Not verified yet'), why.verification],
      ['Marketplace experience', ex.label, why.experience],
      ['Organizer feedback', fb.label, why.organizer_feedback],
      ['Documents', dr.label, why.document_readiness]
    ];
    var dl = el('dl', { className: 'lnkup-passport-indicators' });
    rows.forEach(function (row) {
      dl.appendChild(el('div', { className: 'lnkup-passport-indicators__row' }, [
        el('dt', { text: row[0] }),
        el('dd', {}, [row[1] || '—', row[2] ? el('span', { className: 'lnkup-passport-indicators__why', text: row[2] }) : null])
      ]));
    });
    var wrap = el('div', {}, [dl]);
    if (rep.is_new_vendor && rep.new_vendor_note) {
      wrap.appendChild(el('p', { className: 'lnkup-muted', text: rep.new_vendor_note }));
    }
    return wrap;
  }

  function shareCard(me) {
    if (!me.public_url) {
      return card('Share your Passport', [empty('Save your business name to get a shareable link.')]);
    }
    var copy = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Copy link' });
    copy.addEventListener('click', function () {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(me.public_url).then(function () { toast('Link copied'); }, function () { toast('Copy failed — select the link instead', true); });
      }
    });
    return card('Share your Passport', [
      me.is_public ? null : el('p', { className: 'lnkup-alert', text: 'Your Passport is private. Turn on public visibility in Settings to share it.' }),
      el('img', { className: 'lnkup-passport-qr', width: '160', height: '160', loading: 'lazy', alt: 'QR code for your public Passport', src: 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data=' + encodeURIComponent(me.public_url) }),
      el('p', { className: 'lnkup-passport-url' }, [el('a', { href: me.public_url, target: '_blank', rel: 'noopener', text: me.public_url })]),
      copy
    ]);
  }

  /* ------------------------------------------------------------ portfolio */

  function renderPortfolio(target) {
    target.appendChild(loading());
    api('/passport/me/portfolio').then(function (data) {
      clear(target);
      state.portfolio = data;
      drawPortfolio(target);
    }).catch(function (err) {
      clear(target);
      target.appendChild(errorCard(err.message, function () { selectTab('portfolio'); }));
    });
  }

  function drawPortfolio(target) {
    var data = state.portfolio;
    var items = (data.items || []).slice();
    var tags = data.tags || {};
    var list = el('ol', { className: 'lnkup-passport-portfolio', 'aria-label': 'Portfolio photos in display order' });
    var dirty = false;
    var save = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary', text: 'Save portfolio', disabled: true });

    function markDirty() {
      dirty = true;
      save.disabled = false;
    }

    function redraw() {
      clear(list);
      if (!items.length) {
        list.appendChild(el('li', {}, [empty('No photos yet', 'Show your booth, products, food and activations. Organizers approve faster when they can see your setup.')]));
      }
      items.forEach(function (item, index) {
        var caption = el('input', { type: 'text', maxlength: '200', value: item.caption || '', 'aria-label': 'Caption', placeholder: 'Caption' });
        caption.addEventListener('input', function () { item.caption = caption.value; markDirty(); });
        var alt = el('input', { type: 'text', maxlength: '200', value: item.alt || '', 'aria-label': 'Alt text', placeholder: 'Describe the photo (alt text)' });
        alt.addEventListener('input', function () { item.alt = alt.value; markDirty(); });
        var tag = el('select', { 'aria-label': 'Category tag' }, [el('option', { value: '', text: 'No tag' })].concat(Object.keys(tags).map(function (key) {
          return el('option', { value: key, text: tags[key], selected: item.tag === key });
        })));
        tag.addEventListener('change', function () { item.tag = tag.value; markDirty(); });
        var li = el('li', { className: 'lnkup-passport-photo' + (item.is_cover ? ' is-cover' : ''), draggable: 'true', 'data-index': String(index) }, [
          el('img', { src: item.thumb || item.url, alt: item.alt || item.caption || '', loading: 'lazy' }),
          item.is_cover ? chip('Cover', 'lnkup-badge--accent') : null,
          item.event_title ? el('p', { className: 'lnkup-muted', text: 'Vendor photo from ' + item.event_title + ' (not an organizer endorsement)' }) : null,
          caption, alt, tag,
          el('div', { className: 'lnkup-passport-photo__actions' }, [
            el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--xs', text: '↑', 'aria-label': 'Move earlier', disabled: index === 0, onClick: function () { move(index, -1); } }),
            el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--xs', text: '↓', 'aria-label': 'Move later', disabled: index === items.length - 1, onClick: function () { move(index, 1); } }),
            el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--xs', text: 'Make cover', disabled: !!item.is_cover, onClick: function () { items.forEach(function (i) { i.is_cover = false; }); item.is_cover = true; markDirty(); redraw(); } }),
            el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--xs lnkup-btn--danger', text: 'Remove', onClick: function () { items.splice(index, 1); markDirty(); redraw(); } })
          ])
        ]);
        li.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', String(index)); });
        li.addEventListener('dragover', function (e) { e.preventDefault(); });
        li.addEventListener('drop', function (e) {
          e.preventDefault();
          var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
          if (!isNaN(from) && from !== index) {
            var moved = items.splice(from, 1)[0];
            items.splice(index, 0, moved);
            markDirty();
            redraw();
          }
        });
        list.appendChild(li);
      });
    }

    function move(index, delta) {
      var to = index + delta;
      if (to < 0 || to >= items.length) {
        return;
      }
      var moved = items.splice(index, 1)[0];
      items.splice(to, 0, moved);
      markDirty();
      redraw();
    }

    var file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', id: 'lnkup-passport-upload', className: 'screen-reader-text' });
    var uploadLabel = el('label', { for: 'lnkup-passport-upload', className: 'lnkup-btn lnkup-btn--outline', text: 'Add photo' });
    var status = el('p', { className: 'lnkup-muted', 'aria-live': 'polite' });
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) {
        return;
      }
      if (f.size > 8 * 1024 * 1024) {
        status.textContent = 'That image is over 8 MB. Choose a smaller one.';
        return;
      }
      if (dirty) {
        status.textContent = 'Save your changes before adding another photo.';
        return;
      }
      var fd = new FormData();
      fd.append('file', f);
      status.textContent = 'Uploading…';
      api('/passport/me/portfolio/upload', { method: 'POST', body: fd }).then(function (res) {
        items = res.items || [];
        state.portfolio.items = items;
        status.textContent = 'Photo added. Add a caption and alt text so organizers know what they are looking at.';
        file.value = '';
        redraw();
      }).catch(function (err) {
        status.textContent = err.message || 'Upload failed. Try again.';
        file.value = '';
      });
    });

    save.addEventListener('click', function () {
      busy(save, true);
      api('/passport/me/portfolio', { method: 'PUT', body: { items: items } }).then(function (res) {
        items = res.items || [];
        state.portfolio.items = items;
        dirty = false;
        save.disabled = true;
        toast('Portfolio saved');
        redraw();
      }).catch(function (err) {
        toast(err.message || 'Could not save portfolio', true);
      }).then(function () { busy(save, false); save.disabled = !dirty; });
    });

    target.appendChild(card('Portfolio', [
      el('p', { className: 'lnkup-muted', text: 'Drag photos (or use the arrows) to set the order. Up to ' + (data.max || 30) + ' photos. Your uploads are labeled as vendor content and are never shown as organizer endorsements.' }),
      el('div', { className: 'lnkup-passport-toolbar' }, [file, uploadLabel, save]),
      status,
      list
    ]));
    redraw();
  }

  /* ------------------------------------------------------------ verification */

  function renderVerification(target) {
    target.appendChild(loading());
    api('/passport/me/verification').then(function (data) {
      clear(target);
      var rows = Object.keys(data.records || {}).map(function (type) {
        var r = data.records[type];
        return el('tr', {}, [
          el('th', { scope: 'row', text: (data.types || {})[type] || type }),
          el('td', {}, [chip(r.label || r.status, r.status === 'verified' ? 'lnkup-badge--verified' : 'lnkup-badge--outline')]),
          el('td', { text: r.verified_at ? formatDate(r.verified_at) : '—' }),
          el('td', { text: r.expires_at ? formatDate(r.expires_at) : '—' }),
          el('td', { className: 'lnkup-muted', text: ['info_required', 'rejected', 'revoked'].indexOf(r.status) !== -1 ? (r.reason || '') : '' })
        ]);
      });
      var table = el('table', { className: 'lnkup-passport-table' }, [
        el('thead', {}, [el('tr', {}, ['Type', 'Status', 'Verified', 'Recheck by', 'Notes'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
        el('tbody', {}, rows)
      ]);
      var summary = data.summary || {};
      target.appendChild(card('Verification', [
        el('p', { text: summary.is_verified ? summary.label : 'Your business identity is not verified yet.' }),
        summary.explanation ? el('p', { className: 'lnkup-muted', text: summary.explanation }) : null,
        el('p', { className: 'lnkup-muted', text: 'Business verification, document review and event requirements are separate. Being verified does not satisfy an event’s insurance or permit requirements on its own.' }),
        table
      ]));
      if (data.can_submit) {
        var type = el('select', { 'aria-label': 'Verification type' }, [
          el('option', { value: 'business_identity', text: 'Business identity' }),
          el('option', { value: 'business_document', text: 'Business documentation' }),
          el('option', { value: 'license_permit', text: 'License or permit' }),
          el('option', { value: 'insurance', text: 'Insurance' })
        ]);
        var note = el('textarea', { rows: '3', maxlength: '1000', placeholder: 'Anything our team should know (legal business name, EIN last 4 is not required)…', 'aria-label': 'Note for reviewer' });
        var submit = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary', text: 'Request verification' });
        submit.addEventListener('click', function () {
          busy(submit, true);
          api('/passport/me/verification', { method: 'POST', body: { type: type.value, note: note.value } }).then(function () {
            toast('Submitted — we will notify you when it is reviewed.');
            state.me = null;
            selectTab('verification');
          }).catch(function (err) {
            toast(err.message, true);
            busy(submit, false);
          });
        });
        target.appendChild(card('Request or renew verification', [
          el('p', { className: 'lnkup-muted', text: 'Upload supporting documents in the Documents tab first. Never share full tax IDs or bank details here.' }),
          type, note, submit
        ]));
      }
    }).catch(function (err) {
      clear(target);
      target.appendChild(errorCard(err.message, function () { selectTab('verification'); }));
    });
  }

  /* ------------------------------------------------------------ history */

  function renderHistory(target, page) {
    page = page || 1;
    target.appendChild(loading());
    api('/passport/me/history?page=' + page + '&per_page=20').then(function (data) {
      clear(target);
      var privacy = (state.me && state.me.privacy) || {};
      var hidden = (privacy.hidden_event_ids || []).map(Number);
      if (!data.items || !data.items.length) {
        target.appendChild(card('Event History', [empty('No event history yet', 'Your applications, bookings and verified check-ins will build your history here.', 'Find events', cfg.findUrl)]));
        return;
      }
      var stats = data.stats || {};
      var groups = {};
      data.items.forEach(function (item) {
        var year = String(item.event_date || '').slice(0, 4) || 'Undated';
        (groups[year] = groups[year] || []).push(item);
      });
      var blocks = Object.keys(groups).sort().reverse().map(function (year) {
        return el('div', { className: 'lnkup-passport-year' }, [
          el('h3', { text: year + ' Event History' }),
          el('ul', { className: 'lnkup-passport-timeline' }, groups[year].map(function (item) {
            var isHidden = hidden.indexOf(Number(item.event_id)) !== -1;
            var toggle = item.confirmed ? el('label', { className: 'lnkup-passport-toggle' }, [
              el('input', { type: 'checkbox', checked: !isHidden, onChange: function (e) { toggleHidden(item.event_id, !e.target.checked); } }),
              ' Show on public Passport'
            ]) : null;
            return el('li', { className: 'lnkup-passport-timeline__item is-' + item.status }, [
              el('strong', { text: item.event_title }),
              el('span', { className: 'lnkup-muted', text: ' · ' + [formatDate(item.event_date), [item.city, item.state].filter(Boolean).join(', '), item.organizer_name].filter(Boolean).join(' · ') }),
              el('div', {}, [
                chip(item.status_label, item.confirmed ? 'lnkup-badge--verified' : 'lnkup-badge--outline'),
                item.evidence === 'check_in' ? chip('Check-in verified') : null,
                item.tour_title ? chip(item.tour_title + (item.tour_stop_order ? ' · stop ' + item.tour_stop_order : '')) : null
              ]),
              toggle
            ]);
          }))
        ]);
      });
      var pager = el('div', { className: 'lnkup-passport-pager' }, [
        page > 1 ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm', text: 'Newer', onClick: function () { clear(target); renderHistory(target, page - 1); } }) : null,
        data.total > page * data.per_page ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm', text: 'Older', onClick: function () { clear(target); renderHistory(target, page + 1); } }) : null
      ]);
      target.appendChild(card('Event History', [
        el('p', { className: 'lnkup-muted', text: (stats.confirmed_events || 0) + ' confirmed events · ' + (stats.distinct_cities || 0) + ' cities · ' + (stats.distinct_organizers || 0) + ' organizers. Approvals and payments alone never count as attendance.' })
      ].concat(blocks).concat([pager])));
    }).catch(function (err) {
      clear(target);
      target.appendChild(errorCard(err.message, function () { selectTab('history'); }));
    });
  }

  function toggleHidden(eventId, hide) {
    var privacy = (state.me && state.me.privacy) || {};
    var hidden = (privacy.hidden_event_ids || []).map(Number).filter(function (id) { return id !== Number(eventId); });
    if (hide) {
      hidden.push(Number(eventId));
    }
    api('/passport/me/privacy', { method: 'PATCH', body: { hidden_event_ids: hidden } }).then(function (res) {
      state.me.privacy = res.privacy;
      toast(hide ? 'Hidden from your public Passport' : 'Shown on your public Passport');
    }).catch(function (err) { toast(err.message, true); });
  }

  /* ------------------------------------------------------------ reviews */

  function renderReviews(target, page) {
    page = page || 1;
    target.appendChild(loading());
    api('/passport/me/reviews?page=' + page).then(function (data) {
      clear(target);
      var summary = data.summary || {};
      target.appendChild(card('Organizer feedback', [
        el('p', { className: 'lnkup-passport-big', text: summary.label || 'No reviews yet' }),
        el('p', { className: 'lnkup-muted', text: summary.method || '' })
      ]));
      if (!data.items || !data.items.length) {
        target.appendChild(empty('No reviews yet', 'Organizers can review you after an event you were booked for. No reviews is never shown as a negative.'));
        return;
      }
      data.items.forEach(function (review) {
        target.appendChild(reviewCard(review, data.report_reasons || cfg.reportReasons || {}));
      });
      target.appendChild(el('div', { className: 'lnkup-passport-pager' }, [
        page > 1 ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm', text: 'Newer', onClick: function () { clear(target); renderReviews(target, page - 1); } }) : null,
        data.total > page * data.per_page ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm', text: 'Older', onClick: function () { clear(target); renderReviews(target, page + 1); } }) : null
      ]));
    }).catch(function (err) {
      clear(target);
      target.appendChild(errorCard(err.message, function () { selectTab('reviews'); }));
    });
  }

  function reviewCard(review, reasons) {
    var body = el('article', { className: 'lnkup-passport-review' }, [
      el('header', {}, [
        el('strong', { text: '★ ' + review.overall_rating + '/5' }),
        el('span', { className: 'lnkup-muted', text: ' · ' + [review.event_title, review.organizer_name, formatDate(review.created_at)].filter(Boolean).join(' · ') }),
        review.verified_participation ? chip('Verified participation') : null,
        review.under_review ? chip('Under review by LNKup') : null
      ]),
      review.public_text ? el('p', { text: review.public_text }) : null,
      (review.positive_tags || []).length ? el('div', { className: 'lnkup-passport-chips' }, review.positive_tags.map(function (t) { return chip(t.label); })) : null
    ]);
    if (review.vendor_response && review.vendor_response.text) {
      body.appendChild(el('blockquote', { className: 'lnkup-passport-response' }, [el('strong', { text: 'Your response' }), el('p', { text: review.vendor_response.text })]));
    }
    if (review.can_respond) {
      var text = el('textarea', { rows: '3', maxlength: '2000', 'aria-label': 'Public response', placeholder: 'Respond professionally. Your response appears under the review; it does not change it.' });
      text.value = (review.vendor_response && review.vendor_response.text) || '';
      var send = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: review.vendor_response ? 'Update response' : 'Post response' });
      send.addEventListener('click', function () {
        busy(send, true);
        api('/reputation/reviews/' + review.id + '/response', { method: 'POST', body: { text: text.value } }).then(function () {
          toast('Response saved');
          selectTab('reviews');
        }).catch(function (err) { toast(err.message, true); busy(send, false); });
      });
      body.appendChild(el('details', {}, [el('summary', { text: review.vendor_response ? 'Edit your response' : 'Respond publicly' }), text, send]));
    }
    if (review.can_report) {
      var reason = el('select', { 'aria-label': 'Reason' }, Object.keys(reasons).map(function (k) { return el('option', { value: k, text: reasons[k] }); }));
      var details = el('textarea', { rows: '3', maxlength: '2000', 'aria-label': 'Details for our team', placeholder: 'What is inaccurate or inappropriate? (at least 10 characters)' });
      var report = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline', text: 'Send to LNKup' });
      report.addEventListener('click', function () {
        busy(report, true);
        api('/reputation/reviews/' + review.id + '/report', { method: 'POST', body: { reason_code: reason.value, reason: details.value } }).then(function () {
          toast('Reported. The review stays visible while our team reviews it.');
          selectTab('reviews');
        }).catch(function (err) { toast(err.message, true); busy(report, false); });
      });
      body.appendChild(el('details', {}, [el('summary', { text: 'Report this review' }), el('p', { className: 'lnkup-muted', text: 'Reviews are not removed just for being negative. Our team checks reports against published standards.' }), reason, details, report]));
    }
    (review.disputes || []).forEach(function (d) {
      var line = el('p', { className: 'lnkup-muted', text: 'Report status: ' + d.status.replace(/_/g, ' ') + (d.decided_at ? ' · decided ' + formatDate(d.decided_at) : '') });
      body.appendChild(line);
      if (d.can_appeal) {
        var why = el('textarea', { rows: '2', maxlength: '2000', 'aria-label': 'Appeal reason', placeholder: 'Why should this decision be reconsidered?' });
        var appeal = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm', text: 'Appeal once' });
        appeal.addEventListener('click', function () {
          busy(appeal, true);
          api('/reputation/disputes/' + d.id + '/appeal', { method: 'POST', body: { reason: why.value } }).then(function () {
            toast('Appeal submitted');
            selectTab('reviews');
          }).catch(function (err) { toast(err.message, true); busy(appeal, false); });
        });
        body.appendChild(el('details', {}, [el('summary', { text: 'Appeal decision' }), why, appeal]));
      }
    });
    return body;
  }

  /* ------------------------------------------------------------ documents */

  function renderDocuments(target) {
    target.appendChild(loading());
    api('/documents/vault').then(function (data) {
      clear(target);
      var groups = data.groups || [];
      target.appendChild(card('Document Vault', [
        el('p', { className: 'lnkup-muted', text: 'Upload once and reuse when an event asks for the same document. Documents stay private: organizers only see a document after you choose to share it for a specific event.' }),
        uploadForm(data.types || cfg.documentTypes || {}, 0, '')
      ]));
      if (!groups.length) {
        target.appendChild(empty('Your vault is empty', 'Add documents you commonly need, like a certificate of insurance or business license.'));
        return;
      }
      groups.forEach(function (g) {
        var cur = g.current || {};
        var versions = (g.versions || []).map(function (v) {
          return el('li', {}, [
            'v' + v.version + ' · ' + v.status + (v.expires_at ? ' · expires ' + formatDate(v.expires_at) : '') + (v.superseded_by ? ' · replaced' : ''),
            v.rejection_reason ? el('span', { className: 'lnkup-muted', text: ' — ' + v.rejection_reason }) : null
          ]);
        });
        var shares = (g.shares || []).map(function (s) {
          var revoke = s.active ? el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--xs', text: 'Stop sharing' }) : null;
          if (revoke) {
            revoke.addEventListener('click', function () {
              busy(revoke, true);
              api('/documents/shares/' + s.id, { method: 'DELETE' }).then(function () {
                toast('Organizer access removed');
                selectTab('documents');
              }).catch(function (err) { toast(err.message, true); busy(revoke, false); });
            });
          }
          return el('li', {}, [s.organizer_name + ' · ' + (s.event_title || 'event') + ' · v' + s.version + ' · ' + (s.active ? 'access until ' + formatDate(s.access_until) : (s.revoked_at ? 'revoked' : 'lapsed')) + ' ', revoke]);
        });
        target.appendChild(card(g.label, [
          el('p', {}, [chip(cur.status || 'pending', cur.status === 'approved' ? 'lnkup-badge--verified' : 'lnkup-badge--outline'), cur.expires_at ? ' Expires ' + formatDate(cur.expires_at) : '']),
          cur.rejection_reason ? el('p', { className: 'lnkup-alert', text: 'Not approved: ' + cur.rejection_reason }) : null,
          el('details', {}, [el('summary', { text: 'Upload a new version' }), uploadForm(data.types || {}, cur.id, g.type)]),
          versions.length > 1 ? el('details', {}, [el('summary', { text: 'Version history (' + versions.length + ')' }), el('ul', {}, versions)]) : null,
          shares.length ? el('details', { open: true }, [el('summary', { text: 'Shared with organizers' }), el('ul', {}, shares)]) : el('p', { className: 'lnkup-muted', text: data.sharing ? 'Not shared with any organizer.' : 'Sharing with organizers opens soon.' })
        ]));
      });
    }).catch(function (err) {
      clear(target);
      target.appendChild(errorCard(err.message, function () { selectTab('documents'); }));
    });
  }

  function uploadForm(types, replaces, fixedType) {
    var type = el('select', { name: 'document_type', 'aria-label': 'Document type', disabled: !!fixedType }, Object.keys(types).map(function (k) {
      return el('option', { value: k, text: types[k], selected: k === fixedType });
    }));
    var exp = el('input', { type: 'date', name: 'expiration_date', 'aria-label': 'Expiration date (if any)' });
    var insured = el('input', { type: 'text', name: 'insured_name', maxlength: '200', placeholder: 'Additional insured (insurance only, optional)', 'aria-label': 'Additional insured' });
    var coverage = el('input', { type: 'number', name: 'coverage_amount', min: '0', step: '1000', placeholder: 'Coverage per occurrence (optional)', 'aria-label': 'Coverage amount' });
    var file = el('input', { type: 'file', name: 'file', accept: '.pdf,.jpg,.jpeg,.png', 'aria-label': 'Document file' });
    var btn = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--primary', text: replaces ? 'Upload new version' : 'Upload' });
    var msg = el('p', { className: 'lnkup-muted', 'aria-live': 'polite' });
    btn.addEventListener('click', function () {
      if (!file.files || !file.files[0]) {
        msg.textContent = 'Choose a PDF, JPG or PNG file.';
        return;
      }
      if (file.files[0].size > 10 * 1024 * 1024) {
        msg.textContent = 'Documents must be under 10 MB.';
        return;
      }
      var fd = new FormData();
      fd.append('file', file.files[0]);
      fd.append('document_type', fixedType || type.value);
      fd.append('expiration_date', exp.value);
      fd.append('insured_name', insured.value);
      fd.append('coverage_amount', coverage.value);
      if (replaces) {
        fd.append('replaces', String(replaces));
      }
      busy(btn, true);
      msg.textContent = 'Uploading securely…';
      api('/documents/upload', { method: 'POST', body: fd }).then(function () {
        toast('Uploaded — our team will review it.');
        state.me = null;
        selectTab('documents');
      }).catch(function (err) {
        msg.textContent = err.message || 'Upload failed. Try again.';
        busy(btn, false);
      });
    });
    return el('div', { className: 'lnkup-passport-form' }, [fixedType ? null : type, exp, insured, coverage, file, btn, msg]);
  }

  /* ------------------------------------------------------------ preferences */

  function renderPreferences(target) {
    var me = state.me;
    var groups = me.capability_groups || {};
    var selected = (me.capabilities || []).map(function (c) { return c.key; });
    var boxes = [];
    var fieldsets = Object.keys(groups).map(function (gk) {
      var g = groups[gk];
      return el('fieldset', { className: 'lnkup-passport-fieldset' }, [el('legend', { text: g.label })].concat(Object.keys(g.options || {}).map(function (ok) {
        var box = el('input', { type: 'checkbox', value: ok, checked: selected.indexOf(ok) !== -1 });
        boxes.push(box);
        return el('label', { className: 'lnkup-passport-check' }, [box, ' ' + g.options[ok]]);
      })));
    });
    var cities = el('input', { type: 'text', value: (me.cities_served || []).join(', '), 'aria-label': 'Cities served' });
    var events = el('input', { type: 'text', value: (me.preferred_event_types || []).join(', '), 'aria-label': 'Preferred event types' });
    var booths = el('input', { type: 'text', value: (me.preferred_booth_types || []).join(', '), 'aria-label': 'Preferred booth types' });
    var save = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary', text: 'Save preferences' });
    var msg = el('p', { className: 'lnkup-muted', 'aria-live': 'polite' });
    save.addEventListener('click', function () {
      busy(save, true);
      api('/passport/me', {
        method: 'PATCH',
        body: {
          capabilities: boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }),
          cities_served: cities.value,
          preferred_event_types: events.value,
          preferred_booth_types: booths.value
        }
      }).then(function () {
        msg.textContent = 'Saved.';
        state.me = null;
      }).catch(function (err) {
        var fields = err.data && err.data.fields ? Object.values(err.data.fields).join(' ') : '';
        msg.textContent = (err.message || 'Could not save.') + ' ' + fields;
      }).then(function () { busy(save, false); });
    });
    target.appendChild(card('Capabilities', [el('p', { className: 'lnkup-muted', text: 'Tell organizers how you operate. Used for matching — never to exclude you from opportunities you qualify for.' })].concat(fieldsets)));
    target.appendChild(card('Markets & event preferences', [
      el('label', { className: 'lnkup-passport-label', text: 'Cities served (comma separated)' }), cities,
      el('label', { className: 'lnkup-passport-label', text: 'Preferred event types' }), events,
      el('label', { className: 'lnkup-passport-label', text: 'Preferred booth types' }), booths,
      save, msg
    ]));
  }

  /* ------------------------------------------------------------ invitations */

  function renderInvitations(target) {
    var invites = state.me.invitations || [];
    if (!invites.length) {
      target.appendChild(card('Invitations', [empty('No open invitations', 'A public, complete Passport makes it easier for organizers to find and invite you.', 'Find events', cfg.findUrl)]));
      return;
    }
    target.appendChild(card('Invitations', [el('ul', { className: 'lnkup-passport-invites' }, invites.map(function (inv) {
      return el('li', {}, [
        el('strong', { text: inv.event_title || 'Event invitation' }),
        el('span', { className: 'lnkup-muted', text: ' · ' + [inv.organizer_name, formatDate(inv.event_date), inv.expires_at ? 'reply by ' + formatDate(inv.expires_at) : ''].filter(Boolean).join(' · ') }),
        inv.message ? el('p', { text: inv.message }) : null
      ]);
    }))]));
  }

  /* ------------------------------------------------------------ settings */

  function renderSettings(target) {
    var privacy = state.me.privacy || {};
    var labels = {
      public_profile: 'Public Passport page (anyone with the link)',
      show_portfolio: 'Show portfolio photos publicly',
      show_history: 'Show confirmed event history publicly',
      show_reviews: 'Show organizer reviews publicly',
      show_cities: 'Show cities and states served publicly',
      show_socials: 'Show website and social links publicly'
    };
    var inputs = {};
    var rows = Object.keys(labels).map(function (key) {
      inputs[key] = el('input', { type: 'checkbox', checked: !!privacy[key] });
      return el('label', { className: 'lnkup-passport-check' }, [inputs[key], ' ' + labels[key]]);
    });
    var save = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--primary', text: 'Save privacy settings' });
    save.addEventListener('click', function () {
      var body = {};
      Object.keys(inputs).forEach(function (k) { body[k] = inputs[k].checked; });
      busy(save, true);
      api('/passport/me/privacy', { method: 'PATCH', body: body }).then(function (res) {
        state.me.privacy = res.privacy;
        state.me.is_public = res.is_public;
        toast('Privacy settings saved');
      }).catch(function (err) { toast(err.message, true); }).then(function () { busy(save, false); });
    });
    target.appendChild(card('Visibility & privacy', [
      el('p', { className: 'lnkup-muted', text: 'Contact details, documents, payment amounts and organizer notes are never public. Organizers you apply to can still see what they need for your application.' })
    ].concat(rows).concat([save])));
  }

  /* ------------------------------------------------------------ boot */

  var initial = new URL(window.location.href).searchParams.get('tab') || 'overview';
  selectTab(initial);
})();
