(function () {
  var cfg = window.lnkupMarketPage || {};
  if (!cfg.marketSlug) return;

  function track(eventType, extra) {
    if (!cfg.restUrl) return;
    var payload = {
      event_type: eventType,
      data: Object.assign(
        { market_slug: cfg.marketSlug, launch_status: cfg.launchStatus || '' },
        extra || {}
      )
    };
    fetch(cfg.restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.restNonce || ''
      },
      body: JSON.stringify(payload)
    }).catch(function () {});
  }

  track('market_page_view');

  function post(path, body) {
    return fetch(cfg.marketsApi + encodeURIComponent(cfg.marketSlug) + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
      body: JSON.stringify(body || {})
    }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (!res.ok || type.indexOf('application/json') === -1) throw new Error('Request failed');
      return res.json();
    });
  }

  function say(el, text, isError) {
    if (!el) return;
    el.textContent = text;
    el.hidden = false;
    el.classList.toggle('is-error', !!isError);
  }

  var i18n = cfg.i18n || {};

  var alertForm = document.querySelector('[data-lnkup-market-alert]');
  if (alertForm && cfg.marketsApi) {
    alertForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var button = alertForm.querySelector('button[type="submit"]');
      var status = alertForm.querySelector('[data-lnkup-alert-status]');
      var select = alertForm.querySelector('select[name="category"]');
      if (button) button.disabled = true;
      post('/alert', { category: select ? select.value : '' }).then(function (data) {
        say(status, data && data.created ? i18n.alertOn : i18n.alertExists, false);
        track('market_alert_submit');
      }).catch(function () {
        say(status, i18n.alertError, true);
      }).finally(function () {
        if (button) button.disabled = false;
      });
    });
  }

  document.querySelectorAll('[data-lnkup-demand]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      if (chip.getAttribute('aria-pressed') === 'true' || !cfg.marketsApi) return;
      chip.setAttribute('aria-pressed', 'true');
      var status = document.querySelector('[data-lnkup-demand-status]');
      post('/demand', { category: chip.getAttribute('data-lnkup-demand') }).then(function () {
        say(status, i18n.demandOn, false);
      }).catch(function () {
        chip.setAttribute('aria-pressed', 'false');
      });
    });
  });

  document.addEventListener('click', function (e) {
    var trackEl = e.target.closest('[data-lnkup-track]');
    if (trackEl) {
      track(trackEl.getAttribute('data-lnkup-track'));
      return;
    }
    var oppLink = e.target.closest('.lnkup-event-card a[href]');
    if (oppLink && document.querySelector('[data-testid="lnkup-market-opportunities"]')) {
      track('market_opportunity_click');
    }
  });

  document.querySelectorAll('.lnkup-reveal').forEach(function (el, i) {
    el.style.animationDelay = Math.min(i * 0.05, 0.4) + 's';
    el.classList.add('is-visible');
  });
})();
