(function () {
  'use strict';

  var root = document.querySelector('.lnkup-vendor-dash');
  if (!root || !document.querySelector('[data-vendor-home-panel]')) {
    return;
  }

  var cfg = window.lnkupAjax || {};
  var restBase = (cfg.restUrl || '').replace(/\/$/, '');

  function api(path, query) {
    if (!restBase) {
      return Promise.reject(new Error('REST unavailable'));
    }
    var url = restBase + path;
    if (query) {
      var qs = new URLSearchParams(query).toString();
      if (qs) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + qs;
      }
    }
    if (window.LNKupUI && LNKupUI.fetchJson) {
      return LNKupUI.fetchJson(url);
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (r) { return r.json(); });
  }

  function cardsFromPayload(data) {
    var items = (data && data.items) || (data && data.opportunities) || (Array.isArray(data) ? data : []);
    if (!items.length) {
      return '';
    }
    return items.map(function (item) {
      if (window.LNKupUI && LNKupUI.renderOpportunityCard) {
        return LNKupUI.renderOpportunityCard(item, { ctaLabel: 'View opportunity' });
      }
      return '';
    }).join('');
  }

  function loadRail(container, path, query) {
    if (!container || container.dataset.loaded === '1') {
      return;
    }
    container.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card" style="min-width:16rem"></div><div class="lnkup-skeleton lnkup-skeleton--card" style="min-width:16rem"></div>';
    api(path, query).then(function (data) {
      var html = cardsFromPayload(data);
      container.innerHTML = html || '<div class="lnkup-rail__placeholder">No opportunities in this collection yet.</div>';
      container.dataset.loaded = '1';
    }).catch(function () {
      container.innerHTML = '<div class="lnkup-rail__placeholder">Could not load opportunities.</div>';
    });
  }

  function initRails() {
    var railsRoot = document.getElementById('lnkup-vendor-home-rails');
    if (!railsRoot || railsRoot.dataset.marketplaceLocked === '1') {
      return;
    }
    railsRoot.querySelectorAll('[data-rail]').forEach(function (track) {
      var rail = track.getAttribute('data-rail');
      if (rail === 'recommended') {
        loadRail(track, '/marketplace/sections/recommended', { limit: 8 });
      } else if (rail === 'closing_soon') {
        loadRail(track, '/marketplace/sections/trending', { section: 'closing_soon', limit: 8 });
      } else if (rail === 'trending') {
        loadRail(track, '/marketplace/sections/trending', { section: 'trending', limit: 8 });
      } else if (rail === 'recent') {
        loadRail(track, '/marketplace/sections/trending', { section: 'most_applied', limit: 8 });
      }
    });
  }

  function syncHomePanels(hash) {
    hash = hash || 'recommendations';
    document.querySelectorAll('[data-vendor-home-panel]').forEach(function (panel) {
      var active = panel.getAttribute('data-vendor-home-panel') === hash;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
      if (active) {
        panel.classList.add('lnkup-panel-fade-in');
      }
    });
    document.querySelectorAll('.lnkup-dash-subnav__link[href^="#"]').forEach(function (link) {
      var target = (link.getAttribute('href') || '').replace('#', '');
      link.classList.toggle('is-active', target === hash);
    });
    if (hash === 'recommendations') {
      initRails();
    }
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest('.lnkup-dash-subnav__link[href^="#"]');
    if (!link || !root.contains(link)) {
      return;
    }
    var hash = (link.getAttribute('href') || '').replace('#', '');
    if (!hash) {
      return;
    }
    e.preventDefault();
    if (window.history.replaceState) {
      window.history.replaceState(null, '', '#' + hash);
    } else {
      window.location.hash = hash;
    }
    syncHomePanels(hash);
  });

  window.addEventListener('hashchange', function () {
    syncHomePanels((window.location.hash || '#recommendations').replace('#', '') || 'recommendations');
  });

  syncHomePanels((window.location.hash || '#recommendations').replace('#', '') || 'recommendations');

  if (window.LNKupUI && LNKupUI.animateKpiCards) {
    LNKupUI.animateKpiCards(document.getElementById('lnkup-vendor-kpi-strip'));
  }
})();
