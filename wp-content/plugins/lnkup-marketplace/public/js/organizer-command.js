(function () {
  'use strict';

  var EXPAND_PANEL_IDS = ['vendors', 'lists', 'invites', 'intelligence', 'financial'];
  var SECTION_IDS = ['overview', 'events', 'applications', 'vendors', 'lists', 'invites', 'intelligence', 'create', 'financial', 'referrals'];
  var vendorSearchTimer = null;
  var ignoreObserverUntil = 0;

  function escapeHtml(str) {
    if (!str) {
      return '';
    }
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function debounce(fn, ms) {
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(vendorSearchTimer);
      vendorSearchTimer = setTimeout(function () {
        fn.apply(ctx, args);
      }, ms);
    };
  }

  function getExpandPanel(panelId) {
    return document.getElementById('lnkup-organizer-expand-' + panelId);
  }

  function onPanelExpanded(panelId) {
    if (panelId === 'vendors' && typeof window.lnkupCrmLoadDirectory === 'function') {
      window.lnkupCrmLoadDirectory();
    }
    if (panelId === 'intelligence') {
      document.dispatchEvent(new CustomEvent('lnkup-intel-expand'));
    }
    if (panelId === 'financial') {
      document.dispatchEvent(new CustomEvent('lnkup-financial-expand'));
    }
    if (panelId === 'lists') {
      if (typeof window.lnkupCrmLoadLists === 'function') {
        window.lnkupCrmLoadLists();
      }
      var nameInput = document.getElementById('lnkup-crm-new-list-name');
      if (nameInput && document.activeElement && document.activeElement.id === 'lnkup-crm-new-list-compact') {
        nameInput.focus();
      }
    }
    if (panelId === 'invites' && typeof window.lnkupCrmLoadInvites === 'function') {
      window.lnkupCrmLoadInvites();
    }
  }

  function expandPanel(panelId, scrollToSection) {
    if (!panelId) {
      return;
    }
    var panel = getExpandPanel(panelId);
    if (panel) {
      panel.hidden = false;
      panel.classList.add('is-open');
      onPanelExpanded(panelId);
    }
    if (scrollToSection !== false) {
      window.location.hash = panelId;
      var target = document.getElementById(panelId);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  }

  function collapsePanel(panelId) {
    if (!panelId) {
      return;
    }
    var panel = getExpandPanel(panelId);
    if (panel) {
      panel.hidden = true;
      panel.classList.remove('is-open');
    }
    var hash = (window.location.hash || '').replace(/^#/, '').split('&')[0];
    if (hash === panelId && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  }

  function scrollToSection(id) {
    if (!id) {
      return;
    }
    var current = (window.location.hash || '').replace(/^#/, '');
    var currentSection = current.split('&')[0];
    if (currentSection !== id) {
      window.location.hash = id;
    }
    var target = document.getElementById(id);
    if (target) {
      ignoreObserverUntil = Date.now() + 1000;
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function openCreateWizard() {
    scrollToSection('create');
    var launcher = document.getElementById('lnkup-create-launcher');
    var wrap = document.getElementById('lnkup-create-panel-wrap');
    if (launcher) {
      launcher.hidden = true;
    }
    if (wrap) {
      wrap.hidden = false;
      wrap.classList.add('is-open');
    }
  }

  function bindExpandCtAs() {
    document.addEventListener('click', function (e) {
      var collapseBtn = e.target.closest('[data-collapse-panel]');
      if (collapseBtn && collapseBtn.tagName === 'BUTTON') {
        e.preventDefault();
        collapsePanel(collapseBtn.getAttribute('data-collapse-panel'));
        return;
      }
      var expandPanelBtn = e.target.closest('[data-expand-panel]');
      if (expandPanelBtn && expandPanelBtn.tagName === 'BUTTON') {
        e.preventDefault();
        expandPanel(expandPanelBtn.getAttribute('data-expand-panel'), true);
        return;
      }
      var expandBtn = e.target.closest('[data-expand]');
      if (expandBtn) {
        e.preventDefault();
        expandPanel(expandBtn.getAttribute('data-expand'), true);
        return;
      }
      var createBtn = e.target.closest('[data-open-create]');
      if (createBtn) {
        e.preventDefault();
        openCreateWizard();
        return;
      }
      var hashLink = e.target.closest('.lnkup-dashboard__nav-link[data-hash], .lnkup-organizer-section-nav__link[data-hash]');
      if (hashLink) {
        var hash = hashLink.getAttribute('data-hash');
        if (!hash) {
          return;
        }
        e.preventDefault();
        if (EXPAND_PANEL_IDS.indexOf(hash) !== -1) {
          expandPanel(hash, false);
        }
        scrollToSection(hash);
        syncSectionNav(hash);
      }
    });
  }

  function syncSectionNav(hash) {
    hash = hash || (window.location.hash || '').replace(/^#/, '') || 'overview';
    var section = hash.split('&')[0];
    if (section === 'operations') {
      section = 'events';
    }
    document.querySelectorAll('.lnkup-organizer-section-nav__link').forEach(function (link) {
      var id = link.getAttribute('data-hash');
      var active = id === section;
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'true');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  function initSectionNavObserver() {
    var navLinks = document.querySelectorAll('.lnkup-organizer-section-nav__link');
    if (!navLinks.length || !('IntersectionObserver' in window)) {
      return;
    }
    var visible = {};
    var observer = new IntersectionObserver(function (entries) {
      if (Date.now() < ignoreObserverUntil) {
        return;
      }
      entries.forEach(function (entry) {
        var id = entry.target.id || entry.target.getAttribute('data-section');
        if (!id) {
          return;
        }
        visible[id] = entry.isIntersecting ? entry.intersectionRatio : 0;
      });
      var best = '';
      var bestRatio = 0;
      SECTION_IDS.forEach(function (id) {
        if ((visible[id] || 0) > bestRatio) {
          bestRatio = visible[id];
          best = id;
        }
      });
      if (best && bestRatio > 0.15) {
        syncSectionNav(best);
      }
    }, { rootMargin: '-20% 0px -55% 0px', threshold: [0, 0.15, 0.35, 0.55] });

    SECTION_IDS.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        observer.observe(el);
      }
    });
  }

  function loadIntelSummary() {
    var el = document.getElementById('lnkup-organizer-intel-summary');
    if (!el || el.getAttribute('data-loaded') === '1') {
      return;
    }
    if (el.getAttribute('data-ssr') === '1') {
      return;
    }
    if (typeof window.lnkupLoadOrganizerIntelSummary === 'function') {
      window.lnkupLoadOrganizerIntelSummary(el);
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
  }

  function vendorProfileUrl(profileId) {
    var ajax = window.lnkupAjax || {};
    var dash = ajax.organizerDashboardUrl || '';
    if (!dash || !profileId) {
      return '#vendors';
    }
    var sep = dash.indexOf('?') > -1 ? '&' : '?';
    return dash + sep + 'vendor=' + encodeURIComponent(String(profileId));
  }

  function renderVendorsPreviewHtml(items, isSearch) {
    if (!items || !items.length) {
      var emptyMsg = isSearch
        ? 'No vendors match your search.'
        : 'No vendor profiles in the marketplace yet.';
      return '<p class="lnkup-muted lnkup-organizer-preview-empty">' + escapeHtml(emptyMsg) + '</p>';
    }
    var html = '<div class="lnkup-organizer-vendors-preview-grid">';
    items.slice(0, 3).forEach(function (v) {
      var name = v.business_name || v.name || 'Vendor';
      var url = vendorProfileUrl(v.profile_id);
      var category = Array.isArray(v.category) && v.category.length
        ? v.category.slice(0, 2).join(', ')
        : '';
      var rating = v.avg_rating ? '★ ' + escapeHtml(String(v.avg_rating)) : '';
      var score = v.vendor_score ? escapeHtml(String(v.vendor_score)) + ' score' : '';
      var vendorUid = v.vendor_user_id || 0;
      html += '<article class="lnkup-organizer-vendor-preview-card">';
      html += '<a href="' + escapeHtml(url) + '" class="lnkup-organizer-vendor-preview-card__link">';
      if (v.logo) {
        html += '<img src="' + escapeHtml(v.logo) + '" alt="" class="lnkup-organizer-vendor-preview-card__logo" loading="lazy" />';
      }
      html += '<span class="lnkup-organizer-vendor-preview-card__main">';
      html += '<strong>' + escapeHtml(name) + '</strong>';
      if (category) {
        html += '<span class="lnkup-muted lnkup-organizer-vendor-preview-card__cat">' + escapeHtml(category) + '</span>';
      }
      if (rating || score) {
        html += '<span class="lnkup-muted lnkup-organizer-vendor-preview-card__meta">' + (rating || score) + '</span>';
      }
      html += '</span></a>';
      html += '<div class="lnkup-organizer-vendor-preview-card__actions">';
      html += '<a href="' + escapeHtml(url) + '" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">View</a>';
      if (vendorUid) {
        html += '<button type="button" class="lnkup-btn lnkup-btn--soft lnkup-btn--sm lnkup-crm-invite" data-vendor-id="' + vendorUid + '" data-vendor-name="' + escapeHtml(name) + '" data-profile-id="' + (v.profile_id || '') + '">Invite</button>';
      }
      html += '</div></article>';
    });
    html += '</div>';
    return html;
  }

  function showVendorsPreviewError(el, q) {
    if (window.LNKupUI) {
      LNKupUI.panelError(el, {
        title: 'Could not load vendors',
        compact: true,
        onRetry: function () {
          el.removeAttribute('data-loaded');
          el.removeAttribute('data-ssr');
          refreshVendorsPreview(q, true);
        }
      });
      return;
    }
    el.innerHTML = '<p class="lnkup-muted lnkup-organizer-preview-empty">' + escapeHtml('Could not load vendors.') + '</p>';
  }

  function refreshVendorsPreview(q, force) {
    var el = document.getElementById('lnkup-organizer-vendors-preview');
    if (!el) {
      return;
    }
    var ajax = window.lnkupAjax || {};
    var base = ajax.crmRestUrl || '';
    var nonce = ajax.restNonce || '';
    if (!base) {
      return;
    }
    if (!force && el.getAttribute('data-ssr') === '1' && !q) {
      return;
    }
    if (!force && el.getAttribute('data-loaded') === '1' && !q) {
      return;
    }
    el.removeAttribute('data-ssr');
    if (window.LNKupUI && typeof LNKupUI.panelSkeleton === 'function') {
      LNKupUI.panelSkeleton(el, 3);
    } else {
      el.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
    }
    var qs = '?scope=discover&view=card&sort=score&per_page=3';
    if (q) {
      qs += '&q=' + encodeURIComponent(q);
    }
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeoutId = setTimeout(function () {
      if (controller) {
        controller.abort();
      }
    }, 8000);
    fetch(base + '/vendors' + qs, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce },
      signal: controller ? controller.signal : undefined
    }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (!r.ok || ct.indexOf('application/json') === -1) {
        throw new Error('Invalid vendor preview response');
      }
      return r.json();
    }).then(function (res) {
      if (res && res.code) {
        throw new Error(res.message || 'Vendor preview failed');
      }
      var items = (res && res.items) ? res.items : [];
      el.innerHTML = renderVendorsPreviewHtml(items, !!q);
      el.setAttribute('data-loaded', '1');
      el.setAttribute('data-preview-q', q || '');
    }).catch(function () {
      showVendorsPreviewError(el, q);
    }).finally(function () {
      clearTimeout(timeoutId);
    });
  }

  function loadVendorsPreview() {
    var el = document.getElementById('lnkup-organizer-vendors-preview');
    if (el && el.getAttribute('data-ssr') === '1') {
      return;
    }
    refreshVendorsPreview('', false);
  }

  function bindVendorSearch() {
    var searchInput = document.getElementById('lnkup-crm-vendor-search');
    if (!searchInput) {
      return;
    }
    var onInput = debounce(function (e) {
      var q = (e.target.value || '').trim();
      var preview = document.getElementById('lnkup-organizer-vendors-preview');
      if (preview) {
        preview.removeAttribute('data-loaded');
        preview.removeAttribute('data-ssr');
      }
      refreshVendorsPreview(q, true);
      if (q) {
        expandPanel('vendors', false);
        if (typeof window.lnkupCrmLoadDirectory === 'function') {
          window.lnkupCrmLoadDirectory();
        }
      }
    }, 350);
    searchInput.addEventListener('input', onInput);
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var q = (searchInput.value || '').trim();
        expandPanel('vendors', true);
        if (typeof window.lnkupCrmLoadDirectory === 'function') {
          window.lnkupCrmLoadDirectory();
        }
        refreshVendorsPreview(q, true);
      }
    });
  }

  function bindWizardSteps() {
    var wizard = document.getElementById('lnkup-event-create-phase');
    if (!wizard) {
      return;
    }
    var steps = ['lnkup-create-step-1', 'lnkup-create-step-2', 'lnkup-create-step-3', 'lnkup-create-step-4'];
    var stepNums = {
      'lnkup-create-step-1': '1',
      'lnkup-create-step-2': '2',
      'lnkup-create-step-3': '3',
      'lnkup-create-step-4': '4'
    };

    function syncOpenVendorAppsFields() {
      var openCb = document.getElementById('lnkup_open_vendor_applications');
      var pricing = document.getElementById('lnkup-create-vendor-pricing');
      var fee = document.getElementById('create_vendor_fee');
      var spots = document.getElementById('create_spots_total');
      var open = !openCb || openCb.checked;
      if (pricing) {
        pricing.hidden = !open;
      }
      if (fee) {
        fee.disabled = !open;
        fee.required = open;
      }
      if (spots) {
        spots.disabled = !open;
        spots.required = open;
      }
    }

    function setWizardStep(stepId) {
      var num = stepNums[stepId] || '1';
      wizard.setAttribute('data-wizard-step', num);
      document.querySelectorAll('.lnkup-organizer-wizard-steps [data-wizard-step]').forEach(function (btn) {
        var target = btn.getAttribute('data-wizard-step');
        btn.classList.toggle('is-active', target === stepId);
      });
      var prevBtn = wizard.querySelector('[data-wizard-nav="prev"]');
      var nextBtn = wizard.querySelector('[data-wizard-nav="next"]');
      var publishBtns = wizard.querySelectorAll('#lnkup-create-event-submit-footer, #lnkup-create-event-submit');
      if (prevBtn) prevBtn.hidden = stepId === 'lnkup-create-step-1';
      if (nextBtn) nextBtn.hidden = stepId === 'lnkup-create-step-4';
      publishBtns.forEach(function (b) { b.hidden = stepId !== 'lnkup-create-step-4'; });
      if (typeof window.lnkupSyncPublishSummary === 'function') {
        window.lnkupSyncPublishSummary();
      }
    }

    function getCurrentStepId() {
      return steps.find(function (id) {
        return wizard.getAttribute('data-wizard-step') === stepNums[id];
      }) || 'lnkup-create-step-1';
    }

    /**
     * Validate required controls in a wizard step section (skip disabled).
     * @param {string} stepId
     * @return {HTMLElement|null} First invalid control, or null.
     */
    function firstInvalidInStep(stepId) {
      var section = document.getElementById(stepId);
      if (!section) {
        return null;
      }
      var fields = section.querySelectorAll('input, select, textarea');
      for (var i = 0; i < fields.length; i++) {
        var el = fields[i];
        if (el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') {
          continue;
        }
        if (typeof el.checkValidity === 'function' && !el.checkValidity()) {
          return el;
        }
      }
      return null;
    }

    function validateStep(stepId) {
      var bad = firstInvalidInStep(stepId);
      if (!bad) {
        return true;
      }
      if (typeof bad.reportValidity === 'function') {
        bad.reportValidity();
      }
      try {
        bad.focus();
      } catch (e) {
        // ignore
      }
      if (window.lnkupShowToast) {
        window.lnkupShowToast('Please complete the required fields before continuing.', 'error');
      }
      return false;
    }

    document.querySelectorAll('.lnkup-organizer-wizard-steps [data-wizard-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var stepId = btn.getAttribute('data-wizard-step');
        if (!stepId) {
          return;
        }
        var current = getCurrentStepId();
        var currentIdx = steps.indexOf(current);
        var targetIdx = steps.indexOf(stepId);
        // Moving forward: require current step valid.
        if (targetIdx > currentIdx && !validateStep(current)) {
          return;
        }
        setWizardStep(stepId);
      });
    });

    wizard.querySelectorAll('[data-wizard-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dir = btn.getAttribute('data-wizard-nav');
        var current = getCurrentStepId();
        var idx = steps.indexOf(current);
        if (dir === 'next' && idx < steps.length - 1) {
          if (!validateStep(current)) {
            return;
          }
          setWizardStep(steps[idx + 1]);
        } else if (dir === 'prev' && idx > 0) {
          setWizardStep(steps[idx - 1]);
        }
      });
    });

    var openAppsCb = document.getElementById('lnkup_open_vendor_applications');
    if (openAppsCb) {
      openAppsCb.addEventListener('change', function () {
        syncOpenVendorAppsFields();
        if (typeof window.lnkupSyncPublishSummary === 'function') {
          window.lnkupSyncPublishSummary();
        }
      });
    }
    syncOpenVendorAppsFields();

    setWizardStep('lnkup-create-step-1');
    window.lnkupOrganizerSetWizardStep = setWizardStep;
    window.lnkupOrganizerValidateCreateStep = validateStep;
    window.lnkupOrganizerFirstInvalidCreateField = function () {
      for (var i = 0; i < steps.length; i++) {
        var bad = firstInvalidInStep(steps[i]);
        if (bad) {
          return { field: bad, stepId: steps[i] };
        }
      }
      return null;
    };
  }

  function handleHashDeepLink() {
    var raw = (window.location.hash || '').replace(/^#/, '');
    if (!raw) {
      return;
    }
    var section = raw.split('&')[0];
    if (section === 'create') {
      openCreateWizard();
      return;
    }
    if (EXPAND_PANEL_IDS.indexOf(section) !== -1) {
      expandPanel(section, false);
      scrollToSection(section);
      return;
    }
    if (section === 'invites' || section === 'lists') {
      expandPanel(section, false);
    }
    if (section === 'operations') {
      scrollToSection('operations');
      return;
    }
    if (section === 'applications') {
      scrollToSection('applications');
      return;
    }
    scrollToSection(section);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('lnkup-organizer-crm')) {
      return;
    }
    bindExpandCtAs();
    bindWizardSteps();
    bindVendorSearch();
    syncSectionNav();
    initSectionNavObserver();
    loadIntelSummary();
    loadVendorsPreview();
    handleHashDeepLink();
    window.addEventListener('hashchange', function () {
      syncSectionNav();
      handleHashDeepLink();
    });
  });

  window.lnkupOrganizerOpenSection = scrollToSection;
  window.lnkupOrganizerExpandPanel = expandPanel;
  window.lnkupOrganizerCollapsePanel = collapsePanel;
  window.lnkupRefreshVendorsPreview = refreshVendorsPreview;
})();
