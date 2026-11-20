(function () {
  var cfg = window.lnkupAjax || {};
  var panel = document.getElementById('lnkup-saved-searches-dashboard');
  if (!panel) {
    return;
  }

  function api(path, opts) {
    if (!cfg.restUrl) return Promise.reject(new Error('REST unavailable'));
    opts = opts || {};
    return fetch(cfg.restUrl + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': cfg.restNonce || '',
        'Content-Type': 'application/json'
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().then(function (body) {
        if (!r.ok || (body && body.code)) {
          var err = new Error((body && body.message) || 'Request failed');
          err.status = r.status;
          throw err;
        }
        return body;
      });
    });
  }

  function formatDate(str) {
    if (!str) return '—';
    try {
      return new Date(str.replace(' ', 'T')).toLocaleDateString();
    } catch (e) {
      return str;
    }
  }

  function restoreUrl(filters) {
    var payload = btoa(JSON.stringify(filters || {}));
    var base = cfg.opportunitiesUrl || '/opportunities/';
    return base + (base.indexOf('?') > -1 ? '&' : '?') + 'lnkup_restore=' + encodeURIComponent(payload);
  }

  function uiCopy(key, fallback) {
    if (window.LNKupUI && LNKupUI.t) {
      return LNKupUI.t(key, fallback);
    }
    var copy = window.lnkupUiCopy || {};
    return copy[key] || fallback || key;
  }

  function renderEmpty() {
    if (window.LNKupUI && LNKupUI.panelEmpty) {
      LNKupUI.panelEmpty(panel, {
        title: 'Save searches for the opportunities you want',
        desc: uiCopy('empty_saved_searches', 'Save your favorite filters and LNKup will surface matching events.'),
        ctaLabel: 'Find events',
        ctaHref: cfg.opportunitiesUrl || '/opportunities/'
      });
      return;
    }
    panel.innerHTML = '<div class="lnkup-empty-state" data-testid="lnkup-saved-searches-empty">' +
      '<p class="lnkup-empty-state__title">Save searches for the opportunities you want</p>' +
      '<p class="lnkup-empty-state__desc">' + escapeHtml(uiCopy('empty_saved_searches', 'Save your favorite filters and LNKup will surface matching events.')) + '</p>' +
      '<a href="' + (cfg.opportunitiesUrl || '/opportunities/') + '" class="lnkup-btn lnkup-btn--primary">Find events</a>' +
      '</div>';
  }

  function renderCards(searches) {
    if (!searches.length) {
      renderEmpty();
      return;
    }
    panel.innerHTML = '<div class="lnkup-saved-searches-list"></div>';
    var list = panel.querySelector('.lnkup-saved-searches-list');
    searches.forEach(function (search) {
      var card = document.createElement('article');
      card.className = 'lnkup-saved-search-card';
      card.setAttribute('data-testid', 'lnkup-saved-search-card');
      card.setAttribute('data-search-id', search.id);

      var chips = (search.filter_summary || []).map(function (c) {
        return '<span class="lnkup-badge lnkup-badge--outline">' + escapeHtml(c.label) + '</span>';
      }).join('');

      var alertLabel = search.is_paused || !search.alert_enabled
        ? 'Alerts paused'
        : ('Alerts: ' + (search.frequency || 'daily'));

      card.innerHTML =
        '<div class="lnkup-saved-search-card__head">' +
          '<h3 class="lnkup-saved-search-card__title">' + escapeHtml(search.name) + '</h3>' +
          '<span class="lnkup-badge ' + (search.is_paused ? 'lnkup-badge--secondary' : 'lnkup-badge--primary') + '">' + escapeHtml(alertLabel) + '</span>' +
        '</div>' +
        '<div class="lnkup-saved-search-card__chips">' + (chips || '<span class="lnkup-muted">All new opportunities</span>') + '</div>' +
        '<p class="lnkup-saved-search-card__meta lnkup-muted">Last checked: ' + escapeHtml(formatDate(search.last_checked_at)) +
          (search.new_match_count ? ' · <strong>' + search.new_match_count + ' new</strong>' : '') + '</p>' +
        '<div class="lnkup-saved-search-card__actions">' +
          '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-action="view">View matches</button>' +
          '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-action="pause">' + (search.is_paused ? 'Resume alerts' : 'Pause alerts') + '</button>' +
          '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-action="delete">Delete</button>' +
        '</div>';

      card.querySelector('[data-action="view"]').addEventListener('click', function () {
        api('/saved-searches/' + search.id + '/run', { method: 'POST', body: { per_page: 24 } }).then(function () {
          window.location.href = restoreUrl(search.filters);
        }).catch(function () {
          window.location.href = restoreUrl(search.filters);
        });
        if (cfg.restUrl) {
          fetch(cfg.restUrl + '/track/event', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.restNonce || '', 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_type: 'saved_search_match_clicked', data: { search_id: search.id } })
          }).catch(function () {});
        }
      });

      card.querySelector('[data-action="pause"]').addEventListener('click', function () {
        var paused = !search.is_paused;
        api('/saved-searches/' + search.id, {
          method: 'PATCH',
          body: {
            status: paused ? 'paused' : 'active',
            alert_enabled: !paused
          }
        }).then(refresh);
      });

      card.querySelector('[data-action="delete"]').addEventListener('click', function () {
        if (!window.confirm('Delete this saved search?')) return;
        api('/saved-searches/' + search.id, { method: 'DELETE' }).then(refresh);
      });

      list.appendChild(card);
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function refresh() {
    if (window.LNKupUI && LNKupUI.panelSkeleton) {
      LNKupUI.panelSkeleton(panel, 2);
    } else {
      panel.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card" aria-hidden="true"></div>';
    }
    return api('/saved-searches').then(function (res) {
      var rows = Array.isArray(res) ? res : (res && res.searches ? res.searches : []);
      renderCards(rows);
    }).catch(function () {
      if (window.LNKupUI && LNKupUI.panelError) {
        LNKupUI.panelError(panel, {
          title: uiCopy('error_load_section', 'We could not load this section.'),
          onRetry: refresh
        });
        return;
      }
      panel.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml(uiCopy('error_load_section', 'We could not load this section.')) + '</p>';
    });
  }

  window.lnkupSavedSearches = { refresh: refresh };
  refresh();
})();
