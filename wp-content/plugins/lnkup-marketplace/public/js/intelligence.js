(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.intelligenceRestUrl || '';
  var nonce = ajax.restNonce || '';

  function t(key, fallback) {
    if (window.LNKupUI && LNKupUI.t) {
      return LNKupUI.t(key, fallback);
    }
    var copy = window.lnkupUiCopy || {};
    return copy[key] || fallback || key;
  }

  /**
   * Intelligence REST fetch helper.
   */
  function intelFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
          return r.text().then(function (text) {
            console.error('[LNKup Intel] Expected JSON:', text.slice(0, 300));
            throw new Error('Invalid server response.');
          });
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
   * AJAX fallback when REST is unavailable.
   */
  function intelAjaxFetch() {
    if (!ajax.url) {
      return Promise.reject(new Error('AJAX unavailable'));
    }
    var fd = new FormData();
    fd.append('action', 'lnkup_event_intelligence');
    fd.append('lnkup_nonce', ajax.nonce || '');
    return fetch(ajax.url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || 'Intelligence request failed');
        }
        return res.data;
      });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function funnelHtml(stages, labels) {
    if (!stages) {
      return '';
    }
    var html = '<div class="lnkup-intel-funnel">';
    labels.forEach(function (pair) {
      var key = pair[0];
      var label = pair[1];
      var val = stages[key];
      if (val == null) {
        return;
      }
      var max = Math.max.apply(null, labels.map(function (p) { return Number(stages[p[0]] || 0); }).concat([1]));
      var pct = Math.round((Number(val) / max) * 100);
      html += '<div class="lnkup-intel-funnel__row"><span class="lnkup-intel-funnel__label">' + escapeHtml(label) + '</span>';
      html += '<div class="lnkup-intel-funnel__bar lnkup-chart"><span class="lnkup-chart__bar' + (pct >= 90 ? ' is-latest' : '') + '" style="height:' + Math.max(8, pct) + '%"></span></div>';
      html += '<span class="lnkup-intel-funnel__value">' + escapeHtml(String(val)) + '</span></div>';
    });
    html += '</div>';
    return html;
  }

  function intelKpiCard(label, value) {
    return '<div class="lnkup-stat-card lnkup-intel-kpi"><div class="lnkup-stat-card__content"><p class="lnkup-stat-card__label">' + escapeHtml(label) + '</p><p class="lnkup-stat-card__value">' + escapeHtml(String(value)) + '</p></div></div>';
  }

  function scoreBlock(score) {
    if (!score) {
      return '';
    }
    var cls = score.score >= 80 ? 'excellent' : (score.score >= 65 ? 'healthy' : (score.score >= 50 ? 'attention' : 'risk'));
    return '<div class="lnkup-intel-score lnkup-intel-score--' + cls + '"><span class="lnkup-intel-score__value">' + escapeHtml(String(score.score)) + '</span><span class="lnkup-intel-score__label">' + escapeHtml(score.label || '') + '</span></div>';
  }

  function insightCard(text, icon) {
    var iconHtml = icon || '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';
    return '<article class="lnkup-intel-insight-card"><span class="lnkup-intel-insight-card__icon" aria-hidden="true">' + iconHtml + '</span><p>' + escapeHtml(text) + '</p></article>';
  }

  function rankedEventCard(ev, rank) {
    var title = escapeHtml(ev.title || '');
    var score = ev.score != null ? escapeHtml(String(ev.score)) : '';
    var label = ev.label ? escapeHtml(ev.label) : '';
    var apps = ev.applications != null ? escapeHtml(String(ev.applications)) + ' apps' : '';
    var inner = '<div class="lnkup-intel-ranked__main"><span class="lnkup-intel-ranked__rank">' + rank + '</span><div><strong>' + title + '</strong>';
    if (apps) {
      inner += '<p class="lnkup-muted">' + apps + '</p>';
    }
    inner += '</div></div>';
    if (score) {
      inner += '<span class="lnkup-intel-ranked__score">' + score + (label ? ' <small>' + label + '</small>' : '') + '</span>';
    }
    var tag = ev.url ? 'a' : 'div';
    var href = ev.url ? ' href="' + escapeHtml(ev.url) + '"' : '';
    return '<' + tag + ' class="lnkup-intel-ranked-card"' + href + '>' + inner + '</' + tag + '>';
  }

  function intelDashboardSkeleton() {
    return '<div class="lnkup-intel-dashboard lnkup-intel-dashboard--loading">' +
      '<div class="lnkup-intel-hero-skeleton lnkup-intel-skeleton"></div>' +
      '<div class="lnkup-intel-kpi-row">' +
      '<div class="lnkup-intel-skeleton"></div><div class="lnkup-intel-skeleton"></div>' +
      '<div class="lnkup-intel-skeleton"></div><div class="lnkup-intel-skeleton"></div>' +
      '</div>' +
      '<div class="lnkup-intel-skeleton lnkup-intel-skeleton--wide"></div>' +
      '</div>';
  }

  /**
   * Render organizer intelligence panel from API payload.
   */
  function renderOrganizerIntel(el, data) {
    if (!data || data.code) {
      return false;
    }
    var html = '<div class="lnkup-intel-dashboard">';
    var kpis = data.kpis || [];
    var healthScore = data.health && data.health.score != null ? data.health : null;

    // The Intelligence page already shows the score in its server-rendered summary.
    var ssrSummary = !!document.querySelector('#lnkup-organizer-intel-summary[data-ssr="1"]');
    if (healthScore && !ssrSummary) {
      html += '<section class="lnkup-intel-hero">';
      html += '<div class="lnkup-intel-hero__score">' + scoreBlock({ score: healthScore.score, label: healthScore.label }) + '</div>';
      html += '<div class="lnkup-intel-hero__copy"><h3>Portfolio health</h3><p class="lnkup-muted">' + escapeHtml(healthScore.label || 'Overall event performance') + '</p></div>';
      html += '</section>';
    }

    var kpiItems = kpis.length ? kpis.slice(0, 4) : [];
    if (!kpiItems.length && data.health && data.health.kpis) {
      Object.keys(data.health.kpis).slice(0, 4).forEach(function (k) {
        kpiItems.push({ label: k.replace(/_/g, ' '), value: data.health.kpis[k] });
      });
    }
    if (kpiItems.length) {
      html += '<section class="lnkup-intel-kpi-row">';
      kpiItems.forEach(function (k) {
        html += intelKpiCard(k.label || k.key || '', k.value != null ? k.value : '—');
      });
      html += '</section>';
    }

    if (data.insights && data.insights.length) {
      html += '<section class="lnkup-intel-section"><h3>Insights</h3><div class="lnkup-intel-insight-grid">';
      data.insights.forEach(function (line) {
        html += insightCard(line);
      });
      html += '</div></section>';
    }

    if (data.warnings && data.warnings.length) {
      html += '<section class="lnkup-intel-section"><h3>Needs attention</h3><ul class="lnkup-intel-attention-list" data-testid="lnkup-intel-attention">';
      data.warnings.forEach(function (w) {
        html += '<li class="lnkup-intel-attention-list__item lnkup-intel-attention-list__item--' + escapeHtml(w.level || 'medium') + '">' + escapeHtml(w.message || '') + '</li>';
      });
      html += '</ul></section>';
    }

    if (data.summary && data.summary.application_funnel) {
      html += '<section class="lnkup-intel-section"><h3>Application funnel</h3>';
      html += funnelHtml(data.summary.application_funnel, [
        ['views', 'Views'],
        ['submitted', 'Submitted'],
        ['approved', 'Approved'],
        ['paid', 'Paid']
      ]);
      html += '</section>';
    } else if (data.application_funnel) {
      html += '<section class="lnkup-intel-section"><h3>Application funnel</h3>';
      html += funnelHtml(data.application_funnel, [
        ['views', 'Views'],
        ['submitted', 'Submitted'],
        ['approved', 'Approved'],
        ['paid', 'Paid']
      ]);
      html += '</section>';
    }

    if (data.summary && data.summary.recommended_actions && data.summary.recommended_actions.length) {
      var groups = [];
      var byKey = {};
      data.summary.recommended_actions.forEach(function (a) {
        var key = (a.title || '') + '|' + (a.reason || '');
        if (!byKey[key]) {
          byKey[key] = { title: a.title, reason: a.reason, events: [] };
          groups.push(byKey[key]);
        }
        byKey[key].events.push({ title: a.event_title || '', cta: a.cta || null });
      });
      html += '<section class="lnkup-intel-section"><h3>Recommended actions</h3><div class="lnkup-intel-action-grid" data-testid="lnkup-intel-actions">';
      groups.forEach(function (g) {
        html += '<article class="lnkup-intel-action-card"><strong>' + escapeHtml(g.title || '') + '</strong><p>' + escapeHtml(g.reason || '') + '</p>';
        if (g.events.length > 1) {
          html += '<p class="lnkup-muted lnkup-intel-action-card__count">' + g.events.length + ' events</p><ul class="lnkup-intel-action-card__events">';
          g.events.slice(0, 5).forEach(function (ev) {
            html += '<li>' + (ev.cta && ev.cta.url
              ? '<a href="' + escapeHtml(ev.cta.url) + '">' + escapeHtml(ev.title || ev.cta.label || 'Open') + '</a>'
              : escapeHtml(ev.title)) + '</li>';
          });
          html += '</ul>';
        } else if (g.events[0] && g.events[0].cta && g.events[0].cta.url) {
          var only = g.events[0];
          html += '<a href="' + escapeHtml(only.cta.url) + '" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline">' + escapeHtml(only.cta.label || 'View') + (only.title ? ' · ' + escapeHtml(only.title) : '') + '</a>';
        }
        html += '</article>';
      });
      html += '</div></section>';
    }

    var topEvents = (data.summary && data.summary.top_events && data.summary.top_events.length)
      ? data.summary.top_events
      : (data.events || []);
    if (topEvents.length) {
      html += '<section class="lnkup-intel-section"><h3>Top events</h3><div class="lnkup-intel-ranked-grid">';
      topEvents.slice(0, 5).forEach(function (ev, i) {
        html += rankedEventCard(ev, i + 1);
      });
      html += '</div></section>';
    }

    if (data.summary && data.summary.category_trends && data.summary.category_trends.length) {
      html += '<section class="lnkup-intel-section"><h3>Category trends</h3><div class="lnkup-intel-trend-grid">';
      data.summary.category_trends.forEach(function (c) {
        html += '<article class="lnkup-intel-trend-card"><strong>' + escapeHtml(c.category) + '</strong>';
        html += '<p class="lnkup-intel-trend-card__metric">' + escapeHtml(String(c.applications)) + ' apps</p>';
        if (c.insight) {
          html += '<p class="lnkup-muted">' + escapeHtml(c.insight) + '</p>';
        }
        html += '</article>';
      });
      html += '</div></section>';
    }

    html += '</div>';

    var hasContent = healthScore || kpiItems.length ||
      (data.insights && data.insights.length) ||
      (data.warnings && data.warnings.length) ||
      topEvents.length ||
      (data.summary && data.summary.recommended_actions && data.summary.recommended_actions.length);

    el.innerHTML = hasContent ? html : '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">No intelligence data yet</p><p class="lnkup-empty-state__desc">Create events and publish opportunities to see health scores and insights here.</p><div class="lnkup-empty-state__actions"><a href="#create" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm">Create event</a></div></div>';
    return !!hasContent;
  }

  function intelErrorHtml(message) {
    return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">Could not load intelligence</p>' +
      '<p class="lnkup-empty-state__desc">' + escapeHtml(message || 'Please try again in a moment.') + '</p>' +
      '<div class="lnkup-empty-state__actions"><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-intel-retry">Retry</button></div></div>';
  }

  function intelCompactSkeleton() {
    return '<div class="lnkup-crm-skeleton" aria-hidden="true"></div><div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
  }

  function withFetchTimeout(promise, ms) {
    var timeoutMs = ms || 8000;
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () {
        reject(new Error('Request timed out'));
      }, timeoutMs);
      promise.then(function (value) {
        clearTimeout(timer);
        resolve(value);
      }).catch(function (err) {
        clearTimeout(timer);
        reject(err);
      });
    });
  }

  function renderIntelSummaryHtml(data) {
    if (!data || data.code) {
      throw new Error((data && data.message) || 'Intelligence request failed');
    }
    var healthScore = (data.health && data.health.score != null)
      ? data.health.score
      : (data.summary && data.summary.event_score_average != null ? data.summary.event_score_average : null);
    var risk = (data.health && data.health.label) ? data.health.label : '';
    var topAction = (data.summary && data.summary.recommended_actions && data.summary.recommended_actions[0])
      ? data.summary.recommended_actions[0]
      : null;
    var html = '<div class="lnkup-organizer-intel-summary__chips">';
    if (healthScore != null) {
      html += '<span class="lnkup-organizer-intel-summary__chip">' + escapeHtml('Score ' + String(healthScore)) + '</span>';
    }
    if (risk) {
      html += '<span class="lnkup-organizer-intel-summary__chip">' + escapeHtml(String(risk)) + '</span>';
    }
    html += '</div>';
    if (topAction && topAction.title) {
      html += '<p class="lnkup-muted"><strong>' + escapeHtml(topAction.title) + '</strong>';
      if (topAction.reason) {
        html += ' — ' + escapeHtml(topAction.reason);
      }
      html += '</p>';
    } else if (healthScore != null || risk) {
      html += '<p class="lnkup-muted">' + escapeHtml('Portfolio snapshot ready — expand for full insights.') + '</p>';
    } else {
      html += '<p class="lnkup-muted">' + escapeHtml('Insights will appear as your events gain traction.') + '</p>';
    }
    return html;
  }

  function showIntelSummaryError(el) {
    if (window.LNKupUI) {
      LNKupUI.panelError(el, {
        title: 'Could not load intelligence',
        compact: true,
        onRetry: function () {
          el.removeAttribute('data-loaded');
          el.removeAttribute('data-ssr');
          loadOrganizerIntelSummary(el, true);
        }
      });
      return;
    }
    el.innerHTML = intelErrorHtml();
  }

  function loadOrganizerIntelSummary(el, force) {
    if (!el) {
      return;
    }
    if (el.getAttribute('data-ssr') === '1' && !force) {
      return;
    }
    if (el.getAttribute('data-loaded') === '1' && !force) {
      return;
    }
    el.removeAttribute('data-ssr');
    el.innerHTML = intelCompactSkeleton();

    function fetchData() {
      if (base) {
        return withFetchTimeout(intelFetch('/dashboard'));
      }
      return withFetchTimeout(intelAjaxFetch());
    }

    function applyData(data) {
      el.innerHTML = renderIntelSummaryHtml(data);
      el.setAttribute('data-loaded', '1');
    }

    fetchData().then(applyData).catch(function () {
      if (base && ajax.url) {
        withFetchTimeout(intelAjaxFetch()).then(applyData).catch(function () {
          showIntelSummaryError(el);
        });
        return;
      }
      showIntelSummaryError(el);
    });
  }

  function safeApplyOrganizerIntel(el, data) {
    try {
      if (!renderOrganizerIntel(el, data)) {
        el.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">Intelligence unavailable</p><p class="lnkup-empty-state__desc">No insights are available for your account yet.</p><div class="lnkup-empty-state__actions"><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" id="lnkup-intel-retry">Retry</button></div></div>';
      }
      return true;
    } catch (renderErr) {
      console.error('[LNKup Intel] Render failed:', renderErr);
      return false;
    }
  }

  function showOrganizerDashboardError(el, message, onRetry) {
    if (window.LNKupUI) {
      LNKupUI.panelError(el, {
        title: t('error_load_section', 'We could not load this section.'),
        desc: message || t('panel_error', 'Please try again in a moment.'),
        onRetry: onRetry
      });
      return;
    }
    el.innerHTML = intelErrorHtml(message);
  }

  function loadOrganizerDashboard(force) {
    var el = document.getElementById('lnkup-organizer-intelligence');
    if (!el) {
      return;
    }
    if (!base && !ajax.url) {
      el.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml(t('error_load_section', 'We could not load this section.')) + '</p>';
      return;
    }
    if (el.getAttribute('data-loaded') === '1' && !force) {
      return;
    }
    if (el.getAttribute('data-intel-loading') === '1' && !force) {
      return;
    }

    el.setAttribute('data-intel-loading', '1');
    el.removeAttribute('data-loaded');
    el.innerHTML = intelDashboardSkeleton();

    function fetchData() {
      if (base) {
        return withFetchTimeout(intelFetch('/dashboard'));
      }
      return withFetchTimeout(intelAjaxFetch());
    }

    function finishLoading() {
      el.removeAttribute('data-intel-loading');
    }

    function attempt() {
      fetchData()
        .catch(function (err) {
          if (base && ajax.url) {
            return withFetchTimeout(intelAjaxFetch());
          }
          throw err;
        })
        .then(function (data) {
          if (!safeApplyOrganizerIntel(el, data)) {
            showOrganizerDashboardError(el, 'Could not display intelligence data.', function () {
              loadOrganizerDashboard(true);
            });
            return;
          }
          el.setAttribute('data-loaded', '1');
        })
        .catch(function (err) {
          showOrganizerDashboardError(el, err && err.message ? err.message : null, function () {
            loadOrganizerDashboard(true);
          });
        })
        .finally(finishLoading);
    }

    attempt();
  }

  function bindOrganizerIntelRetry() {
    document.addEventListener('click', function (e) {
      var retryBtn = e.target && e.target.id === 'lnkup-intel-retry' ? e.target : null;
      if (!retryBtn) {
        return;
      }
      var el = document.getElementById('lnkup-organizer-intelligence');
      if (!el || !el.contains(retryBtn)) {
        return;
      }
      e.preventDefault();
      loadOrganizerDashboard(true);
    });
  }

  function loadVendorDashboard() {
    var el = document.getElementById('lnkup-vendor-intelligence');
    if (!el || !base) {
      if (el && !base) {
        el.innerHTML = '<p class="lnkup-muted">Insights unavailable on this page.</p>';
      }
      return;
    }
    el.innerHTML = '<div class="lnkup-intel-skeleton"></div>';
    function renderVendor() {
      intelFetch('/vendors/me').then(function (data) {
      if (!data || data.code) {
        if (window.LNKupUI) {
          LNKupUI.panelEmpty(el, { title: 'No insights yet', desc: t('empty_intelligence', 'No intelligence data yet. Insights will appear as your events gain activity.'), compact: true });
        } else {
          el.innerHTML = '<p class="lnkup-muted">No insights available.</p>';
        }
        return;
      }
      var html = '<div class="lnkup-intel-grid-2">';
      if (data.reputation && data.reputation.score != null) {
        var trend = data.reputation_trend === 'up' ? 'lnkup-intel-trend-up' : (data.reputation_trend === 'down' ? 'lnkup-intel-trend-down' : '');
        html += '<div><h3>Reputation</h3><p class="' + trend + '"><strong>' + escapeHtml(String(data.reputation.score)) + '</strong></p>';
        html += '<p class="lnkup-muted">Payment reliability: ' + escapeHtml(data.payment_reliability || '') + '</p>';
        html += '<p class="lnkup-muted">Documents: ' + escapeHtml(data.document_readiness || '') + '</p></div>';
      }
      if (data.trending_categories && data.trending_categories.length) {
        html += '<div><h3>Trending Categories</h3><p>' + escapeHtml(data.trending_categories.join(', ')) + '</p></div>';
      }
      html += '</div>';
      if (data.suggested_actions && data.suggested_actions.length) {
        html += '<section><h3>Suggested Actions</h3><div class="lnkup-intel-actions">';
        data.suggested_actions.forEach(function (a) {
          html += '<article class="lnkup-intel-card"><strong>' + escapeHtml(a.title) + '</strong><p>' + escapeHtml(a.reason) + '</p></article>';
        });
        html += '</div></section>';
      }
      if (data.public_trends && data.public_trends.cities && data.public_trends.cities.length) {
        html += '<section><h3>Active Markets</h3><ul>';
        data.public_trends.cities.forEach(function (c) {
          html += '<li>' + escapeHtml(c.label || c.city) + '</li>';
        });
        html += '</ul></section>';
      }
      el.innerHTML = html || (window.LNKupUI ? '' : '<p class="lnkup-muted">No insights yet.</p>');
      if (!html && window.LNKupUI) {
        LNKupUI.panelEmpty(el, { title: 'No insights yet', desc: t('empty_intelligence', 'No intelligence data yet. Insights will appear as your events gain activity.'), compact: true });
      }
    }).catch(function (err) {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { title: t('error_load_section', 'We could not load this section.'), onRetry: renderVendor });
      } else {
        el.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml(t('error_load_section', 'We could not load this section.')) + '</p>';
      }
    });
    renderVendor();
    }
  }

  function weatherInsightsHtml(weather) {
    if (!weather || typeof weather !== 'object' || !weather.event_id) {
      return '';
    }
    if (window.lnkupWeatherRenderCard) {
      var wrap = document.createElement('div');
      window.lnkupWeatherRenderCard(weather, wrap);
      return '<section class="lnkup-weather-intel-block"><h3>Weather Intelligence</h3>' + wrap.innerHTML + '</section>';
    }
    var risks = weather.risks || {};
    var html = '<section class="lnkup-weather-intel-block"><h3>Weather Intelligence</h3>';
    html += '<p>' + escapeHtml(weather.forecast_status || weather.summary || '') + '</p>';
    if (risks.overall) {
      html += '<p class="lnkup-muted">Overall risk: ' + escapeHtml(risks.overall) + '</p>';
    }
    html += '</section>';
    return html;
  }

  function loadEventInsights(eventId, container) {
    if (!container || !eventId || !base) {
      return;
    }
    container.innerHTML = '<div class="lnkup-intel-skeleton"></div>';
    function renderEvent() {
      intelFetch('/events/' + eventId).then(function (data) {
      if (!data || data.code) {
        if (window.LNKupUI && LNKupUI.panelEmpty) {
          LNKupUI.panelEmpty(container, {
            title: 'Insights are not ready yet',
            desc: 'They appear once this event has applications and payments.',
            compact: true
          });
        } else {
          container.innerHTML = '<p class="lnkup-muted">Insights are not ready yet.</p>';
        }
        return;
      }
      var html = '';
      if (data.weather) {
        html += weatherInsightsHtml(data.weather);
      }
      html += scoreBlock(data.score);
      if (data.application_funnel) {
        html += '<section><h3>Application Funnel</h3>';
        html += funnelHtml(data.application_funnel, [
          ['views', 'Views'],
          ['submitted', 'Submitted'],
          ['approved', 'Approved'],
          ['paid', 'Paid'],
          ['checked_in', 'Checked In'],
          ['completed', 'Completed']
        ]);
        if (data.application_funnel.insight) {
          html += '<p class="lnkup-intel-card lnkup-intel-card--warning">' + escapeHtml(data.application_funnel.insight) + '</p>';
        }
        html += '</section>';
      }
      if (data.payment_funnel) {
        html += '<section><h3>Payment Funnel</h3>';
        html += funnelHtml(data.payment_funnel, [
          ['approved_vendors', 'Approved'],
          ['payment_completed', 'Paid'],
          ['payment_pending', 'Pending'],
          ['payment_failed', 'Failed']
        ]);
        if (data.payment_funnel.insight) {
          html += '<p class="lnkup-intel-card">' + escapeHtml(data.payment_funnel.insight) + '</p>';
        }
        html += '</section>';
      }
      if (data.revenue) {
        html += '<section><h3>Revenue</h3><p><strong>' + escapeHtml(String(data.revenue.total_revenue || 0)) + '</strong> total · ' + escapeHtml(String(data.revenue.paid_vendors || 0)) + ' paid vendors</p></section>';
      }
      if (data.risks && data.risks.length) {
        html += '<section><h3>Risk Alerts</h3><ul class="lnkup-intel-risks">';
        data.risks.forEach(function (r) {
          html += '<li class="lnkup-intel-risk lnkup-intel-risk--' + escapeHtml(r.level || 'medium') + '">' + escapeHtml(r.message || '') + '</li>';
        });
        html += '</ul></section>';
      }
      if (data.actions && data.actions.length) {
        html += '<section><h3>Recommended Actions</h3><div class="lnkup-intel-actions">';
        data.actions.forEach(function (a) {
          html += '<article class="lnkup-intel-card"><strong>' + escapeHtml(a.title || '') + '</strong><p>' + escapeHtml(a.reason || '') + '</p>';
          if (a.cta && a.cta.url) {
            html += '<a href="' + escapeHtml(a.cta.url) + '" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary">' + escapeHtml(a.cta.label || 'Go') + '</a>';
          }
          html += '</article>';
        });
        html += '</div></section>';
      }
      if (data.predictions) {
        html += '<section data-testid="lnkup-intel-projections"><h3>Projections <span class="lnkup-intel-drawer__badge lnkup-intel-drawer__badge--default">Projection, not a measured result</span></h3>' +
          '<p class="lnkup-muted">' + escapeHtml(data.predictions.disclaimer || 'Estimated from this event’s applications and payments so far.') + '</p>';
        html += '<ul><li>Projected applications: ' + escapeHtml(String(data.predictions.predicted_application_volume || '—')) + '</li>';
        html += '<li>Projected payment completion: ' + escapeHtml(String(data.predictions.predicted_payment_completion || '—')) + '</li></ul></section>';
      }
      if (data.report && data.report.report && typeof data.report.report === 'object') {
        html += '<section><h3>Post-event report</h3><dl class="vos-kv">';
        Object.keys(data.report.report).forEach(function (key) {
          var value = data.report.report[key];
          var label = key.replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); });
          var text = value !== null && typeof value === 'object'
            ? Object.keys(value).map(function (k) { return k.replace(/_/g, ' ') + ': ' + (typeof value[k] === 'object' ? '…' : value[k]); }).join(' · ')
            : String(value);
          html += '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(text) + '</dd>';
        });
        html += '</dl></section>';
      }
      container.innerHTML = html;
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(container, { title: t('error_load_section', 'We could not load this section.'), onRetry: renderEvent });
      } else {
        container.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml(t('error_load_section', 'We could not load this section.')) + '</p>';
      }
    });
    }
    renderEvent();
  }

  function intelStatusBadge(label, mod) {
    return '<span class="lnkup-intel-drawer__badge lnkup-intel-drawer__badge--' + escapeHtml(mod || 'default') + '">' + escapeHtml(label || '') + '</span>';
  }

  function openIntelDrawer(appId) {
    var drawer = document.getElementById('lnkup-intel-drawer');
    var content = document.getElementById('lnkup-intel-drawer-content');
    if (!drawer || !content) {
      return;
    }
    intelDrawerReturnFocus = document.activeElement;
    drawer.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('lnkup-intel-drawer-open');
    var closeEl = document.getElementById('lnkup-intel-drawer-close');
    if (closeEl) {
      closeEl.focus();
    }
    content.innerHTML = '<div class="lnkup-intel-drawer__loading"><p class="lnkup-muted">Loading application intelligence…</p></div>';
    intelFetch('/applications/' + appId + '/intel').then(function (data) {
      if (!data || data.code) {
        content.innerHTML = '<div class="lnkup-intel-drawer__body"><p class="lnkup-alert lnkup-alert--error">' + escapeHtml(t('error_load_section', 'We could not load this section.')) + '</p></div>';
        return;
      }
      var vendorName = data.vendor_name || data.business_name || data.applicant_name || 'Vendor';
      var payStatus = data.payment_status || data.payment || '—';
      var appStatus = data.status || '—';
      var readiness = (data.readiness && data.readiness.percent) ? String(data.readiness.percent) + '%' : '—';
      var html = '<div class="lnkup-intel-drawer__body">';
      html += '<header class="lnkup-intel-drawer__header">';
      html += '<p class="lnkup-intel-drawer__eyebrow">Application intelligence</p>';
      html += '<h2 class="lnkup-intel-drawer__title">' + escapeHtml(vendorName) + '</h2>';
      html += '<div class="lnkup-intel-drawer__badges">';
      html += intelStatusBadge(appStatus, 'status');
      html += intelStatusBadge(payStatus, 'payment');
      html += intelStatusBadge(readiness + ' ready', 'readiness');
      html += '</div></header>';

      var card = document.querySelector('.lnkup-request-row[data-app-id="' + String(appId).replace(/[^0-9]/g, '') + '"]');
      var decisions = card ? card.querySelectorAll('.lnkup-review-btn, [data-request-info]') : [];
      if (decisions.length) {
        html += '<section class="lnkup-intel-drawer__card" data-testid="lnkup-intel-decide"><h3>Decide</h3><div class="lnkup-intel-drawer__actions">';
        Array.prototype.forEach.call(decisions, function (btn, i) {
          html += '<button type="button" class="lnkup-btn lnkup-btn--sm ' + (btn.getAttribute('data-action') === 'approve' ? 'lnkup-btn--primary' : 'lnkup-btn--outline') + '" data-drawer-proxy="' + i + '">' + escapeHtml(btn.textContent.trim()) + '</button>';
        });
        html += '</div><p class="lnkup-muted">Decisions apply to the request card and notify the vendor.</p></section>';
      }

      if (data.info_history && data.info_history.length) {
        html += '<section class="lnkup-intel-drawer__card"><h3>Questions &amp; replies</h3><ul class="lnkup-intel-drawer__timeline">';
        data.info_history.forEach(function (entry) {
          html += '<li><strong>' + (entry.type === 'response' ? 'Vendor' : 'You') + '</strong> ' + escapeHtml(entry.message || '') + '<span class="lnkup-muted">' + escapeHtml(entry.at || '') + '</span></li>';
        });
        html += '</ul></section>';
      }

      if (data.vendor_history && data.vendor_history.length) {
        html += '<section class="lnkup-intel-drawer__card"><h3>History with you</h3><ul class="lnkup-intel-drawer__list">';
        data.vendor_history.forEach(function (row) {
          html += '<li><span>' + escapeHtml(row.event || 'Event') + (row.date ? ' · ' + escapeHtml(row.date) : '') + '</span><strong>' + escapeHtml(row.status || '') + '</strong></li>';
        });
        html += '</ul></section>';
      }

      html += '<section class="lnkup-intel-drawer__card"><h3>Payment summary</h3>';
      if (data.payment_summary) {
        html += '<ul class="lnkup-intel-drawer__list">';
        Object.keys(data.payment_summary).forEach(function (key) {
          html += '<li><span>' + escapeHtml(key.replace(/_/g, ' ')) + '</span><strong>' + escapeHtml(String(data.payment_summary[key])) + '</strong></li>';
        });
        html += '</ul>';
      } else {
        html += '<p class="lnkup-muted">No payment activity yet.</p>';
      }
      html += '</section>';

      html += '<section class="lnkup-intel-drawer__card"><h3>Documents</h3>';
      var readinessItems = (data.readiness && data.readiness.items) ? data.readiness.items : [];
      var docItems = readinessItems.filter(function (item) {
        var key = String(item.key || item.id || item.slug || '').toLowerCase();
        var label = String(item.label || '').toLowerCase();
        return key.indexOf('payment') === -1 && label.indexOf('payment') === -1;
      });
      var pendingDocs = docItems.filter(function (item) { return !item.done; });
      if (pendingDocs.length) {
        html += '<ul class="lnkup-intel-drawer__checklist">';
        pendingDocs.forEach(function (item) {
          html += '<li>' + escapeHtml(item.label) + '</li>';
        });
        html += '</ul>';
      } else if (docItems.length) {
        html += '<p class="lnkup-muted">Documents complete.</p>';
      } else {
        html += '<p class="lnkup-muted">No required documents for this opportunity.</p>';
      }
      html += '</section>';

      html += '<section class="lnkup-intel-drawer__card"><h3>Risk signals</h3>';
      if (data.reliability && data.reliability.flags && data.reliability.flags.length) {
        data.reliability.flags.forEach(function (f) {
          html += '<p class="lnkup-intel-flag lnkup-intel-flag--' + escapeHtml(f.type || 'warning') + '">' + escapeHtml(f.message || f.label || '') + '</p>';
        });
        html += '<p class="lnkup-muted">Payment likelihood: ' + escapeHtml(data.reliability.payment_likelihood || '—') + ' · No-show risk: ' + escapeHtml(data.reliability.no_show_likelihood || '—') + '</p>';
      } else {
        html += '<p class="lnkup-muted">No risk flags for this applicant.</p>';
      }
      html += '</section>';

      if (data.recommended_actions && data.recommended_actions.length) {
        html += '<section class="lnkup-intel-drawer__card"><h3>Recommended actions</h3><ul class="lnkup-intel-drawer__list">';
        data.recommended_actions.forEach(function (action) {
          html += '<li>' + escapeHtml(action.label || action) + '</li>';
        });
        html += '</ul></section>';
      }

      if (data.timeline && data.timeline.length) {
        html += '<section class="lnkup-intel-drawer__card"><h3>Timeline</h3><ul class="lnkup-intel-drawer__timeline">';
        data.timeline.forEach(function (item) {
          html += '<li><strong>' + escapeHtml(item.label || item.type || '') + '</strong><span class="lnkup-muted">' + escapeHtml(item.created_at || item.date || '') + '</span></li>';
        });
        html += '</ul></section>';
      } else if (data.message) {
        html += '<section class="lnkup-intel-drawer__card"><h3>Application message</h3><p>' + escapeHtml(data.message) + '</p></section>';
      }

      html += '</div>';
      content.innerHTML = html;
      Array.prototype.forEach.call(content.querySelectorAll('[data-drawer-proxy]'), function (proxy) {
        proxy.addEventListener('click', function () {
          var target = decisions[Number(proxy.getAttribute('data-drawer-proxy'))];
          if (!target) {
            return;
          }
          closeIntelDrawer();
          target.scrollIntoView({ block: 'center' });
          target.click();
        });
      });
    }).catch(function () {
      content.innerHTML = '<div class="lnkup-intel-drawer__body"><p class="lnkup-alert lnkup-alert--error">' + escapeHtml(t('error_load_section', 'We could not load this section.')) + '</p></div>';
    });
  }

  var intelDrawerReturnFocus = null;

  function closeIntelDrawer() {
    var drawer = document.getElementById('lnkup-intel-drawer');
    if (drawer && !drawer.hidden) {
      drawer.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('lnkup-intel-drawer-open');
      if (intelDrawerReturnFocus && typeof intelDrawerReturnFocus.focus === 'function') {
        intelDrawerReturnFocus.focus();
      }
      intelDrawerReturnFocus = null;
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeIntelDrawer();
    }
  });

  function initOpsInsightsTab() {
    document.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-ops-tab="insights"]');
      if (!tab) {
        return;
      }
      var wrap = document.getElementById('lnkup-event-insights');
      if (wrap && wrap.getAttribute('data-loaded') !== '1') {
        loadEventInsights(wrap.getAttribute('data-event-id'), wrap);
        wrap.setAttribute('data-loaded', '1');
      }
    });
    var wrap = document.getElementById('lnkup-event-insights');
    if (wrap && wrap.closest('.lnkup-ops-tab-panel.is-active')) {
      loadEventInsights(wrap.getAttribute('data-event-id'), wrap);
      wrap.setAttribute('data-loaded', '1');
    }
    document.addEventListener('lnkup-ops-panel-open', function (e) {
      if (!e.detail || e.detail.tab !== 'insights') {
        return;
      }
      var eventWrap = document.getElementById('lnkup-event-insights');
      if (eventWrap && eventWrap.getAttribute('data-loaded') !== '1') {
        loadEventInsights(eventWrap.getAttribute('data-event-id'), eventWrap);
        eventWrap.setAttribute('data-loaded', '1');
      }
    });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('.lnkup-intel-open')) {
      var btn = e.target.closest('.lnkup-intel-open');
      openIntelDrawer(btn.getAttribute('data-app-id'));
    }
    if (e.target.id === 'lnkup-intel-drawer-close' || e.target.id === 'lnkup-intel-drawer') {
      closeIntelDrawer();
    }
  });

  function initOrganizerIntelIfExpanded() {
    var panel = document.getElementById('lnkup-organizer-expand-intelligence');
    var el = document.getElementById('lnkup-organizer-intelligence');
    if (!panel || !el || panel.hidden) {
      return;
    }
    if (el.getAttribute('data-loaded') === '1' || el.getAttribute('data-intel-loading') === '1') {
      return;
    }
    loadOrganizerDashboard(true);
  }

  function observeIntelExpandPanel() {
    var panel = document.getElementById('lnkup-organizer-expand-intelligence');
    if (!panel || !('MutationObserver' in window)) {
      return;
    }
    var observer = new MutationObserver(function () {
      if (!panel.hidden) {
        initOrganizerIntelIfExpanded();
      }
    });
    observer.observe(panel, { attributes: true, attributeFilter: ['hidden', 'class'] });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindOrganizerIntelRetry();
    document.addEventListener('lnkup-intel-expand', function () {
      loadOrganizerDashboard(true);
    });
    observeIntelExpandPanel();
    initOrganizerIntelIfExpanded();
    loadVendorDashboard();
    initOpsInsightsTab();
    var closeBtn = document.getElementById('lnkup-intel-drawer-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', closeIntelDrawer);
    }
  });

  window.lnkupLoadOrganizerDashboard = loadOrganizerDashboard;
  window.lnkupLoadOrganizerIntelSummary = loadOrganizerIntelSummary;
})();
