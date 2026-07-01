/**
 * LNKup shared dashboard UI utilities.
 */
(function (window) {
  'use strict';

  var copy = window.lnkupUiCopy || {};

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function t(key, fallback) {
    return copy[key] || fallback || key;
  }

  /**
   * Safe JSON fetch with content-type validation.
   */
  function fetchJson(url, options) {
    options = options || {};
    var nonce = (window.lnkupAjax && window.lnkupAjax.restNonce) || '';
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (response) {
        var contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
          return response.text().then(function (text) {
            console.error('[LNKupUI] Expected JSON:', text.slice(0, 300));
            throw new Error(t('panel_error', 'Invalid server response.'));
          });
        }
        return response.json().then(function (body) {
          if (!response.ok) {
            var message = (body && (body.message || body.code)) || t('panel_error', 'Request failed.');
            throw new Error(message);
          }
          return body;
        });
      })
      .catch(function (err) {
        console.error('[LNKupUI]', url, err);
        throw err;
      });
  }

  function panelSkeleton(el, count) {
    if (!el) {
      return;
    }
    var n = count || 3;
    var html = '';
    for (var i = 0; i < n; i++) {
      html += '<div class="lnkup-skeleton lnkup-skeleton--card" aria-hidden="true"></div>';
    }
    el.innerHTML = html;
  }

  function panelEmpty(el, opts) {
    if (!el) {
      return;
    }
    opts = opts || {};
    var compact = opts.compact ? ' lnkup-empty-state--compact' : '';
    var icon = opts.icon !== false ? '<div class="lnkup-empty-state__icon" aria-hidden="true"></div>' : '';
    var cta = '';
    if (opts.ctaLabel && opts.ctaHref) {
      cta += '<a href="' + escapeHtml(opts.ctaHref) + '" class="lnkup-btn lnkup-btn--primary">' + escapeHtml(opts.ctaLabel) + '</a>';
    } else if (opts.ctaLabel && typeof opts.onCta === 'function') {
      cta += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-ui-empty-cta">' + escapeHtml(opts.ctaLabel) + '</button>';
    }
    if (opts.secondaryCtaLabel && opts.secondaryCtaHref) {
      cta += '<a href="' + escapeHtml(opts.secondaryCtaHref) + '" class="lnkup-btn lnkup-btn--ghost">' + escapeHtml(opts.secondaryCtaLabel) + '</a>';
    }
    if (cta) {
      cta = '<div class="lnkup-empty-state__actions">' + cta + '</div>';
    }
    el.innerHTML = '<div class="lnkup-empty-state' + compact + '">' + icon +
      '<p class="lnkup-empty-state__title">' + escapeHtml(opts.title || '') + '</p>' +
      (opts.desc ? '<p class="lnkup-empty-state__desc">' + escapeHtml(opts.desc) + '</p>' : '') +
      cta + '</div>';
    if (opts.onCta) {
      var btn = el.querySelector('.lnkup-ui-empty-cta');
      if (btn) {
        btn.addEventListener('click', opts.onCta);
      }
    }
  }

  function panelError(el, opts) {
    if (!el) {
      return;
    }
    opts = opts || {};
    var retry = '';
    if (opts.onRetry) {
      retry = '<div class="lnkup-empty-state__actions"><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-ui-retry">' +
        escapeHtml(t('retry', 'Try again')) + '</button></div>';
    }
    el.innerHTML = '<div class="lnkup-alert lnkup-alert--error lnkup-panel-state--error">' +
      '<p class="lnkup-empty-state__title">' + escapeHtml(opts.title || t('panel_error', 'We couldn\'t load this section.')) + '</p>' +
      (opts.desc ? '<p class="lnkup-empty-state__desc">' + escapeHtml(opts.desc) + '</p>' : '') +
      retry + '</div>';
    if (opts.onRetry) {
      var btn = el.querySelector('.lnkup-ui-retry');
      if (btn) {
        btn.addEventListener('click', opts.onRetry);
      }
    }
    el.dataset.loaded = '1';
  }

  function panelMessage(el, type, title, desc) {
    if (!el) {
      return;
    }
    if (type === 'error') {
      panelError(el, { title: title, desc: desc });
      return;
    }
    panelEmpty(el, { title: title, desc: desc, compact: true });
  }

  function renderPriorityItem(item) {
    item = item || {};
    var level = item.level || 'info';
    var action = '';
    if (item.actionHtml) {
      action = '<div class="lnkup-priority-item__action">' + item.actionHtml + '</div>';
    } else if (item.href && item.actionLabel) {
      action = '<div class="lnkup-priority-item__action"><a href="' + escapeHtml(item.href) +
        '" class="lnkup-btn lnkup-btn--sm ' + (item.actionPrimary ? 'lnkup-btn--primary' : 'lnkup-btn--ghost') + '">' +
        escapeHtml(item.actionLabel) + '</a></div>';
    }
    return '<li class="lnkup-priority-item lnkup-micro-lift">' +
      '<span class="lnkup-priority-item__dot lnkup-priority-item__dot--' + escapeHtml(level) + '" aria-hidden="true"></span>' +
      '<div class="lnkup-priority-item__body">' +
      '<p class="lnkup-priority-item__title">' + escapeHtml(item.title || '') + '</p>' +
      (item.meta ? '<p class="lnkup-priority-item__meta">' + escapeHtml(item.meta) + '</p>' : '') +
      '</div>' + action + '</li>';
  }

  function renderWorkflowStep(label, state) {
    var cls = state === 'done' ? ' is-done' : (state === 'current' ? ' is-current' : '');
    return '<div class="lnkup-workflow-step' + cls + '"><span class="lnkup-workflow-step__label">' + escapeHtml(label) + '</span></div>';
  }

  function renderOpportunityCard(item, opts) {
    opts = opts || {};
    if (!item) return '';
    var opp = item;
    var fee = opp.fee_label || (opp.vendor_fee != null ? '$' + Number(opp.vendor_fee).toFixed(0) : '');
    var location = opp.location_label || [opp.city, opp.state].filter(Boolean).join(', ');
    var title = opp.event_name || opp.name || opp.title || '';
    var img = opp.image || opp.image_url || '';
    var applyUrl = opp.apply_url || (opp.opportunity_id ? '/opportunities/?apply=' + opp.opportunity_id : '#');
    var compact = opts.compact ? ' lnkup-event-card--compact' : '';
    var media = img
      ? '<img src="' + escapeHtml(img) + '" alt="" loading="lazy" />'
      : '<div class="lnkup-event-card__placeholder" aria-hidden="true"><span>LNKup</span></div>';
    return '<article class="lnkup-event-card lnkup-event-card--v2 lnkup-surface--primary lnkup-micro-lift' + compact + '" data-opportunity-id="' + escapeHtml(String(opp.opportunity_id || '')) + '">' +
      '<div class="lnkup-event-card__media">' + media +
      '<div class="lnkup-event-card__media-gradient" aria-hidden="true"></div>' +
      '<div class="lnkup-event-card__badges-top"><span class="lnkup-badge lnkup-badge--accent">' + escapeHtml(fee) + '</span></div></div>' +
      '<div class="lnkup-event-card__body">' +
      '<h3 class="lnkup-event-card__title">' + escapeHtml(title) + '</h3>' +
      (location ? '<p class="lnkup-event-card__location">' + escapeHtml(location) + '</p>' : '') +
      (opts.showCta !== false ? '<a href="' + escapeHtml(applyUrl) + '" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm">' + escapeHtml(opts.ctaLabel || 'View') + '</a>' : '') +
      '</div></article>';
  }

  function renderRail(title, innerHtml, linkHref, linkLabel) {
    var link = linkHref ? '<a href="' + escapeHtml(linkHref) + '" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">' + escapeHtml(linkLabel || 'See all') + '</a>' : '';
    return '<section class="lnkup-rail"><div class="lnkup-rail__header"><h3 class="lnkup-rail__title">' + escapeHtml(title) + '</h3>' + link + '</div>' +
      '<div class="lnkup-rail__track">' + (innerHtml || '<div class="lnkup-rail__placeholder">No matches yet</div>') + '</div></section>';
  }

  function showInlineSuccess(el, message) {
    if (!el) return;
    var node = document.createElement('div');
    node.className = 'lnkup-inline-success';
    node.setAttribute('role', 'status');
    node.textContent = message || 'Saved';
    el.prepend(node);
    setTimeout(function () { node.remove(); }, 3200);
  }

  function renderBarChart(items, opts) {
    opts = opts || {};
    var valueKey = opts.valueKey || 'amount';
    var labelKey = opts.labelKey || 'date';
    if (!items || !items.length) {
      return '<p class="lnkup-type-meta">' + escapeHtml(opts.emptyLabel || 'No data yet.') + '</p>';
    }
    var slice = items.slice(opts.limit ? -opts.limit : -20);
    var max = Math.max.apply(null, slice.map(function (i) { return parseFloat(i[valueKey]) || 0; })) || 1;
    var html = '<div class="lnkup-chart" role="img" aria-label="' + escapeHtml(opts.ariaLabel || 'Chart') + '">';
    slice.forEach(function (item, idx) {
      var val = parseFloat(item[valueKey]) || 0;
      var h = Math.max(4, Math.round((val / max) * 100));
      var isLatest = idx === slice.length - 1;
      var label = item[labelKey] || item.month || item.label || '';
      html += '<div class="lnkup-chart__bar' + (isLatest ? ' is-latest' : '') + (val <= 0 ? ' is-empty' : '') +
        '" style="height:' + h + '%" title="' + escapeHtml(label) + '"></div>';
    });
    html += '</div>';
    return html;
  }

  function renderSparkline(values) {
    if (!values || !values.length) return '';
    var max = Math.max.apply(null, values.map(function (v) { return parseFloat(v) || 0; })) || 1;
    var html = '<div class="lnkup-chart-spark" aria-hidden="true">';
    values.forEach(function (v) {
      var h = Math.max(15, Math.round(((parseFloat(v) || 0) / max) * 100));
      html += '<span class="lnkup-chart-spark__bar" style="height:' + h + '%"></span>';
    });
    html += '</div>';
    return html;
  }

  function animateCount(el, target, opts) {
    if (!el) return;
    opts = opts || {};
    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var end = parseFloat(target) || 0;
    if (prefersReduced || opts.instant) {
      el.textContent = opts.format ? opts.format(end) : String(end);
      return;
    }
    var start = 0;
    var duration = opts.duration || 600;
    var startTime = null;
    function step(ts) {
      if (!startTime) startTime = ts;
      var p = Math.min(1, (ts - startTime) / duration);
      var val = start + (end - start) * p;
      el.textContent = opts.format ? opts.format(val) : String(Math.round(val));
      if (p < 1) window.requestAnimationFrame(step);
    }
    window.requestAnimationFrame(step);
  }

  function shimmer(el, on) {
    if (!el) return;
    el.classList.toggle('lnkup-shimmer', on !== false);
  }

  function mountSparkline(container, values) {
    if (!container || !values || !values.length) return;
    container.innerHTML = renderSparkline(values);
  }

  function animateKpiCards(root) {
    if (!root) return;
    root.querySelectorAll('[data-animate-count]').forEach(function (el) {
      var raw = el.getAttribute('data-animate-count');
      var prefix = el.getAttribute('data-count-prefix') || '';
      var suffix = el.getAttribute('data-count-suffix') || '';
      var isCurrency = el.getAttribute('data-count-currency') === '1';
      var target = parseFloat(raw) || 0;
      animateCount(el, target, {
        format: function (v) {
          if (isCurrency) {
            return prefix + Number(v).toFixed(2) + suffix;
          }
          return prefix + (Number.isInteger(target) ? String(Math.round(v)) : v.toFixed(1)) + suffix;
        }
      });
    });
  }

  function tableEmptyPanel(el, opts) {
    panelEmpty(el, opts);
  }

  window.LNKupUI = {
    escapeHtml: escapeHtml,
    t: t,
    fetchJson: fetchJson,
    panelSkeleton: panelSkeleton,
    panelEmpty: panelEmpty,
    panelError: panelError,
    panelMessage: panelMessage,
    renderPriorityItem: renderPriorityItem,
    renderWorkflowStep: renderWorkflowStep,
    renderOpportunityCard: renderOpportunityCard,
    renderRail: renderRail,
    showInlineSuccess: showInlineSuccess,
    renderBarChart: renderBarChart,
    renderSparkline: renderSparkline,
    animateCount: animateCount,
    animateKpiCards: animateKpiCards,
    mountSparkline: mountSparkline,
    tableEmptyPanel: tableEmptyPanel,
    shimmer: shimmer
  };
})(window);
