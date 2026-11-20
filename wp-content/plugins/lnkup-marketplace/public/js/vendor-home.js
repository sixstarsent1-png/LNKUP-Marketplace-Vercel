(function () {
  'use strict';

  var cfg = window.lnkupAjax || {};

  function postAjax(action) {
    var body = new FormData();
    body.append('action', action);
    body.append('lnkup_nonce', cfg.nonce || '');
    return fetch(cfg.url || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); });
  }

  function initKnowledgeBaseReset() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-kb-show-checklist]');
      if (!btn || btn.getAttribute('data-kb-role') !== 'vendor') {
        return;
      }
      e.preventDefault();
      var target = btn.getAttribute('data-kb-checklist-url') || '/';
      postAjax('lnkup_reset_vendor_tutorial').then(function () {
        window.location.href = target;
      }).catch(function () {
        window.location.href = target;
      });
    });
  }

  function postTourAjax(action, role) {
    var body = new FormData();
    body.append('action', action);
    body.append('lnkup_nonce', cfg.nonce || '');
    body.append('role', role || 'vendor');
    return fetch(cfg.url || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); });
  }

  function initDashboardTour() {
    var modal = document.getElementById('lnkup-vendor-tour-modal');
    if (!modal) {
      return;
    }
    var role = modal.getAttribute('data-tour-role') || 'vendor';
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
      if (!replay || replay.getAttribute('data-kb-role') !== 'vendor') {
        return;
      }
      e.preventDefault();
      postTourAjax('lnkup_reset_dashboard_tour', 'vendor').then(function () {
        modal.setAttribute('data-tour-auto-open', '1');
        openTour();
      }).catch(function () {
        openTour();
      });
    });

    window.lnkupOpenVendorTour = openTour;

    var welcome = document.getElementById('lnkup-vendor-welcome-modal');
    var shouldAuto = modal.getAttribute('data-tour-auto-open') === '1';
    if (shouldAuto && (!welcome || welcome.hidden)) {
      openTour();
    }
  }

  function maybeStartTourAfterWelcome() {
    var tour = document.getElementById('lnkup-vendor-tour-modal');
    if (tour && tour.getAttribute('data-tour-auto-open') === '1' && window.lnkupOpenVendorTour) {
      window.lnkupOpenVendorTour();
    }
  }

  initKnowledgeBaseReset();
  initDashboardTour();

  var root = document.querySelector('.lnkup-vendor-dash');
  if (!root) {
    return;
  }

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
    if (data && typeof data.html === 'string' && data.html.trim()) {
      return data.html;
    }
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
    var section = container.closest('.lnkup-rail');
    container.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card lnkup-rail__skeleton"></div><div class="lnkup-skeleton lnkup-skeleton--card lnkup-rail__skeleton"></div>';
    api(path, query).then(function (data) {
      var html = cardsFromPayload(data);
      container.dataset.loaded = '1';
      if (!html) {
        if (section) {
          section.hidden = true;
        } else {
          container.innerHTML = '';
        }
        return;
      }
      if (section) {
        section.hidden = false;
      }
      container.innerHTML = html;
    }).catch(function (error) {
      console.error('LNKup vendor home: could not load rail', path, error);
      container.dataset.loaded = '0';
      if (section) {
        section.hidden = false;
      }
      var retry = function () {
        loadRail(container, path, query);
      };
      if (window.LNKupUI && LNKupUI.panelError) {
        LNKupUI.panelError(container, { title: 'We couldn\u2019t load these events.', onRetry: retry });
        container.dataset.loaded = '0';
        return;
      }
      container.innerHTML = '<p class="lnkup-alert lnkup-alert--error">We couldn\u2019t load these events.</p>';
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
        loadRail(track, '/sections/recommended', { limit: 8 });
      } else if (rail === 'closing_soon') {
        loadRail(track, '/sections/trending', { section: 'closing_soon', limit: 8 });
      } else if (rail === 'trending') {
        loadRail(track, '/sections/trending', { section: 'trending', limit: 8 });
      } else if (rail === 'recent') {
        loadRail(track, '/sections/trending', { section: 'recently_added', limit: 8 });
      }
    });
  }

  function syncHomePanels(hash) {
    var panels = document.querySelectorAll('[data-vendor-home-panel]');
    if (!panels.length) {
      return;
    }
    panels.forEach(function (panel) {
      var active = panel.getAttribute('data-vendor-home-panel') === hash;
      panel.hidden = !active;
    });
    document.querySelectorAll('.lnkup-dash-subnav__link[href^="#"]').forEach(function (link) {
      var linkHash = (link.getAttribute('href') || '').replace('#', '');
      link.classList.toggle('is-active', linkHash === hash);
    });
    if (hash === 'recommendations' || hash === 'explore') {
      initRails();
    }
  }

  function closeWelcomeModal(markSeen, dismissTutorial) {
    var modal = document.getElementById('lnkup-vendor-welcome-modal');
    if (modal) {
      modal.hidden = true;
    }
    document.body.classList.remove('lnkup-modal-open');
    if (dismissTutorial) {
      postAjax('lnkup_dismiss_vendor_tutorial').then(function () {
        var checklist = document.getElementById('lnkup-vendor-getting-started');
        if (checklist) {
          checklist.hidden = true;
        }
      }).catch(function () { /* ignore */ });
      return;
    }
    if (markSeen) {
      postAjax('lnkup_vendor_welcome_seen').catch(function () { /* ignore */ });
    }
    maybeStartTourAfterWelcome();
  }

  function openWelcomeModal() {
    var modal = document.getElementById('lnkup-vendor-welcome-modal');
    if (!modal) {
      return;
    }
    modal.hidden = false;
    document.body.classList.add('lnkup-modal-open');
  }

  function initTutorialUi() {
    var modal = document.getElementById('lnkup-vendor-welcome-modal');
    if (modal) {
      openWelcomeModal();
      modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-vendor-welcome-start]')) {
          e.preventDefault();
          closeWelcomeModal(true, false);
          var checklist = document.getElementById('lnkup-vendor-getting-started');
          if (checklist && checklist.scrollIntoView) {
            checklist.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          return;
        }
        if (e.target.closest('[data-vendor-welcome-skip]')) {
          e.preventDefault();
          closeWelcomeModal(true, false);
          return;
        }
        if (e.target.closest('[data-vendor-welcome-close]')) {
          e.preventDefault();
          closeWelcomeModal(true, false);
        }
      });
    }

    document.addEventListener('click', function (e) {
      var dismissBtn = e.target.closest('[data-vendor-tutorial-dismiss]');
      if (!dismissBtn) {
        return;
      }
      e.preventDefault();
      postAjax('lnkup_dismiss_vendor_tutorial').then(function () {
        var checklist = document.getElementById('lnkup-vendor-getting-started');
        if (checklist) {
          checklist.hidden = true;
        }
        closeWelcomeModal(false, false);
      }).catch(function () { /* ignore */ });
    });
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

  if (document.querySelector('[data-vendor-home-panel]')) {
    syncHomePanels((window.location.hash || '#recommendations').replace('#', '') || 'recommendations');
  } else {
    initRails();
  }

  initTutorialUi();

  if (window.LNKupUI && LNKupUI.animateKpiCards) {
    LNKupUI.animateKpiCards(document.getElementById('lnkup-vendor-kpi-strip'));
  }
})();
