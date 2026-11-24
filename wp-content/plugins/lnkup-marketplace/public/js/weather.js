/**
 * Event Weather Intelligence — refresh and panel hydration.
 */
(function () {
  'use strict';

  var base = (window.lnkupAjax && window.lnkupAjax.weatherRestUrl) || '';

  function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function fetchJson(url, options) {
    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': (window.lnkupAjax && window.lnkupAjax.restNonce) || ''
      }
    }, options || {})).then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        throw new Error('Invalid response');
      }
      return res.json().then(function (json) {
        if (!res.ok) {
          throw new Error(json.message || json.code || 'Request failed');
        }
        return json;
      });
    });
  }

  function riskChip(label, level) {
    level = level || 'unavailable';
    return '<span class="lnkup-weather-risk lnkup-weather-risk--' + escapeHtml(level) + '">' +
      '<span class="lnkup-weather-risk__label">' + escapeHtml(label) + '</span> ' +
      '<span class="lnkup-weather-risk__value">' + escapeHtml(level.charAt(0).toUpperCase() + level.slice(1)) + '</span></span>';
  }

  function renderCard(dto, container) {
    if (!container || !dto) return;
    if (dto.message && dto.status && ['unconfigured', 'no_location', 'no_date', 'error'].indexOf(dto.status) !== -1 && !dto.stale) {
      container.innerHTML = '<div class="lnkup-weather-card lnkup-weather-card--' + escapeHtml(dto.status) + '" data-testid="lnkup-ops-weather" data-event-id="' + escapeHtml(String(dto.event_id || '')) + '">' +
        '<h3 class="lnkup-weather-card__title">Weather Readiness</h3>' +
        '<p class="lnkup-alert lnkup-alert--info">' + escapeHtml(dto.message) + '</p>' +
        (dto.status === 'unconfigured' ? '' : '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-weather-refresh" data-event-id="' + escapeHtml(String(dto.event_id || '')) + '">Retry</button>') + '</div>';
      return;
    }
    var risks = dto.risks || {};
    var icon = (dto.current && dto.current.icon) || (dto.event_day && dto.event_day.icon) || '';
    var html = '<div class="lnkup-weather-card' + (dto.stale ? ' is-stale' : '') + '" data-testid="lnkup-ops-weather" data-event-id="' + escapeHtml(String(dto.event_id || '')) + '">';
    html += '<div class="lnkup-weather-card__head"><h3 class="lnkup-weather-card__title">Weather Readiness</h3>';
    if (icon) {
      html += '<img class="lnkup-weather-card__icon" src="https://openweathermap.org/img/wn/' + encodeURIComponent(icon) + '@2x.png" alt="" width="48" height="48" loading="lazy" />';
    }
    html += '</div>';
    html += '<p class="lnkup-weather-card__status">' + escapeHtml(dto.forecast_status || '') + '</p>';
    if (dto.summary) {
      html += '<p class="lnkup-weather-card__summary">' + escapeHtml(dto.summary) + '</p>';
    }
    html += '<div class="lnkup-weather-card__risks">';
    html += riskChip('Overall', risks.overall);
    html += riskChip('Rain', risks.rain);
    html += riskChip('Wind', risks.wind);
    html += riskChip('Heat', risks.heat);
    html += '</div>';
    if (dto.recommendations && dto.recommendations.length) {
      html += '<ul class="lnkup-weather-recs">';
      dto.recommendations.forEach(function (r) {
        html += '<li>' + escapeHtml(r) + '</li>';
      });
      html += '</ul>';
    }
    if (dto.last_updated) {
      html += '<p class="lnkup-weather-card__updated lnkup-muted">Last updated ' + escapeHtml(dto.last_updated) + '</p>';
    }
    html += '<div class="lnkup-weather-card__actions"><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-weather-refresh" data-event-id="' + escapeHtml(String(dto.event_id || '')) + '">Refresh forecast</button></div>';
    html += '</div>';
    container.innerHTML = html;
  }

  function refreshWeather(eventId, container) {
    if (!base || !eventId) return Promise.resolve();
    if (container) {
      container.innerHTML = '<div class="lnkup-weather-card lnkup-weather-card--loading"><div class="lnkup-skeleton lnkup-skeleton--card"></div><p class="lnkup-muted">Refreshing weather intelligence…</p></div>';
    }
    return fetchJson(base + '/event/' + eventId + '/refresh', { method: 'POST' })
      .then(function (dto) {
        if (container) renderCard(dto, container);
        document.querySelectorAll('[data-testid="lnkup-weather-readiness-card"][data-event-id="' + eventId + '"]').forEach(function (el) {
          renderCard(dto, el);
        });
        return dto;
      })
      .catch(function () {
        if (container) {
          container.innerHTML = '<div class="lnkup-weather-card lnkup-weather-card--error"><p class="lnkup-alert lnkup-alert--error">We couldn\'t refresh weather intelligence right now. Try again later.</p>' +
            '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-weather-refresh" data-event-id="' + escapeHtml(String(eventId)) + '">Retry</button></div>';
        }
      });
  }

  function loadWeather(eventId, container) {
    if (!base || !eventId || !container) return;
    if (container.dataset.loaded === '1') return;
    container.dataset.loaded = '1';
    fetchJson(base + '/event/' + eventId)
      .then(function (dto) { renderCard(dto, container); })
      .catch(function () {
        container.innerHTML = '<p class="lnkup-alert lnkup-alert--error">Could not load weather intelligence.</p>';
      });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.lnkup-weather-refresh');
    if (!btn) return;
    e.preventDefault();
    var eventId = btn.getAttribute('data-event-id');
    var panel = btn.closest('#lnkup-ops-weather-panel, [data-testid="lnkup-ops-weather"], [data-testid="lnkup-weather-readiness-card"]');
    refreshWeather(eventId, panel);
  });

  document.addEventListener('DOMContentLoaded', function () {
    var opsPanel = document.getElementById('lnkup-ops-weather-panel');
    if (opsPanel && opsPanel.querySelector('.lnkup-weather-card--loading')) {
      var root = document.getElementById('lnkup-event-ops');
      var eventId = root && root.getAttribute('data-event-id');
      if (eventId) loadWeather(eventId, opsPanel);
    }
  });

  window.lnkupWeatherRefresh = refreshWeather;
  window.lnkupWeatherLoad = loadWeather;
  window.lnkupWeatherRenderCard = renderCard;
})();
