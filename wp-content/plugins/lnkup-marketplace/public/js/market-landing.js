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
