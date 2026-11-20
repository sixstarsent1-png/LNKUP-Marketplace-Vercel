(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.crmRestUrl || '';
  var nonce = ajax.restNonce || '';

  function toast(message, type) {
    if (window.lnkupShowToast) {
      window.lnkupShowToast(message, type || 'success');
    }
  }

  function buildNoteItem(id, content, when) {
    var li = document.createElement('li');
    li.className = 'lnkup-crm-note';
    li.setAttribute('data-note-id', String(id));
    var body = document.createElement('p');
    body.className = 'lnkup-crm-note__body';
    body.setAttribute('data-note-body', '');
    body.textContent = content;
    var stamp = document.createElement('span');
    stamp.className = 'lnkup-muted lnkup-type-caption';
    stamp.textContent = when || '';
    var actions = document.createElement('span');
    actions.className = 'lnkup-crm-note__actions';
    [['data-note-edit', 'Edit'], ['data-note-delete', 'Delete']].forEach(function (pair) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost';
      btn.setAttribute(pair[0], '');
      btn.textContent = pair[1];
      actions.appendChild(btn);
    });
    li.appendChild(body);
    li.appendChild(stamp);
    li.appendChild(actions);
    return li;
  }

  function crmFetchJson(path, options) {
    options = options || {};
    if (!base) {
      return Promise.reject(new Error('CRM REST not configured'));
    }
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var type = r.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          var bad = new Error('Something went wrong. Refresh and try again.');
          bad.status = r.status;
          throw bad;
        }
        return r.json().then(function (body) {
          if (!r.ok) {
            var err = new Error((body && body.message) || 'Request failed');
            err.body = body;
            err.status = r.status;
            throw err;
          }
          return body;
        });
      });
  }

  /**
   * CRM request for actions. Failed writes always surface a toast (callers that skip .catch
   * still give feedback); the rejection is marked handled so it is not reported twice.
   */
  function crmFetch(path, options) {
    var method = String((options && options.method) || 'GET').toUpperCase();
    return crmFetchJson(path, options).catch(function (err) {
      console.error('[LNKup CRM]', path, err);
      if (method !== 'GET') {
        toast((err && err.message) ? err.message : 'Something went wrong. Try again.', 'error');
        if (err && typeof err === 'object') {
          err.lnkupToasted = true;
        }
      }
      throw err;
    });
  }

  window.addEventListener('unhandledrejection', function (event) {
    if (event.reason && event.reason.lnkupToasted) {
      event.preventDefault();
    }
  });

  function panelMessage(el, type, title, desc) {
    if (window.LNKupUI) {
      LNKupUI.panelMessage(el, type, title, desc);
      return;
    }
    if (!el) {
      return;
    }
    var cls = type === 'error' ? 'lnkup-alert lnkup-alert--error' : 'lnkup-empty-state lnkup-empty-state--compact';
    el.innerHTML = '<div class="' + cls + '"><p class="lnkup-empty-state__title">' + escapeHtml(title) + '</p>' +
      (desc ? '<p class="lnkup-empty-state__desc">' + escapeHtml(desc) + '</p>' : '') + '</div>';
  }

  function isScopeTab(el) {
    return el.classList.contains('lnkup-btn') || el.classList.contains('lnkup-dash-tabs__btn');
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function syncHashNav() {
    // Theme dashboard.js owns shell sidebar/bottom active state.
    // Only normalize legacy hashes for in-page CRM behavior.
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (window.location.pathname.indexOf('organizer-dashboard') === -1) {
      return;
    }
    var section = hash.split('&')[0].split('?')[0];
    var pricingMatch = section.match(/^pricing-(\d+)$/);
    if (pricingMatch) {
      document.documentElement.setAttribute('data-lnkup-pricing-event', pricingMatch[1]);
      section = 'events';
    } else if (section === 'payments') {
      section = 'financial';
    } else if (section === 'create-opportunity') {
      document.documentElement.removeAttribute('data-lnkup-create-mode');
      section = 'events';
    } else if (section === 'import' || section === 'create-event') {
      section = 'create';
    }
    if (!section) {
      // ?view= pages: keep the server-rendered data-active-section.
      return;
    }
    var crmRoot = document.getElementById('lnkup-organizer-crm');
    if (crmRoot) {
      crmRoot.setAttribute('data-active-section', section);
    }
    document.querySelectorAll('.lnkup-organizer-section-nav a[href], .lnkup-organizer-section-nav [data-hash], .lnkup-organizer-section-nav [data-section]').forEach(function (link) {
      var hash = (link.getAttribute('data-section') || link.getAttribute('data-hash') || '').split('&')[0];
      if (!hash) {
        var href = link.getAttribute('href') || '';
        var parts = href.split('#');
        hash = (parts[1] || '').split('&')[0];
      }
      link.classList.toggle('is-active', hash === section);
    });
  }

  var crmState = { scope: 'discover', view: 'card', q: '', sort: 'score', city: '', category: '', verified: '' };
  var inviteOptionsCache = null;
  var listsCache = [];

  function loadDirectory() {
    var el = document.getElementById('lnkup-crm-directory');
    if (!el || !base) {
      return;
    }
    el.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
    var qs = '?scope=' + encodeURIComponent(crmState.scope) +
      '&view=' + encodeURIComponent(crmState.view) +
      '&q=' + encodeURIComponent(crmState.q) +
      '&sort=' + encodeURIComponent(crmState.sort) +
      '&city=' + encodeURIComponent(crmState.city) +
      '&category=' + encodeURIComponent(crmState.category) +
      (crmState.verified ? '&verified=1' : '');
    crmFetchJson('/vendors' + qs).then(function (res) {
      if (res && res.code) {
        panelMessage(el, 'error', 'Could not load directory', res.message || '');
        return;
      }
      if (res && res.html) {
        el.innerHTML = res.html;
        var moreBtn = document.getElementById('lnkup-crm-directory-more');
        if (moreBtn) {
          var cardCount = el.querySelectorAll('.lnkup-crm-card, .lnkup-vendor-card').length;
          moreBtn.hidden = cardCount <= 6;
          el.classList.toggle('lnkup-crm-directory--limited', cardCount > 6);
          el.classList.remove('lnkup-crm-directory--expanded');
        }
        return;
      }
      panelMessage(el, 'empty', 'No vendors found', 'Try adjusting search or filters.');
    }).catch(function () {
      showRetryError(el, 'Could not load vendors', loadDirectory);
    });
  }

  function loadWidgets() {
    var grid = document.getElementById('lnkup-crm-widgets-grid');
    var activity = document.getElementById('lnkup-crm-activity');
    if (!grid || !base) {
      return;
    }
    var copy = (window.lnkupAjax && window.lnkupAjax.copy) || {};
    crmFetchJson('/dashboard/widgets').then(function (data) {
      if (!data || data.code) {
        panelMessage(grid, 'empty', copy.empty_activity || 'No activity yet', 'Widgets will appear when you have applications and vendor relationships.');
        return;
      }
      var html = '';
      html += '<div class="lnkup-crm-widget lnkup-crm-widget--pending lnkup-micro-lift"><h3>Pending reviews</h3><p class="lnkup-crm-widget__value">' + (data.pending_applications || 0) + '</p></div>';
      html += '<div class="lnkup-crm-widget lnkup-crm-widget--favorites lnkup-micro-lift"><h3>Favorites</h3><p class="lnkup-crm-widget__value">' + (data.favorites_count || 0) + '</p></div>';
      html += '<div class="lnkup-crm-widget lnkup-crm-widget--preferred lnkup-micro-lift"><h3>Preferred</h3><p class="lnkup-crm-widget__value">' + (data.preferred_count || 0) + '</p></div>';
      grid.innerHTML = html;

      var discoverHero = document.querySelector('#vendors .lnkup-executive-hero');
      if (discoverHero) {
        var metricEls = discoverHero.querySelectorAll('.lnkup-executive-hero__metric strong');
        if (metricEls[0]) metricEls[0].textContent = String(data.favorites_count || 0);
        if (metricEls[1]) metricEls[1].textContent = String(data.preferred_count || 0);
      }

      if (activity) {
        var actBody = activity.querySelector('.lnkup-organizer-activity__body') || activity;
        var actHtml = '';
        var appliedRows = (data.activity_feed || []).filter(function (row) {
          return (row.activity_type || '') === 'applied';
        });
        if (appliedRows.length) {
          actHtml += '<ul class="lnkup-organizer-activity-feed" data-testid="lnkup-organizer-activity-feed">';
          appliedRows.forEach(function (row) {
            var when = row.created_at ? new Date(row.created_at).toLocaleString() : '';
            actHtml += '<li class="lnkup-organizer-activity-feed__item"><span>Applied</span><span class="lnkup-muted">' + escapeHtml(when) + '</span></li>';
          });
          actHtml += '</ul>';
        }
        if (actHtml) {
          actBody.innerHTML = actHtml;
        } else if (window.LNKupUI && LNKupUI.panelEmpty) {
          LNKupUI.panelEmpty(actBody, {
            title: 'No recent applications yet',
            desc: 'When a vendor applies, it will show here as Applied.',
            compact: true
          });
        } else {
          actBody.innerHTML = '<p class="lnkup-muted">' + escapeHtml(copy.empty_activity || 'No recent activity yet.') + '</p>';
        }
      }
    }).catch(function () {
      panelMessage(grid, 'error', 'Could not load activity', 'CRM data is temporarily unavailable.');
      if (activity) {
        var errBody = activity.querySelector('.lnkup-organizer-activity__body') || activity;
        if (window.LNKupUI && LNKupUI.panelError) {
          LNKupUI.panelError(errBody, {
            title: 'Could not load activity',
            desc: 'CRM data is temporarily unavailable. Please refresh and try again.',
            compact: true
          });
        } else {
          panelMessage(errBody, 'error', 'Could not load activity', 'CRM data is temporarily unavailable.');
        }
      }
    });
  }

  function loadLists() {
    var el = document.getElementById('lnkup-crm-lists');
    if (!el || !base) {
      return;
    }
    el.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
    return crmFetchJson('/lists').then(function (res) {
      var items = (res && res.items) ? res.items : [];
      listsCache = items;
      var summary = document.getElementById('lnkup-organizer-lists-summary');
      if (summary) {
        if (!items.length) {
          summary.textContent = 'Save vendors from Discover, then invite the list to an event.';
        } else {
          var first = items[0];
          summary.textContent = items.length === 1
            ? first.name + ' (' + (first.count || 0) + ' vendors)'
            : items.length + ' lists · latest: ' + first.name;
        }
      }
      if (!items.length) {
        if (window.LNKupUI && LNKupUI.panelEmpty) {
          var copy = (window.lnkupAjax && window.lnkupAjax.copy) || {};
          LNKupUI.panelEmpty(el, {
            title: 'No lists yet',
            desc: copy.empty_vendor_lists || 'Create a list to save vendor segments for future events.',
            ctaLabel: copy.discover_vendors || 'Discover vendors',
            ctaHref: (ajax.organizerDashboardUrl || window.location.pathname) + '?view=vendors'
          });
        } else {
          panelMessage(el, 'empty', 'No lists yet', 'Create a list to save vendor segments for future events.');
        }
        return;
      }
      var html = '<div class="lnkup-crm-list-grid">';
      items.forEach(function (list) {
        var color = list.color || '#c8ff00';
        html += '<article class="lnkup-crm-list-card lnkup-crm-list-card--clickable" data-list-id="' + list.id + '" role="button" tabindex="0">';
        html += '<div class="lnkup-crm-list-card__top">';
        html += '<span class="lnkup-crm-list-card__swatch" style="background:' + escapeHtml(color) + '"></span>';
        html += '<strong>' + escapeHtml(list.name) + '</strong>';
        html += '<span class="lnkup-crm-list-card__chevron" aria-hidden="true">›</span>';
        html += '</div>';
        html += '<p class="lnkup-crm-list-card__count">' + (list.count || 0) + ' <span class="lnkup-muted">vendors</span></p>';
        html += '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-crm-list-delete" data-list-id="' + list.id + '">Delete</button>';
        html += '</article>';
      });
      html += '</div>';
      el.innerHTML = html;
    }).catch(function () {
      showRetryError(el, 'Could not load lists', loadLists);
    });
  }

  function showRetryError(el, title, retryFn) {
    if (window.LNKupUI && LNKupUI.panelError) {
      LNKupUI.panelError(el, { title: title, desc: 'Check your connection and try again.', onRetry: retryFn });
      return;
    }
    panelMessage(el, 'error', title, 'Please refresh and try again.');
  }

  function inviteStatusKey(status) {
    var s = (status || '').toLowerCase();
    if (s.indexOf('accept') > -1) {
      return 'accepted';
    }
    if (s.indexOf('open') > -1) {
      return 'opened';
    }
    return 'sent';
  }

  function inviteStatusClass(status) {
    var s = (status || '').toLowerCase();
    if (s.indexOf('accept') > -1) {
      return 'lnkup-status-pill--accent';
    }
    if (s.indexOf('open') > -1 || s.indexOf('sent') > -1) {
      return 'lnkup-status-pill--muted';
    }
    return '';
  }

  function loadInvites() {
    var el = document.getElementById('lnkup-crm-invites');
    if (!el || !base) {
      return;
    }
    el.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
    crmFetchJson('/invites/list').then(function (res) {
      var items = (res && res.items) ? res.items : [];
      var summary = document.getElementById('lnkup-organizer-invites-summary');
      if (summary) {
        if (!items.length) {
          summary.textContent = 'No invites sent yet.';
        } else {
          summary.textContent = items.length === 1
            ? '1 invite sent'
            : items.length + ' invites sent';
        }
      }
      if (!items.length) {
        if (window.LNKupUI && LNKupUI.panelEmpty) {
          var copy = (window.lnkupAjax && window.lnkupAjax.copy) || {};
          LNKupUI.panelEmpty(el, {
            title: 'No invites sent yet',
            desc: copy.empty_vendor_invites || 'Discover vendors and send your first invite.',
            ctaLabel: copy.discover_vendors || 'Discover vendors',
            ctaHref: (ajax.organizerDashboardUrl || window.location.pathname) + '?view=vendors'
          });
        } else {
          panelMessage(el, 'empty', 'No invites sent yet', 'Invite vendors from the directory or vendor profiles.');
        }
        return;
      }
      var statusCounts = { sent: 0, opened: 0, accepted: 0 };
      items.forEach(function (inv) {
        statusCounts[inviteStatusKey(inv.status)]++;
      });
      var html = '<div class="lnkup-crm-invite-filters" role="group" aria-label="Filter invites">';
      [['all', 'All', items.length], ['sent', 'Sent', statusCounts.sent], ['opened', 'Opened', statusCounts.opened], ['accepted', 'Accepted', statusCounts.accepted]].forEach(function (f) {
        html += '<button type="button" class="lnkup-badge' + (f[0] === 'all' ? ' is-active' : '') + '" data-invite-filter="' + f[0] + '" aria-pressed="' + (f[0] === 'all' ? 'true' : 'false') + '">' + f[1] + ' (' + f[2] + ')</button>';
      });
      html += '</div><p class="lnkup-muted" data-invite-filter-empty hidden>No invites with this status.</p>';
      items.forEach(function (inv) {
        var link = inv.link || inv.invite_link || '';
        var eventLine = inv.event_title ? escapeHtml(inv.event_title) + ' · ' : '';
        html += '<article class="lnkup-crm-invite-card" data-invite-status="' + inviteStatusKey(inv.status) + '">';
        html += '<div class="lnkup-crm-invite-card__header"><strong>' + escapeHtml(inv.vendor) + '</strong>';
        html += '<span class="lnkup-status-pill ' + inviteStatusClass(inv.status) + '">' + escapeHtml(inv.status || 'sent') + '</span></div>';
        html += '<p class="lnkup-muted">' + eventLine + escapeHtml(inv.created || '') + '</p>';
        html += '<div class="lnkup-crm-card__actions">';
        if (link) {
          html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-crm-copy-invite" data-link="' + escapeHtml(link) + '">Copy link</button>';
        }
        if (inv.id && link) {
          html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-crm-resend-invite" data-link="' + escapeHtml(link) + '">Resend link</button>';
        }
        html += '</div></article>';
      });
      el.innerHTML = html;
      if (!el.getAttribute('data-filter-bound')) {
        el.setAttribute('data-filter-bound', '1');
        el.addEventListener('click', function (e) {
          var chip = e.target.closest('[data-invite-filter]');
          if (!chip) {
            return;
          }
          var key = chip.getAttribute('data-invite-filter');
          var shown = 0;
          el.querySelectorAll('[data-invite-filter]').forEach(function (b) {
            b.classList.toggle('is-active', b === chip);
            b.setAttribute('aria-pressed', b === chip ? 'true' : 'false');
          });
          el.querySelectorAll('.lnkup-crm-invite-card').forEach(function (card) {
            var match = key === 'all' || card.getAttribute('data-invite-status') === key;
            card.hidden = !match;
            if (match) {
              shown++;
            }
          });
          var empty = el.querySelector('[data-invite-filter-empty]');
          if (empty) {
            empty.hidden = shown !== 0;
          }
        });
      }
    }).catch(function () {
      showRetryError(el, 'Could not load invites', loadInvites);
    });
  }

  function loadOutreachCampaigns() {
    var el = document.getElementById('lnkup-crm-outreach-campaigns');
    if (!el || !base) {
      return;
    }
    crmFetchJson('/outreach/list').then(function (res) {
      var items = (res && res.items) ? res.items : [];
      if (!items.length) {
        el.innerHTML = '';
        return;
      }
      var html = '<div class="lnkup-crm-outreach-campaigns__list"><p class="lnkup-muted lnkup-crm-outreach-campaigns__title">Email outreach campaigns</p>';
      items.forEach(function (c) {
        var progress = (c.sent || 0) + '/' + (c.total || 0);
        html += '<article class="lnkup-crm-outreach-card">';
        html += '<div class="lnkup-crm-outreach-card__header"><strong>' + escapeHtml(c.name || 'Campaign') + '</strong>';
        html += '<span class="lnkup-status-pill lnkup-status-pill--muted">' + escapeHtml(c.status || 'draft') + '</span></div>';
        html += '<p class="lnkup-muted">Sent ' + escapeHtml(progress) + ' · Registered ' + escapeHtml(String(c.registered || 0)) + '</p>';
        html += '</article>';
      });
      html += '</div>';
      el.innerHTML = html;
    }).catch(function () {
      el.innerHTML = '';
    });
  }

  function initBulkOutreachForm() {
    var form = document.getElementById('lnkup-crm-bulk-outreach-form');
    if (!form || !base) {
      return;
    }
    var eventSelect = document.getElementById('lnkup-crm-bulk-event');
    var oppSelect = document.getElementById('lnkup-crm-bulk-opp');
    if (!eventSelect || !oppSelect) {
      return;
    }

    fetchInviteOptions().then(function (options) {
      if (!options.items || !options.items.length) {
        eventSelect.innerHTML = '<option value="">Launch an event first</option>';
        oppSelect.innerHTML = '<option value="">—</option>';
        return;
      }
      var eventOptions = '<option value="">Select event (optional)…</option>';
      options.items.forEach(function (ev) {
        var meta = [];
        if (ev.date) {
          meta.push(ev.date);
        }
        if (ev.city) {
          meta.push(ev.city);
        }
        eventOptions += '<option value="' + ev.event_id + '">' + escapeHtml(ev.title) + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</option>';
      });
      eventSelect.innerHTML = eventOptions;
      oppSelect.innerHTML = '<option value="">Select opportunity…</option>';
    });

    eventSelect.addEventListener('change', function () {
      fetchInviteOptions().then(function (options) {
        oppSelect.innerHTML = buildOpportunityOptions(options.items || [], eventSelect.value);
      });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var emailsEl = document.getElementById('lnkup-crm-bulk-emails');
      var messageEl = document.getElementById('lnkup-crm-bulk-message');
      var emails = emailsEl ? emailsEl.value.trim() : '';
      if (!emails) {
        toast('Add at least one email address.', 'error');
        return;
      }
      var submitBtn = form.querySelector('[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
      }
      crmFetch('/outreach/bulk', {
        method: 'POST',
        body: {
          event_id: eventSelect.value ? parseInt(eventSelect.value, 10) : 0,
          opportunity_id: oppSelect.value ? parseInt(oppSelect.value, 10) : 0,
          emails: emails,
          message: messageEl ? messageEl.value.trim() : ''
        }
      }).then(function () {
        toast('Bulk invites queued.');
        if (emailsEl) {
          emailsEl.value = '';
        }
        if (messageEl) {
          messageEl.value = '';
        }
        loadOutreachCampaigns();
      }).catch(function (err) {
        toast((err && err.message) || 'Could not send bulk invites.', 'error');
      }).finally(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
        }
      });
    });
  }

  function updateCompareBar() {
    var bar = document.getElementById('lnkup-crm-compare-bar');
    var countEl = document.getElementById('lnkup-crm-compare-count');
    if (!bar || !countEl) {
      return;
    }
    var checked = document.querySelectorAll('.lnkup-crm-compare-pick:checked');
    var n = checked.length;
    bar.hidden = n < 2;
    countEl.textContent = n + (n === 1 ? ' selected' : ' selected');
  }

  var appFilterState = { status: 'all', event: 'all' };

  function parseAppsHash(hash) {
    hash = (hash || '').replace(/^#/, '');
    var parts = hash.split('&');
    var section = parts[0] || '';
    var params = { section: section, filter: '', event: '' };
    for (var i = 1; i < parts.length; i++) {
      var kv = parts[i].split('=');
      if (kv[0] === 'filter') {
        params.filter = decodeURIComponent(kv[1] || '');
      } else if (kv[0] === 'event') {
        params.event = decodeURIComponent(kv[1] || '');
      }
    }
    return params;
  }

  function filterApplications(filter, eventId) {
    if (typeof filter === 'string' && filter) {
      appFilterState.status = filter;
    }
    if (typeof eventId === 'string' && eventId) {
      appFilterState.event = eventId;
    } else if (eventId === 'all') {
      appFilterState.event = 'all';
    }
    var statusFilter = appFilterState.status || 'all';
    var eventFilter = appFilterState.event || 'all';
    var cards = document.querySelectorAll('.lnkup-crm-app-card');
    var visible = 0;
    cards.forEach(function (card) {
      var status = card.getAttribute('data-org-app-status') || '';
      var show = statusFilter === 'all';
      if (!show && statusFilter === 'pending') {
        show = card.getAttribute('data-org-app-filter') === 'pending';
      } else if (!show && statusFilter === 'approved') {
        show = status === 'approved' || status === 'payment_required';
      } else if (!show && statusFilter === 'paid') {
        show = status === 'paid' || status === 'completed';
      } else if (!show && statusFilter === 'waitlisted') {
        show = status === 'waitlisted';
      } else if (!show && statusFilter === 'rejected') {
        show = status === 'rejected';
      } else if (!show && statusFilter === 'unpaid') {
        show = card.getAttribute('data-org-app-paid') === 'no' && (status === 'approved' || status === 'payment_required');
      } else if (!show && statusFilter === 'missing_docs') {
        show = card.getAttribute('data-org-app-docs') === 'missing';
      }
      if (show && eventFilter && eventFilter !== 'all') {
        show = String(card.getAttribute('data-event-id') || '') === String(eventFilter);
      }
      card.hidden = !show;
      if (show) {
        visible++;
      }
    });
    var grid = document.getElementById('lnkup-app-intel-grid');
    var empty = document.getElementById('lnkup-app-filter-empty');
    if (grid && (statusFilter !== 'all' || (eventFilter && eventFilter !== 'all')) && visible === 0) {
      if (!empty) {
        empty = document.createElement('p');
        empty.id = 'lnkup-app-filter-empty';
        empty.className = 'lnkup-muted';
        grid.appendChild(empty);
      }
      empty.textContent = 'No applications match this filter.';
      empty.hidden = false;
    } else if (empty) {
      empty.hidden = true;
    }
    syncExternalInterestVisibility(statusFilter);
    syncAppEventControls();
  }

  function syncAppEventControls() {
    var eventFilter = appFilterState.event || 'all';
    var select = document.getElementById('lnkup-org-app-event');
    if (select && select.value !== String(eventFilter)) {
      select.value = String(eventFilter);
    }
    var context = document.getElementById('lnkup-org-app-event-context');
    var pending = 0;
    var counts = { all: 0, pending: 0, approved: 0, unpaid: 0, paid: 0, waitlisted: 0, rejected: 0, missing_docs: 0 };
    document.querySelectorAll('.lnkup-crm-app-card').forEach(function (card) {
      if (eventFilter !== 'all' && String(card.getAttribute('data-event-id') || '') !== String(eventFilter)) {
        return;
      }
      var status = card.getAttribute('data-org-app-status') || '';
      counts.all++;
      if (card.getAttribute('data-org-app-filter') === 'pending') {
        counts.pending++;
        pending++;
      }
      if (status === 'approved' || status === 'payment_required') {
        counts.approved++;
      }
      if (card.getAttribute('data-org-app-paid') === 'no' && (status === 'approved' || status === 'payment_required')) {
        counts.unpaid++;
      }
      if (status === 'paid' || status === 'completed') {
        counts.paid++;
      }
      if (status === 'waitlisted') {
        counts.waitlisted++;
      }
      if (status === 'rejected') {
        counts.rejected++;
      }
      if (card.getAttribute('data-org-app-docs') === 'missing') {
        counts.missing_docs++;
      }
    });
    document.querySelectorAll('[data-org-app-filter]').forEach(function (btn) {
      if (btn.tagName !== 'BUTTON') {
        return;
      }
      var key = btn.getAttribute('data-org-app-filter');
      var badge = btn.querySelector('.lnkup-dash-tabs__badge');
      if (badge && Object.prototype.hasOwnProperty.call(counts, key)) {
        badge.textContent = String(counts[key]);
      }
    });
    if (!context) {
      return;
    }
    if (eventFilter === 'all') {
      context.hidden = true;
      context.textContent = '';
      return;
    }
    var name = '';
    if (select) {
      var opt = select.querySelector('option[value="' + eventFilter + '"]');
      name = opt ? (opt.getAttribute('data-event-name') || opt.textContent) : '';
    }
    context.hidden = false;
    context.textContent = 'Showing requests for ' + name + ' · ' + pending + ' pending';
  }

  function syncExternalInterestVisibility(statusFilter) {
    var mod = document.querySelector('[data-testid="lnkup-external-pending-interests-module"]');
    if (!mod) {
      return;
    }
    var status = statusFilter || appFilterState.status || 'all';
    mod.hidden = status !== 'all' && status !== 'pending';
  }

  function setAppsHash(statusFilter, eventFilter) {
    var url = new URL(window.location.href);
    url.hash = '';
    url.searchParams.set('view', 'applications');
    url.searchParams.delete('lnkup_page');
    if (statusFilter && statusFilter !== 'all') {
      url.searchParams.set('filter', statusFilter);
    } else {
      url.searchParams.delete('filter');
    }
    if (eventFilter && eventFilter !== 'all') {
      url.searchParams.set('event_filter', eventFilter);
      url.searchParams.delete('event');
    } else {
      url.searchParams.delete('event_filter');
    }
    window.location.assign(url.toString());
  }

  function applyAppsDeepLink() {
    var params = parseAppsHash(window.location.hash || '');
    if (params.section !== 'applications' && params.section.indexOf('applications') !== 0) {
      return;
    }
    var filter = params.filter || 'all';
    var eventId = params.event || 'all';
    if (filter === 'paid' || filter === 'waitlisted' || filter === 'rejected' || filter === 'missing_docs') {
      var extra = document.getElementById('lnkup-org-app-filters-extra');
      var moreBtn = document.querySelector('[data-org-app-filter-toggle]');
      if (extra) {
        extra.hidden = false;
      }
      if (moreBtn) {
        moreBtn.setAttribute('aria-expanded', 'true');
      }
    }
    document.querySelectorAll('.lnkup-organizer-app-filters .lnkup-dash-tabs__btn[data-org-app-filter]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-org-app-filter') === filter);
    });
    document.querySelectorAll('[data-org-app-event]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-org-app-event') === eventId);
    });
    filterApplications(filter, eventId);
  }

  function escapeHtml(str) {
    if (!str) {
      return '';
    }
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function openCompare(ids) {
    if (ids.length < 2) {
      toast('Select at least 2 applications to compare.', 'error');
      return;
    }
    crmFetch('/applications/compare?ids=' + ids.join(',')).then(function (res) {
      var items = (res && res.items) ? res.items : [];
      var modal = document.getElementById('lnkup-crm-compare-modal');
      if (!modal) {
        modal = document.createElement('div');
        modal.id = 'lnkup-crm-compare-modal';
        modal.className = 'lnkup-crm-modal';
        modal.innerHTML = '<div class="lnkup-crm-modal__inner"><button type="button" class="lnkup-crm-modal__close" aria-label="Close comparison">&times;</button><div id="lnkup-crm-compare-body"></div></div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (e) {
          if (e.target === modal || e.target.classList.contains('lnkup-crm-modal__close')) {
            modal.hidden = true;
          }
        });
      }
      var body = document.getElementById('lnkup-crm-compare-body');
      var html = '<h2>Compare Applicants</h2><table class="lnkup-table lnkup-crm-compare-table"><thead><tr><th>Field</th>';
      items.forEach(function (p) {
        html += '<th>' + escapeHtml(p.business_name) + '</th>';
      });
      html += '</tr></thead><tbody>';
      var fields = [
        ['Score', 'vendor_score'],
        ['Rating', 'avg_rating'],
        ['Reviews', 'review_count'],
        ['City', 'city'],
        ['Events', 'completed_events'],
        ['Message', 'application_message']
      ];
      fields.forEach(function (pair) {
        html += '<tr><td>' + escapeHtml(pair[0]) + '</td>';
        items.forEach(function (p) {
          html += '<td>' + escapeHtml(String(p[pair[1]] != null ? p[pair[1]] : '—')) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table>';
      body.innerHTML = html;
      modal.hidden = false;
    });
  }

  function fetchInviteOptions() {
    if (inviteOptionsCache) {
      return Promise.resolve(inviteOptionsCache);
    }
    return crmFetchJson('/events/invite-options').then(function (data) {
      inviteOptionsCache = data || { items: [], needs_event: true };
      return inviteOptionsCache;
    });
  }

  function invalidateInviteOptions() {
    inviteOptionsCache = null;
  }

  function closeSlideOver(overlay) {
    if (overlay && overlay.parentNode) {
      overlay.parentNode.removeChild(overlay);
    }
  }

  function buildOpportunityOptions(events, eventId) {
    var html = '<option value="">Select opportunity…</option>';
    if (!eventId) {
      return html;
    }
    events.forEach(function (ev) {
      if (String(ev.event_id) !== String(eventId)) {
        return;
      }
      var opps = ev.opportunities || [];
      opps.forEach(function (opp) {
        var label = opp.label + ' — $' + (opp.vendor_fee || 0) + ' · ' + (opp.spots_remaining || 0) + ' spots';
        html += '<option value="' + escapeHtml(String(opp.id)) + '"' + (opps.length === 1 ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
      });
    });
    return html;
  }

  function openInviteModal(opts) {
    opts = opts || {};
    var vendorUserId = opts.vendorUserId ? parseInt(opts.vendorUserId, 10) : 0;
    var vendorName = opts.vendorName || '';
    var bulkVendors = opts.bulkVendors || null;
    var prefillEventId = opts.prefillEventId || 0;
    var prefillOpportunityId = opts.prefillOpportunityId || 0;

    if (!vendorUserId && !bulkVendors) {
      if (!document.getElementById('vendors')) {
        window.location.assign(window.location.pathname + '?view=vendors');
        return;
      }
      if (window.lnkupOrganizerExpandPanel) {
        window.lnkupOrganizerExpandPanel('vendors', true);
      }
      var search = document.getElementById('lnkup-crm-vendor-search');
      if (search) {
        search.focus();
      }
      toast('Choose a vendor from Discover Vendors, then click Invite.', 'success');
      return;
    }

    fetchInviteOptions().then(function (options) {
      var overlay = document.createElement('div');
      overlay.className = 'lnkup-crm-slide';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');

      var title = bulkVendors
        ? 'Invite ' + bulkVendors.length + ' vendors to event'
        : (vendorName ? 'Invite ' + vendorName : 'Invite vendor');

      var bodyHtml = '';
      if (options.needs_event || !options.items || !options.items.length) {
        bodyHtml = '<div class="lnkup-alert lnkup-alert--info"><p>Launch an event with a vendor opportunity before sending invites.</p>';
        bodyHtml += '<a href="' + window.location.pathname + '?view=create" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm">Launch event</a></div>';
      } else {
        var eventOptions = '<option value="">Select event…</option>';
        options.items.forEach(function (ev) {
          var meta = [];
          if (ev.date) {
            meta.push(ev.date);
          }
          if (ev.city) {
            meta.push(ev.city);
          }
          var selected = prefillEventId && String(ev.event_id) === String(prefillEventId) ? ' selected' : '';
          eventOptions += '<option value="' + ev.event_id + '"' + selected + '>' + escapeHtml(ev.event_title) + (meta.length ? ' (' + escapeHtml(meta.join(' · ')) + ')' : '') + '</option>';
        });
        var initialEvent = prefillEventId || (options.items[0] && options.items[0].event_id) || '';
        bodyHtml += '<form id="lnkup-crm-invite-form" class="lnkup-crm-slide-form">';
        bodyHtml += '<label class="lnkup-form-row">Event<select name="event_id" id="lnkup-crm-invite-event" required>' + eventOptions + '</select></label>';
        bodyHtml += '<label class="lnkup-form-row">Opportunity<select name="opportunity_id" id="lnkup-crm-invite-opp" required>' + buildOpportunityOptions(options.items, initialEvent) + '</select></label>';
        bodyHtml += '<label class="lnkup-form-row">Personal message<textarea name="message" rows="3" placeholder="Optional note for the vendor"></textarea></label>';
        bodyHtml += '<div class="lnkup-crm-slide-form__actions">';
        bodyHtml += '<button type="submit" class="lnkup-btn lnkup-btn--primary">Send invite</button>';
        bodyHtml += '<button type="button" class="lnkup-btn lnkup-btn--ghost" id="lnkup-crm-invite-cancel">Cancel</button>';
        bodyHtml += '</div></form>';
      }

      overlay.innerHTML = '<div class="lnkup-crm-slide__panel"><button type="button" class="lnkup-crm-slide__close" aria-label="Close">&times;</button><h2 class="lnkup-crm-slide__title">' + escapeHtml(title) + '</h2>' + bodyHtml + '</div>';
      document.body.appendChild(overlay);

      function close() {
        closeSlideOver(overlay);
      }
      overlay.querySelector('.lnkup-crm-slide__close').addEventListener('click', close);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
          close();
        }
      });
      var cancelBtn = overlay.querySelector('#lnkup-crm-invite-cancel');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', close);
      }

      var eventSelect = overlay.querySelector('#lnkup-crm-invite-event');
      var oppSelect = overlay.querySelector('#lnkup-crm-invite-opp');
      if (eventSelect && oppSelect) {
        if (prefillOpportunityId) {
          oppSelect.value = String(prefillOpportunityId);
        }
        eventSelect.addEventListener('change', function () {
          oppSelect.innerHTML = buildOpportunityOptions(options.items, eventSelect.value);
        });
      }

      var form = overlay.querySelector('#lnkup-crm-invite-form');
      if (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var fd = new FormData(form);
          var eventId = parseInt(fd.get('event_id'), 10) || 0;
          var oppId = parseInt(fd.get('opportunity_id'), 10) || 0;
          var message = fd.get('message') || '';
          var submitBtn = form.querySelector('[type="submit"]');
          if (submitBtn) {
            submitBtn.disabled = true;
          }

          function sendOne(uid) {
            return crmFetchJson('/invites', {
              method: 'POST',
              body: {
                vendor_user_id: uid,
                event_id: eventId,
                opportunity_id: oppId,
                message: message,
                type: 'apply'
              }
            }).then(function (res) {
              return { ok: true, res: res };
            }, function (err) {
              return { ok: false, duplicate: err && err.status === 409, message: (err && err.message) || 'Could not send invite.' };
            });
          }

          var uids = (bulkVendors && bulkVendors.length)
            ? bulkVendors.map(function (v) { return parseInt(v.vendor_user_id, 10); })
            : [vendorUserId];
          var outcomes = [];
          var chain = Promise.resolve();
          uids.forEach(function (uid) {
            chain = chain.then(function () {
              return sendOne(uid).then(function (o) { outcomes.push(o); });
            });
          });

          chain.then(function () {
            var sent = outcomes.filter(function (o) { return o.ok; });
            var dupes = outcomes.filter(function (o) { return o.duplicate; }).length;
            var failed = outcomes.length - sent.length - dupes;
            if (!sent.length) {
              var first = outcomes[0] || {};
              toast(dupes && !failed ? (uids.length > 1 ? 'Every selected vendor already has an open invite.' : first.message) : (first.message || 'Could not send invite.'), 'error');
              if (submitBtn) {
                submitBtn.disabled = false;
              }
              return;
            }
            close();
            var parts = [sent.length === 1 ? 'Invite sent.' : sent.length + ' invites sent.'];
            if (dupes) {
              parts.push(dupes + ' already invited.');
            }
            if (failed) {
              parts.push(failed + ' failed.');
            }
            toast(parts.join(' '), failed ? 'error' : 'success');
            var last = sent[sent.length - 1].res;
            if (sent.length === 1 && last && last.link && navigator.clipboard) {
              navigator.clipboard.writeText(last.link).catch(function () {});
            }
            loadInvites();
          });
        });
      }
    }).catch(function () {
      toast('Could not load your events.', 'error');
    });
  }

  function openListDrawer(listId) {
    var listMeta = listsCache.find(function (l) { return String(l.id) === String(listId); });
    var listName = listMeta ? listMeta.name : 'Vendor list';
    var overlay = document.createElement('div');
    overlay.className = 'lnkup-crm-slide';
    overlay.setAttribute('role', 'dialog');
    overlay.innerHTML = '<div class="lnkup-crm-slide__panel lnkup-crm-slide__panel--wide">' +
      '<button type="button" class="lnkup-crm-slide__close" aria-label="Close">&times;</button>' +
      '<h2 class="lnkup-crm-slide__title">' + escapeHtml(listName) + '</h2>' +
      '<div class="lnkup-crm-list-drawer__toolbar">' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" id="lnkup-crm-list-invite-all">Invite all to event</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-crm-list-add-vendor">Add vendors</button>' +
      '</div>' +
      '<div id="lnkup-crm-list-drawer-body"><div class="lnkup-crm-skeleton"></div></div>' +
      '</div>';
    document.body.appendChild(overlay);

    function close() {
      closeSlideOver(overlay);
    }
    overlay.querySelector('.lnkup-crm-slide__close').addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        close();
      }
    });

    var body = overlay.querySelector('#lnkup-crm-list-drawer-body');
    var listVendors = [];

    function renderVendors(items) {
      listVendors = items || [];
      if (!items.length) {
        body.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">No vendors in this list</p><p class="lnkup-empty-state__desc">Add vendors from Discover Vendors.</p></div>';
        return;
      }
      var html = '<ul class="lnkup-crm-list-items">';
      items.forEach(function (v) {
        html += '<li class="lnkup-crm-list-item">';
        html += '<span><strong>' + escapeHtml(v.business_name) + '</strong>';
        if (v.city) {
          html += ' <span class="lnkup-muted">' + escapeHtml(v.city) + '</span>';
        }
        html += '</span>';
        html += '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-crm-list-remove" data-profile-id="' + v.profile_id + '">Remove</button>';
        html += '</li>';
      });
      html += '</ul>';
      body.innerHTML = html;
    }

    function refreshList() {
      crmFetchJson('/lists/' + listId + '/vendors').then(function (res) {
        renderVendors((res && res.items) ? res.items : []);
        loadLists();
      }).catch(function () {
        panelMessage(body, 'error', 'Could not load list', '');
      });
    }

    refreshList();

    overlay.querySelector('#lnkup-crm-list-invite-all').addEventListener('click', function () {
      if (!listVendors.length) {
        toast('Add vendors to this list first.', 'error');
        return;
      }
      close();
      openInviteModal({
        bulkVendors: listVendors.map(function (v) {
          return { vendor_user_id: v.vendor_user_id, business_name: v.business_name };
        })
      });
    });

    overlay.querySelector('#lnkup-crm-list-add-vendor').addEventListener('click', function () {
      body.innerHTML = '<input type="search" id="lnkup-crm-list-add-search" class="lnkup-crm-search" placeholder="Search vendors…" />';
      body.innerHTML += '<div id="lnkup-crm-list-add-results" class="lnkup-crm-list-add-results"></div>';
      var searchEl = body.querySelector('#lnkup-crm-list-add-search');
      var resultsEl = body.querySelector('#lnkup-crm-list-add-results');
      function searchVendors(q) {
        resultsEl.innerHTML = '<div class="lnkup-crm-skeleton"></div>';
        crmFetchJson('/vendors?scope=discover&view=compact&q=' + encodeURIComponent(q)).then(function (res) {
          var items = (res && res.items) ? res.items : [];
          if (!items.length) {
            resultsEl.innerHTML = '<p class="lnkup-muted">No vendors found.</p>';
            return;
          }
          var html = '';
          items.forEach(function (v) {
            html += '<button type="button" class="lnkup-crm-list-add-pick" data-profile-id="' + v.profile_id + '">';
            html += escapeHtml(v.business_name) + (v.city ? ' · ' + escapeHtml(v.city) : '');
            html += '</button>';
          });
          resultsEl.innerHTML = html;
        });
      }
      searchEl.addEventListener('input', debounce(function (e) {
        searchVendors(e.target.value);
      }, 300));
      searchVendors('');
      resultsEl.addEventListener('click', function (e) {
        var pick = e.target.closest('.lnkup-crm-list-add-pick');
        if (!pick) {
          return;
        }
        var profileId = pick.getAttribute('data-profile-id');
        crmFetch('/lists/' + listId + '/vendors', {
          method: 'POST',
          body: { profile_id: parseInt(profileId, 10) }
        }).then(function () {
          toast('Vendor added to list.');
          refreshList();
        });
      });
    });

    body.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.lnkup-crm-list-remove');
      if (!removeBtn) {
        return;
      }
      var profileId = removeBtn.getAttribute('data-profile-id');
      crmFetch('/lists/' + listId + '/vendors?profile_id=' + encodeURIComponent(profileId), {
        method: 'DELETE'
      }).then(function () {
        refreshList();
      });
    });
  }

  function openAddToListMenu(profileId, anchorBtn) {
    if (!listsCache.length) {
      loadLists().then(function () {
        openAddToListMenu(profileId, anchorBtn);
      });
      return;
    }
    document.querySelectorAll('.lnkup-crm-list-picker').forEach(function (el) {
      el.remove();
    });
    var menu = document.createElement('div');
    menu.className = 'lnkup-crm-list-picker';
    if (!listsCache.length) {
      menu.innerHTML = '<p class="lnkup-muted">Create a list first.</p>';
    } else {
      listsCache.forEach(function (list) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lnkup-crm-list-picker__item';
        btn.textContent = list.name;
        btn.addEventListener('click', function () {
          crmFetch('/lists/' + list.id + '/vendors', {
            method: 'POST',
            body: { profile_id: parseInt(profileId, 10) }
          }).then(function () {
            toast('Saved to ' + list.name + '.');
            menu.remove();
            loadLists();
          });
        });
        menu.appendChild(btn);
      });
    }
    anchorBtn.parentElement.appendChild(menu);
    setTimeout(function () {
      document.addEventListener('click', function handler(e) {
        if (!menu.contains(e.target) && e.target !== anchorBtn) {
          menu.remove();
          document.removeEventListener('click', handler);
        }
      });
    }, 0);
  }

  function bindInviteBanner() {
    var banner = document.getElementById('lnkup-invite-banner');
    if (!banner || !base) {
      return;
    }
    var token = banner.getAttribute('data-token');
    var acceptBtn = document.getElementById('lnkup-invite-accept');
    var declineBtn = document.getElementById('lnkup-invite-decline');
    function respond(accept) {
      crmFetch('/invites/respond', { method: 'POST', body: { token: token, accept: accept } }).then(function (res) {
        if (res && res.success) {
          if (accept && res.apply_url) {
            window.location.href = res.apply_url;
            return;
          }
          banner.innerHTML = '<p class="lnkup-alert lnkup-alert--success">' + (accept ? 'Invite accepted!' : 'Invite declined.') + '</p>';
        } else {
          toast((res && res.message) ? res.message : 'Could not update invite.', 'error');
        }
      }).catch(function () {
        // crmFetch already showed the error.
      });
    }
    if (acceptBtn) {
      acceptBtn.addEventListener('click', function () { respond(true); });
    }
    if (declineBtn) {
      declineBtn.addEventListener('click', function () { respond(false); });
    }
  }

  var rosterToken = 0;

  function applyRosterItem(item) {
    if (!item || !item.application_id) {
      return;
    }
    var card = document.querySelector('.lnkup-roster-card[data-app-id="' + item.application_id + '"]');
    if (!card) {
      return;
    }
    var status = card.querySelector('.lnkup-roster-card__status');
    if (status && item.ops_label) {
      status.textContent = item.ops_label;
    }
    var note = card.querySelector('.lnkup-roster-card__note');
    if (note) {
      note.textContent = item.message || '';
    }
    var activity = card.querySelector('.lnkup-roster-card__activity');
    if (activity && item.activity && item.activity.label) {
      activity.textContent = item.activity.label + (item.activity.created_at ? ' · ' + item.activity.created_at : '');
    }
    if (item.actions) {
      var actions = card.querySelector('.lnkup-roster-card__actions');
      var review = actions ? actions.querySelector('.lnkup-crm-review-open') : null;
      var labels = {
        checked_in: 'Checked In',
        no_show: 'No Show',
        late_arrival: 'Late Arrival',
        setup_started: 'Setup Started',
        setup_complete: 'Setup Complete',
        checked_out: 'Checked Out'
      };
      var bulk = {
        checked_in: 'check_in',
        no_show: 'no_show',
        late_arrival: 'late_arrival',
        setup_started: 'setup_started',
        setup_complete: 'setup_complete',
        checked_out: 'mark_complete'
      };
      var html = '';
      item.actions.forEach(function (action) {
        if (!bulk[action]) {
          return;
        }
        html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-roster-action" data-roster-action="' + bulk[action] + '" data-app-id="' + item.application_id + '">' + (labels[action] || action) + '</button>';
      });
      if (actions) {
        actions.innerHTML = html + (review ? review.outerHTML : '');
      }
    }
    var feed = document.getElementById('lnkup-ops-feed-list');
    if (feed && item.activity && item.activity.label) {
      var li = document.createElement('li');
      var strong = document.createElement('strong');
      var when = document.createElement('span');
      strong.textContent = item.activity.label;
      when.className = 'lnkup-muted';
      when.textContent = item.activity.created_at || '';
      li.appendChild(strong);
      li.appendChild(document.createTextNode(' '));
      li.appendChild(when);
      feed.insertBefore(li, feed.firstChild);
    }
  }

  function rosterRequest(action, eventId, ids, buttons) {
    var token = ++rosterToken;
    var scroll = window.scrollY;
    (buttons || []).forEach(function (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    });
    return crmFetch('/events/' + eventId + '/roster/bulk', {
      method: 'POST',
      body: { action: action, application_ids: ids }
    }).then(function (res) {
      if (token !== rosterToken) {
        return;
      }
      ((res && res.results) || []).forEach(applyRosterItem);
      window.scrollTo(0, scroll);
    }).catch(function (err) {
      if (token !== rosterToken) {
        return;
      }
      var message = (err && err.body && err.body.message) || (err && err.message) || 'Could not update the roster.';
      ids.forEach(function (id) {
        var card = document.querySelector('.lnkup-roster-card[data-app-id="' + id + '"]');
        var note = card && card.querySelector('.lnkup-roster-card__note');
        if (note) {
          note.textContent = message;
        }
      });
    }).then(function () {
      (buttons || []).forEach(function (btn) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      });
      syncRosterSelection();
    });
  }

  function selectedRosterIds() {
    var ids = [];
    document.querySelectorAll('.lnkup-roster-pick:checked').forEach(function (cb) {
      if (!cb.closest('.lnkup-roster-card[hidden]')) {
        ids.push(parseInt(cb.value, 10));
      }
    });
    return ids;
  }

  function syncRosterSelection() {
    var count = selectedRosterIds().length;
    var label = document.getElementById('lnkup-roster-selected-count');
    if (label) {
      label.textContent = count + ' selected';
    }
    document.querySelectorAll('[data-roster-bulk]').forEach(function (btn) {
      if (btn.getAttribute('aria-busy') !== 'true') {
        btn.disabled = count === 0;
      }
    });
    var selectAll = document.getElementById('lnkup-roster-select-all');
    if (selectAll) {
      var visible = document.querySelectorAll('.lnkup-roster-card:not([hidden]) .lnkup-roster-pick');
      selectAll.checked = visible.length > 0 && count === visible.length;
      selectAll.indeterminate = count > 0 && count < visible.length;
    }
  }

  function rosterBulk(action, eventId) {
    var ids = selectedRosterIds();
    if (!ids.length) {
      syncRosterSelection();
      return;
    }
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-roster-bulk="' + action + '"]'));
    rosterRequest(action, eventId, ids, buttons);
  }

  function rosterSingle(action, appId, button) {
    var rosterEl = document.getElementById('lnkup-event-roster');
    if (!rosterEl || !base) {
      return;
    }
    rosterRequest(action, rosterEl.getAttribute('data-event-id'), [parseInt(appId, 10)], button ? [button] : []);
  }

  function handleInviteDeepLink() {
    var params = new URLSearchParams(window.location.search);
    var eventId = params.get('invite_event');
    var oppId = params.get('opp');
    if (!eventId && !oppId) {
      return;
    }
    openInviteModal({
      prefillEventId: eventId ? parseInt(eventId, 10) : 0,
      prefillOpportunityId: oppId ? parseInt(oppId, 10) : 0
    });
  }

  function launchChecklistCopy() {
    return window.lnkupUiCopy || (window.lnkupAjax && window.lnkupAjax.copy) || {};
  }

  function statusLabel(status) {
    var copy = launchChecklistCopy();
    if (status === 'complete') {
      return copy.launch_checklist_status_complete || 'Complete';
    }
    if (status === 'in_progress') {
      return copy.launch_checklist_status_in_progress || 'In progress';
    }
    return copy.launch_checklist_status_not_started || 'Not started';
  }

  function statusClass(status) {
    return (status || 'not_started').replace(/_/g, '-');
  }

  function refreshLaunchChecklist() {
    var root = document.getElementById('lnkup-launch-checklist');
    if (!root || !base) {
      return Promise.resolve();
    }
    return crmFetchJson('/launch-checklist').then(function (data) {
      if (!data || !data.items) {
        return;
      }
      var progressEl = root.querySelector('[data-launch-checklist-progress]');
      var percentEl = root.querySelector('[data-launch-checklist-percent]');
      var bar = root.querySelector('.lnkup-launch-checklist__progress-bar');
      var copy = launchChecklistCopy();
      var progressText = (copy.launch_checklist_progress || '%1$d of %2$d complete')
        .replace('%1$d', String(data.completed || 0))
        .replace('%2$d', String(data.total || 0));
      if (progressEl) {
        progressEl.textContent = progressText;
      }
      if (percentEl) {
        percentEl.style.width = (data.percent || 0) + '%';
      }
      if (bar) {
        bar.setAttribute('aria-valuenow', String(data.percent || 0));
        bar.setAttribute('aria-label', progressText);
      }
      data.items.forEach(function (item) {
        var card = root.querySelector('[data-launch-checklist-item="' + item.key + '"]');
        if (!card) {
          return;
        }
        var status = item.status || 'not_started';
        card.setAttribute('data-status', status);
        card.className = 'lnkup-launch-checklist-card lnkup-launch-checklist-card--' + statusClass(status);
        var pill = card.querySelector('[data-launch-checklist-status]');
        if (pill) {
          pill.textContent = statusLabel(status);
        }
        var cta = card.querySelector('[data-launch-checklist-cta]');
        if (cta) {
          var isComplete = status === 'complete';
          cta.textContent = isComplete ? (copy.launch_checklist_view || 'View') : (item.cta_label || cta.textContent);
          cta.classList.toggle('lnkup-btn--ghost', isComplete);
          cta.classList.toggle('lnkup-btn--outline', !isComplete);
          if (item.cta_url) {
            cta.setAttribute('href', item.cta_url);
          }
        }
      });
      if (data.all_complete) {
        root.setAttribute('data-all-complete', '1');
        root.classList.add('lnkup-launch-checklist--complete');
      }
    }).catch(function (err) {
      console.error('[LNKup CRM] launch-checklist', err);
    });
  }

  function initLaunchChecklistToggle() {
    var root = document.getElementById('lnkup-launch-checklist');
    if (!root) {
      return;
    }
    var toggle = root.querySelector('[data-launch-checklist-toggle]');
    var body = root.querySelector('.lnkup-launch-checklist__body');
    if (!toggle || !body) {
      return;
    }
    var copy = (window.lnkupAjax && window.lnkupAjax.copy) || {};
    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      body.hidden = expanded;
      toggle.textContent = expanded
        ? (copy.launch_checklist_show || 'Show checklist')
        : (copy.launch_checklist_hide || 'Hide checklist');
    });
  }

  function postOrganizerAjax(action) {
    var body = new FormData();
    body.append('action', action);
    body.append('lnkup_nonce', ajax.nonce || '');
    return fetch(ajax.url || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); });
  }

  function closeOrganizerWelcome(markSeen) {
    var modal = document.getElementById('lnkup-organizer-welcome-modal');
    if (modal) {
      modal.hidden = true;
    }
    document.body.classList.remove('lnkup-modal-open');
    if (markSeen) {
      postOrganizerAjax('lnkup_organizer_welcome_seen').catch(function () { /* ignore */ });
    }
    maybeStartOrganizerTourAfterWelcome();
  }

  function openOrganizerWelcome() {
    var modal = document.getElementById('lnkup-organizer-welcome-modal');
    if (!modal) {
      return;
    }
    modal.hidden = false;
    document.body.classList.add('lnkup-modal-open');
  }

  function initOrganizerWelcome() {
    var modal = document.getElementById('lnkup-organizer-welcome-modal');
    if (!modal) {
      return;
    }
    openOrganizerWelcome();
    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-organizer-welcome-start]')) {
        e.preventDefault();
        closeOrganizerWelcome(true);
        var checklist = document.getElementById('lnkup-launch-checklist');
        if (checklist && checklist.scrollIntoView) {
          checklist.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return;
      }
      if (e.target.closest('[data-organizer-welcome-skip]') || e.target.closest('[data-organizer-welcome-close]')) {
        e.preventDefault();
        closeOrganizerWelcome(true);
      }
    });
  }

  function initOrganizerTutorialDismiss() {
    document.addEventListener('click', function (e) {
      var dismissBtn = e.target.closest('[data-organizer-tutorial-dismiss]');
      if (!dismissBtn) {
        return;
      }
      e.preventDefault();
      postOrganizerAjax('lnkup_dismiss_organizer_tutorial').then(function () {
        var checklist = document.getElementById('lnkup-launch-checklist');
        if (checklist) {
          checklist.hidden = true;
        }
        closeOrganizerWelcome(false);
      }).catch(function () { /* ignore */ });
    });
  }

  function initKnowledgeBaseReset() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-kb-show-checklist]');
      if (!btn || btn.getAttribute('data-kb-role') !== 'organizer') {
        return;
      }
      e.preventDefault();
      var target = btn.getAttribute('data-kb-checklist-url') || (window.location.pathname + '#overview');
      postOrganizerAjax('lnkup_reset_organizer_tutorial').then(function () {
        window.location.href = target;
      }).catch(function () {
        window.location.href = target;
      });
    });
  }

  function postTourAjax(action, role) {
    var body = new FormData();
    body.append('action', action);
    body.append('lnkup_nonce', ajax.nonce || '');
    body.append('role', role || 'organizer');
    return fetch(ajax.url || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); });
  }

  function initOrganizerDashboardTour() {
    var modal = document.getElementById('lnkup-organizer-tour-modal');
    if (!modal) {
      return;
    }
    var role = 'organizer';
    var slides = modal.querySelectorAll('[data-tour-slide]');
    var index = 0;
    var progress = modal.querySelector('[data-tour-progress]');
    var backBtn = modal.querySelector('[data-tour-back]');
    var nextBtn = modal.querySelector('[data-tour-next]');
    var doneBtn = modal.querySelector('[data-tour-done]');

    function renderProgress() {
      if (!progress) return;
      var dots = '';
      for (var i = 0; i < slides.length; i++) {
        dots += '<span class="lnkup-tour__dot' + (i === index ? ' is-active' : '') + '"></span>';
      }
      progress.innerHTML = dots;
    }

    function showSlide(i) {
      index = Math.max(0, Math.min(i, slides.length - 1));
      slides.forEach(function (slide, si) {
        slide.hidden = si !== index;
      });
      if (backBtn) backBtn.hidden = index === 0;
      var last = index >= slides.length - 1;
      if (nextBtn) nextBtn.hidden = last;
      if (doneBtn) doneBtn.hidden = !last;
      renderProgress();
    }

    function openTour() {
      modal.hidden = false;
      document.body.classList.add('lnkup-modal-open');
      showSlide(0);
    }

    function closeTour(markSeen) {
      modal.hidden = true;
      document.body.classList.remove('lnkup-modal-open');
      if (markSeen) {
        postTourAjax('lnkup_dashboard_tour_seen', role).catch(function () { /* ignore */ });
        modal.setAttribute('data-tour-auto-open', '0');
      }
    }

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-tour-skip]')) {
        e.preventDefault();
        closeTour(true);
        return;
      }
      if (e.target.closest('[data-tour-back]')) {
        e.preventDefault();
        showSlide(index - 1);
        return;
      }
      if (e.target.closest('[data-tour-next]')) {
        e.preventDefault();
        showSlide(index + 1);
        return;
      }
      if (e.target.closest('[data-tour-done]')) {
        e.preventDefault();
        closeTour(true);
      }
    });

    document.addEventListener('click', function (e) {
      var replay = e.target.closest('[data-kb-replay-tour]');
      if (!replay || replay.getAttribute('data-kb-role') !== 'organizer') {
        return;
      }
      e.preventDefault();
      postTourAjax('lnkup_reset_dashboard_tour', 'organizer').then(function () {
        modal.setAttribute('data-tour-auto-open', '1');
        openTour();
      }).catch(function () {
        openTour();
      });
    });

    window.lnkupOpenOrganizerTour = openTour;

    var welcome = document.getElementById('lnkup-organizer-welcome-modal');
    var shouldAuto = modal.getAttribute('data-tour-auto-open') === '1';
    if (shouldAuto && (!welcome || welcome.hidden)) {
      openTour();
    }
  }

  function maybeStartOrganizerTourAfterWelcome() {
    var tour = document.getElementById('lnkup-organizer-tour-modal');
    if (tour && tour.getAttribute('data-tour-auto-open') === '1' && window.lnkupOpenOrganizerTour) {
      window.lnkupOpenOrganizerTour();
    }
  }

  /**
   * Active CRM section: the legacy #hash wins, otherwise the server-rendered ?view= page.
   *
   * @returns {string}
   */
  function currentCrmSection() {
    var hash = (window.location.hash || '').replace(/^#/, '').split('&')[0].split('?')[0];
    if (hash) {
      return hash;
    }
    var root = document.getElementById('lnkup-organizer-crm');
    var active = root ? root.getAttribute('data-active-section') || '' : '';
    if (active) {
      return active;
    }
    return new URLSearchParams(window.location.search).get('view') || '';
  }

  document.addEventListener('DOMContentLoaded', function () {
    syncHashNav();
    window.addEventListener('hashchange', function () {
      syncHashNav();
      if (!document.getElementById('lnkup-organizer-crm')) {
        return;
      }
      var section = (window.location.hash || '').replace(/^#/, '').split('&')[0].split('?')[0];
      if (section === 'vendors') {
        loadDirectory();
      }
      if (section === 'lists') {
        loadLists();
      }
      if (section === 'invites') {
        loadInvites();
      }
    });

    if (document.getElementById('lnkup-organizer-crm')) {
      loadWidgets();
      initLaunchChecklistToggle();
      initOrganizerWelcome();
      initOrganizerTutorialDismiss();
      initKnowledgeBaseReset();
      initOrganizerDashboardTour();
      if (window.LNKupUI && typeof LNKupUI.animateKpiCards === 'function') {
        LNKupUI.animateKpiCards(document.getElementById('lnkup-crm-stats'));
      }
      var hash = currentCrmSection();
      if (hash === 'vendors') {
        loadDirectory();
      }
      if (hash === 'lists') {
        loadLists();
      }
      if (hash === 'invites') {
        loadInvites();
      }
      loadOutreachCampaigns();
      initBulkOutreachForm();
      handleInviteDeepLink();
      applyAppsDeepLink();
    }

    var directoryMore = document.getElementById('lnkup-crm-directory-more');
    if (directoryMore) {
      directoryMore.addEventListener('click', function () {
        var dir = document.getElementById('lnkup-crm-directory');
        if (dir) {
          dir.classList.add('lnkup-crm-directory--expanded');
        }
        directoryMore.hidden = true;
      });
    }

    var newListForm = document.getElementById('lnkup-crm-new-list-form');
    if (newListForm) {
      newListForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var nameInput = document.getElementById('lnkup-crm-new-list-name');
        var name = nameInput && nameInput.value.trim();
        if (!name) {
          return;
        }
        crmFetch('/lists', { method: 'POST', body: { name: name } }).then(function () {
          toast('List created.');
          if (nameInput) {
            nameInput.value = '';
          }
          loadLists();
        });
      });
    }

    document.addEventListener('click', function (e) {
      var rebook = e.target.closest('[data-rebook-vendors]');
      if (!rebook) {
        return;
      }
      var vendors = [];
      try {
        vendors = JSON.parse(rebook.getAttribute('data-rebook-vendors') || '[]');
      } catch (err) {
        vendors = [];
      }
      if (!vendors.length) {
        toast('No confirmed vendors to rebook from this event.', 'error');
        return;
      }
      openInviteModal({ bulkVendors: vendors });
    });

    var inviteFromPanel = document.getElementById('lnkup-crm-invite-from-panel');
    if (inviteFromPanel) {
      inviteFromPanel.addEventListener('click', function () {
        openInviteModal({});
      });
    }

    document.addEventListener('click', function (e) {
      var priorityTrigger = e.target.closest('[data-org-app-filter-trigger]');
      if (priorityTrigger) {
        e.preventDefault();
        window.location.assign(window.location.pathname + '?view=applications&filter=missing_docs');
      }
    });

    bindInviteBanner();

    document.querySelectorAll('[data-org-app-filter]').forEach(function (btn) {
      if (btn.tagName !== 'BUTTON' || !btn.classList.contains('lnkup-dash-tabs__btn')) {
        return;
      }
      btn.addEventListener('click', function () {
        document.querySelectorAll('.lnkup-organizer-app-filters .lnkup-dash-tabs__btn[data-org-app-filter]').forEach(function (b) {
          b.classList.remove('is-active');
        });
        btn.classList.add('is-active');
        var status = btn.getAttribute('data-org-app-filter') || 'all';
        filterApplications(status);
        setAppsHash(appFilterState.status || 'all', appFilterState.event || 'all');
      });
    });

    document.querySelectorAll('[data-org-app-event]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('[data-org-app-event]').forEach(function (b) {
          b.classList.remove('is-active');
        });
        btn.classList.add('is-active');
        var eventId = btn.getAttribute('data-org-app-event') || 'all';
        filterApplications(appFilterState.status || 'all', eventId);
        setAppsHash(appFilterState.status || 'all', appFilterState.event || 'all');
      });
    });

    var appEventSelect = document.getElementById('lnkup-org-app-event');
    if (appEventSelect) {
      appEventSelect.addEventListener('change', function () {
        var eventId = appEventSelect.value || 'all';
        document.querySelectorAll('[data-org-app-event]').forEach(function (b) {
          b.classList.toggle('is-active', b.getAttribute('data-org-app-event') === eventId);
        });
        filterApplications(appFilterState.status || 'all', eventId);
        setAppsHash(appFilterState.status || 'all', appFilterState.event || 'all');
      });
    }

    window.addEventListener('hashchange', function () {
      applyAppsDeepLink();
    });

    var moreFiltersBtn = document.querySelector('[data-org-app-filter-toggle]');
    var extraFilters = document.getElementById('lnkup-org-app-filters-extra');
    if (moreFiltersBtn && extraFilters) {
      moreFiltersBtn.addEventListener('click', function () {
        var expanded = moreFiltersBtn.getAttribute('aria-expanded') === 'true';
        moreFiltersBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        extraFilters.hidden = expanded;
      });
    }

    document.querySelectorAll('[data-org-app-layout]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var layout = btn.getAttribute('data-org-app-layout') || 'grid';
        document.querySelectorAll('[data-org-app-layout]').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        var grid = document.querySelector('.lnkup-crm-app-grid--v2');
        if (grid) {
          grid.classList.toggle('lnkup-crm-app-grid--list', layout === 'list');
        }
      });
    });

    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('lnkup-crm-compare-pick')) {
        updateCompareBar();
      }
    });

    var compareClear = document.getElementById('lnkup-crm-compare-clear');
    if (compareClear) {
      compareClear.addEventListener('click', function () {
        document.querySelectorAll('.lnkup-crm-compare-pick:checked').forEach(function (cb) {
          cb.checked = false;
        });
        updateCompareBar();
      });
    }

    updateCompareBar();

    var searchInput = document.getElementById('lnkup-crm-vendor-search');
    if (searchInput) {
      searchInput.addEventListener('input', debounce(function (e) {
        crmState.q = e.target.value;
        loadDirectory();
      }, 350));
    }

    var sortSelect = document.getElementById('lnkup-crm-vendor-sort');
    if (sortSelect) {
      sortSelect.addEventListener('change', function (e) {
        crmState.sort = e.target.value;
        loadDirectory();
      });
    }

    var cityInput = document.getElementById('lnkup-crm-vendor-city');
    if (cityInput) {
      cityInput.addEventListener('input', debounce(function (e) {
        crmState.city = e.target.value.trim();
        loadDirectory();
      }, 350));
    }

    var categorySelect = document.getElementById('lnkup-crm-vendor-category');
    if (categorySelect) {
      categorySelect.addEventListener('change', function (e) {
        crmState.category = e.target.value;
        loadDirectory();
      });
    }

    var verifiedInput = document.getElementById('lnkup-crm-vendor-verified');
    if (verifiedInput) {
      verifiedInput.addEventListener('change', function (e) {
        crmState.verified = e.target.checked ? '1' : '';
        loadDirectory();
      });
    }

    document.addEventListener('click', function (e) {
      var smartBtn = e.target.closest('.lnkup-smart-list');
      if (smartBtn) {
        var appFilter = smartBtn.getAttribute('data-org-app-filter');
        if (appFilter) {
          window.location.assign(window.location.pathname + '?view=applications&filter=' + encodeURIComponent(appFilter));
          return;
        }
        var scope = smartBtn.getAttribute('data-crm-scope');
        var sort = smartBtn.getAttribute('data-crm-sort');
        if (scope) {
          crmState.scope = scope;
          document.querySelectorAll('[data-crm-scope]').forEach(function (b) {
            if (isScopeTab(b)) {
              var isActive = b.getAttribute('data-crm-scope') === scope;
              b.classList.toggle('is-active', isActive);
              b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            }
          });
        }
        if (sort) {
          crmState.sort = sort;
          var sortEl = document.getElementById('lnkup-crm-vendor-sort');
          if (sortEl) {
            sortEl.value = sort;
          }
        }
        loadDirectory();
        return;
      }

      var copyBtn = e.target.closest('.lnkup-crm-copy-invite');
      if (copyBtn && copyBtn.getAttribute('data-link')) {
        var link = copyBtn.getAttribute('data-link');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(link).then(function () {
            copyBtn.textContent = 'Copied!';
            toast('Invite link copied.');
          });
        }
        return;
      }

      var resendBtn = e.target.closest('.lnkup-crm-resend-invite');
      if (resendBtn) {
        var resendLink = resendBtn.getAttribute('data-link');
        if (resendLink && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(resendLink).then(function () {
            resendBtn.textContent = 'Link copied';
            toast('Invite link copied.');
          });
        }
        return;
      }

      var scopeBtn = e.target.closest('[data-crm-scope]');
      if (scopeBtn && isScopeTab(scopeBtn)) {
        document.querySelectorAll('[data-crm-scope]').forEach(function (b) {
          if (isScopeTab(b)) {
            b.classList.remove('is-active');
            b.setAttribute('aria-pressed', 'false');
          }
        });
        scopeBtn.classList.add('is-active');
        scopeBtn.setAttribute('aria-pressed', 'true');
        crmState.scope = scopeBtn.getAttribute('data-crm-scope');
        loadDirectory();
        return;
      }

      var viewBtn = e.target.closest('[data-crm-view]');
      if (viewBtn) {
        document.querySelectorAll('[data-crm-view]').forEach(function (b) { b.classList.remove('is-active'); });
        viewBtn.classList.add('is-active');
        crmState.view = viewBtn.getAttribute('data-crm-view');
        loadDirectory();
        return;
      }

      var listCard = e.target.closest('.lnkup-crm-list-card--clickable');
      if (listCard && !e.target.closest('.lnkup-crm-list-delete')) {
        openListDrawer(listCard.getAttribute('data-list-id'));
        return;
      }

      var delList = e.target.closest('.lnkup-crm-list-delete');
      if (delList) {
        e.stopPropagation();
        var listId = delList.getAttribute('data-list-id');
        if (!delList.classList.contains('is-armed')) {
          delList.setAttribute('data-label', delList.textContent);
          delList.classList.add('is-armed');
          delList.textContent = 'Confirm delete';
          window.setTimeout(function () {
            delList.classList.remove('is-armed');
            delList.textContent = delList.getAttribute('data-label') || 'Delete';
          }, 4000);
          return;
        }
        delList.disabled = true;
        crmFetch('/lists/' + listId, { method: 'DELETE' })
          .then(function () { toast('List deleted.'); loadLists(); })
          .catch(function () { delList.disabled = false; });
        return;
      }

      var favBtn = e.target.closest('.lnkup-crm-fav');
      if (favBtn) {
        var pid = favBtn.getAttribute('data-profile-id');
        crmFetch('/vendors/' + pid + '/favorite', { method: 'POST' }).then(function (res) {
          favBtn.textContent = (res && res.is_favorite) ? '★ Favorite' : '☆ Favorite';
          toast((res && res.is_favorite) ? 'Added to favorites.' : 'Removed from favorites.');
        });
        return;
      }

      var addListBtn = e.target.closest('.lnkup-crm-add-to-list');
      if (addListBtn) {
        e.preventDefault();
        openAddToListMenu(addListBtn.getAttribute('data-profile-id'), addListBtn);
        return;
      }

      var prefBtn = e.target.closest('.lnkup-crm-pref');
      if (prefBtn) {
        var profileId = prefBtn.getAttribute('data-profile-id') || prefBtn.closest('[data-profile-id]') && prefBtn.closest('[data-profile-id]').getAttribute('data-profile-id');
        if (!profileId) {
          profileId = document.getElementById('lnkup-crm-profile') && document.getElementById('lnkup-crm-profile').getAttribute('data-profile-id');
        }
        if (profileId) {
          crmFetch('/vendors/' + profileId + '/preferred', { method: 'POST' }).then(function () {
            prefBtn.classList.toggle('is-active');
          });
        }
        return;
      }

      var inviteBtn = e.target.closest('.lnkup-crm-invite');
      if (inviteBtn) {
        openInviteModal({
          vendorUserId: inviteBtn.getAttribute('data-vendor-id'),
          vendorName: inviteBtn.getAttribute('data-vendor-name') || '',
          profileId: inviteBtn.getAttribute('data-profile-id') || ''
        });
        return;
      }

      var compareOpen = e.target.closest('#lnkup-crm-compare-open');
      if (compareOpen) {
        var ids = [];
        document.querySelectorAll('.lnkup-crm-compare-pick:checked').forEach(function (cb) {
          ids.push(cb.value);
        });
        openCompare(ids);
        return;
      }

      var rosterAction = e.target.closest('.lnkup-roster-action, .lnkup-crm-checkin, .lnkup-crm-noshow, .lnkup-crm-late');
      if (rosterAction) {
        var rosterName = rosterAction.getAttribute('data-roster-action');
        if (!rosterName && rosterAction.classList.contains('lnkup-crm-checkin')) {
          rosterName = 'check_in';
        } else if (!rosterName && rosterAction.classList.contains('lnkup-crm-noshow')) {
          rosterName = 'no_show';
        } else if (!rosterName && rosterAction.classList.contains('lnkup-crm-late')) {
          rosterName = 'late_arrival';
        }
        rosterSingle(rosterName, rosterAction.getAttribute('data-app-id'), rosterAction);
        return;
      }

      var rosterFilter = e.target.closest('[data-roster-filter]');
      if (rosterFilter && document.getElementById('lnkup-event-roster')) {
        var filterKey = rosterFilter.getAttribute('data-roster-filter') || '';
        document.querySelectorAll('[data-roster-filter]').forEach(function (btn) {
          btn.classList.toggle('is-active', btn === rosterFilter);
          btn.setAttribute('aria-pressed', btn === rosterFilter ? 'true' : 'false');
        });
        document.querySelectorAll('.lnkup-roster-card').forEach(function (card) {
          var groups = (card.getAttribute('data-groups') || '').split(' ');
          card.hidden = filterKey !== '' && groups.indexOf(filterKey) === -1;
        });
        syncRosterSelection();
        return;
      }

      var bulkBtn = e.target.closest('[data-roster-bulk]');
      if (bulkBtn) {
        var rosterEl = document.getElementById('lnkup-event-roster');
        if (rosterEl) {
          rosterBulk(bulkBtn.getAttribute('data-roster-bulk'), rosterEl.getAttribute('data-event-id'));
        }
        return;
      }
    });

    var selectAll = document.getElementById('lnkup-roster-select-all');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        document.querySelectorAll('.lnkup-roster-card:not([hidden]) .lnkup-roster-pick').forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
        syncRosterSelection();
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target.classList && e.target.classList.contains('lnkup-roster-pick')) {
        syncRosterSelection();
      }
    });
    if (document.getElementById('lnkup-event-roster')) {
      syncRosterSelection();
    }

    var noteForm = document.getElementById('lnkup-crm-note-form');
    if (noteForm) {
      noteForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var profileEl = document.getElementById('lnkup-crm-profile');
        var profileId = profileEl && profileEl.getAttribute('data-profile-id');
        var input = document.getElementById('lnkup-crm-note-input');
        if (!profileId || !input || !input.value.trim()) {
          return;
        }
        var content = input.value.trim();
        crmFetch('/vendors/' + profileId + '/notes', {
          method: 'POST',
          body: { content: content }
        }).then(function (res) {
          if (res && res.id) {
            var list = document.getElementById('lnkup-crm-notes-list');
            if (list) {
              list.insertBefore(buildNoteItem(res.id, content, new Date().toLocaleString()), list.firstChild);
            }
            input.value = '';
          }
        }).catch(function (err) {
          toast((err && err.message) || 'Could not save the note.', 'error');
        });
      });
    }

    var notesList = document.getElementById('lnkup-crm-notes-list');
    if (notesList) {
      notesList.addEventListener('click', function (e) {
        var item = e.target.closest('[data-note-id]');
        if (!item) {
          return;
        }
        var noteId = item.getAttribute('data-note-id');
        if (e.target.closest('[data-note-delete]')) {
          var delBtn = e.target.closest('[data-note-delete]');
          if (!delBtn.classList.contains('is-armed')) {
            delBtn.classList.add('is-armed');
            delBtn.textContent = 'Confirm delete';
            window.setTimeout(function () {
              delBtn.classList.remove('is-armed');
              delBtn.textContent = 'Delete';
            }, 4000);
            return;
          }
          delBtn.disabled = true;
          crmFetch('/notes/' + noteId, { method: 'DELETE' }).then(function () {
            item.remove();
          }).catch(function (err) {
            delBtn.disabled = false;
            toast((err && err.message) || 'Could not delete the note.', 'error');
          });
          return;
        }
        if (e.target.closest('[data-note-edit]')) {
          var body = item.querySelector('[data-note-body]');
          if (!body || item.querySelector('textarea')) {
            return;
          }
          var editor = document.createElement('textarea');
          editor.rows = 3;
          editor.value = body.textContent;
          editor.setAttribute('aria-label', 'Edit note');
          var save = document.createElement('button');
          save.type = 'button';
          save.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--primary';
          save.textContent = 'Save';
          var cancel = document.createElement('button');
          cancel.type = 'button';
          cancel.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost';
          cancel.textContent = 'Cancel';
          var wrap = document.createElement('div');
          wrap.className = 'lnkup-crm-note__editor';
          wrap.appendChild(editor);
          wrap.appendChild(save);
          wrap.appendChild(cancel);
          body.hidden = true;
          body.parentNode.insertBefore(wrap, body.nextSibling);
          editor.focus();
          cancel.addEventListener('click', function () {
            wrap.remove();
            body.hidden = false;
          });
          save.addEventListener('click', function () {
            var next = editor.value.trim();
            if (!next) {
              editor.focus();
              return;
            }
            save.disabled = true;
            crmFetch('/notes/' + noteId, { method: 'PUT', body: { content: next } }).then(function () {
              body.textContent = next;
              wrap.remove();
              body.hidden = false;
            }).catch(function (err) {
              save.disabled = false;
              toast((err && err.message) || 'Could not update the note.', 'error');
            });
          });
        }
      });
    }

    var tagsRoot = document.querySelector('[data-crm-tags]');
    var crmProfileEl = document.getElementById('lnkup-crm-profile');
    if (tagsRoot && crmProfileEl) {
      var tagProfileId = crmProfileEl.getAttribute('data-profile-id');
      var tagList = tagsRoot.querySelector('[data-crm-tag-list]');
      var tagForm = tagsRoot.querySelector('[data-crm-tag-form]');
      var tagStatus = tagsRoot.querySelector('[data-crm-tag-status]');
      tagsRoot.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-crm-tag-remove]');
        if (!remove) {
          return;
        }
        var slug = remove.getAttribute('data-crm-tag-remove');
        remove.disabled = true;
        crmFetch('/vendors/' + tagProfileId + '/tags?slug=' + encodeURIComponent(slug), { method: 'DELETE' }).then(function () {
          var chip = remove.closest('li');
          if (chip) {
            chip.remove();
          }
          if (tagStatus) {
            tagStatus.textContent = 'Tag removed.';
          }
        }).catch(function (err) {
          remove.disabled = false;
          if (tagStatus) {
            tagStatus.textContent = (err && err.message) || 'Could not remove the tag.';
          }
        });
      });
      if (tagForm) {
        tagForm.addEventListener('submit', function (e) {
          e.preventDefault();
          var input = tagForm.querySelector('input');
          var label = input ? input.value.trim() : '';
          if (!label) {
            return;
          }
          var slug = '';
          tagForm.querySelectorAll('datalist option').forEach(function (opt) {
            if (opt.value.toLowerCase() === label.toLowerCase()) {
              slug = opt.getAttribute('data-slug') || '';
              label = opt.value;
            }
          });
          if (!slug) {
            slug = label.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
          }
          if (!slug || tagList.querySelector('[data-tag-slug="' + slug + '"]')) {
            input.value = '';
            return;
          }
          crmFetch('/vendors/' + tagProfileId + '/tags', { method: 'POST', body: { slug: slug, label: label } }).then(function () {
            var li = document.createElement('li');
            li.className = 'lnkup-badge lnkup-badge--secondary';
            li.setAttribute('data-tag-slug', slug);
            var text = document.createElement('span');
            text.textContent = label;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lnkup-crm-tags__remove';
            btn.setAttribute('data-crm-tag-remove', slug);
            btn.setAttribute('aria-label', 'Remove tag ' + label);
            btn.textContent = '\u00d7';
            li.appendChild(text);
            li.appendChild(btn);
            tagList.appendChild(li);
            input.value = '';
            if (tagStatus) {
              tagStatus.textContent = 'Tag added.';
            }
          }).catch(function (err) {
            if (tagStatus) {
              tagStatus.textContent = (err && err.message) || 'Could not add the tag.';
            }
          });
        });
      }
    }

    var timelineBtn = document.querySelector('[data-crm-timeline-full]');
    if (timelineBtn && crmProfileEl) {
      timelineBtn.addEventListener('click', function () {
        var container = crmProfileEl.querySelector('.lnkup-crm-timeline');
        if (!container) {
          return;
        }
        timelineBtn.disabled = true;
        timelineBtn.textContent = 'Loading…';
        crmFetch('/vendors/' + crmProfileEl.getAttribute('data-profile-id') + '/timeline').then(function (res) {
          var items = (res && res.items) || [];
          var list = document.createElement('ul');
          list.className = 'lnkup-crm-timeline__list';
          items.forEach(function (item) {
            var li = document.createElement('li');
            var strong = document.createElement('strong');
            strong.textContent = item.label || '';
            li.appendChild(strong);
            if (item.event_title) {
              li.appendChild(document.createTextNode(' — ' + item.event_title));
            }
            if (item.created_at) {
              li.appendChild(document.createElement('br'));
              var when = document.createElement('span');
              when.className = 'lnkup-muted';
              when.textContent = item.created_at;
              li.appendChild(when);
            }
            list.appendChild(li);
          });
          container.innerHTML = '';
          container.appendChild(list);
          timelineBtn.parentNode.remove();
        }).catch(function () {
          timelineBtn.disabled = false;
          timelineBtn.textContent = 'Could not load history. Try again';
        });
      });
    }

    var profileRoot = document.getElementById('lnkup-crm-profile');
    if (profileRoot) {
      var pref = profileRoot.querySelector('.lnkup-crm-pref');
      if (pref && !pref.getAttribute('data-profile-id')) {
        pref.setAttribute('data-profile-id', profileRoot.getAttribute('data-profile-id'));
      }
    }

    var dashSearchForm = document.getElementById('lnkup-dashboard-search-form');
    var dashSearchInput = document.getElementById('lnkup-dashboard-crm-search');
    if (dashSearchForm && dashSearchInput) {
      dashSearchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        crmState.q = dashSearchInput.value.trim();
        var vendorSearch = document.getElementById('lnkup-crm-vendor-search');
        if (vendorSearch) {
          vendorSearch.value = crmState.q;
        }
        var searchUrl = new URL(window.location.href);
        searchUrl.hash = '';
        searchUrl.searchParams.set('view', 'vendors');
        if (crmState.q) {
          searchUrl.searchParams.set('crm_q', crmState.q);
        }
        window.location.assign(searchUrl.toString());
      });
      var params = new URLSearchParams(window.location.search);
      if (params.get('crm_q')) {
        dashSearchInput.value = params.get('crm_q');
        crmState.q = params.get('crm_q');
        var crmVendorSearch = document.getElementById('lnkup-crm-vendor-search');
        if (crmVendorSearch) {
          crmVendorSearch.value = crmState.q;
        }
      }
    }
  });

  window.lnkupCrmLoadDirectory = loadDirectory;
  window.lnkupCrmLoadLists = loadLists;
  window.lnkupCrmLoadInvites = loadInvites;
  window.lnkupCrmSetScope = function (scope) {
    crmState.scope = scope || 'discover';
  };
  window.lnkupOpenInviteModal = openInviteModal;
  window.lnkupCrmInvalidateInviteOptions = invalidateInviteOptions;
  window.lnkupRefreshLaunchChecklist = refreshLaunchChecklist;
})();
