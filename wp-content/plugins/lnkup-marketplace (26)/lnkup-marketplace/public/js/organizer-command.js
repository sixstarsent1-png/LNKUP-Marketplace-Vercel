(function () {
  'use strict';

  var EXPAND_PANEL_IDS = ['vendors', 'lists', 'invites', 'intelligence', 'financial'];
  var SECTION_IDS = ['overview', 'events', 'applications', 'vendors', 'lists', 'invites', 'intelligence', 'create', 'financial'];
  var vendorSearchTimer = null;

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
      var nameInput = document.getElementById('lnkup-crm-new-list-name');
      if (nameInput && document.activeElement && document.activeElement.id === 'lnkup-crm-new-list-compact') {
        nameInput.focus();
      }
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

  function scrollToSection(id) {
    if (!id) {
      return;
    }
    window.location.hash = id;
    var target = document.getElementById(id);
    if (target) {
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
      }
    });
  }

  function syncSectionNav(hash) {
    hash = hash || (window.location.hash || '').replace(/^#/, '') || 'overview';
    if (hash === 'operations' || hash === 'applications') {
      hash = 'events';
    }
    document.querySelectorAll('.lnkup-organizer-section-nav__link').forEach(function (link) {
      var id = link.getAttribute('data-hash');
      var active = id === hash;
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
    el.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
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
    var steps = ['lnkup-create-step-1', 'lnkup-create-step-2', 'lnkup-create-step-3'];
    var stepNums = { 'lnkup-create-step-1': '1', 'lnkup-create-step-2': '2', 'lnkup-create-step-3': '3' };

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
      if (nextBtn) nextBtn.hidden = stepId === 'lnkup-create-step-3';
      publishBtns.forEach(function (b) { b.hidden = stepId !== 'lnkup-create-step-3'; });
    }

    document.querySelectorAll('.lnkup-organizer-wizard-steps [data-wizard-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var stepId = btn.getAttribute('data-wizard-step');
        if (stepId) {
          setWizardStep(stepId);
        }
      });
    });

    wizard.querySelectorAll('[data-wizard-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dir = btn.getAttribute('data-wizard-nav');
        var current = steps.find(function (id) {
          return wizard.getAttribute('data-wizard-step') === stepNums[id];
        }) || 'lnkup-create-step-1';
        var idx = steps.indexOf(current);
        if (dir === 'next' && idx < steps.length - 1) {
          setWizardStep(steps[idx + 1]);
        } else if (dir === 'prev' && idx > 0) {
          setWizardStep(steps[idx - 1]);
        }
      });
    });

    setWizardStep('lnkup-create-step-1');
  }

  function handleHashDeepLink() {
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (!hash) {
      return;
    }
    if (hash === 'create') {
      openCreateWizard();
      return;
    }
    if (EXPAND_PANEL_IDS.indexOf(hash) !== -1) {
      expandPanel(hash, false);
      scrollToSection(hash);
      return;
    }
    if (hash === 'invites' || hash === 'lists') {
      expandPanel(hash, false);
    }
    scrollToSection(hash);
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
  window.lnkupRefreshVendorsPreview = refreshVendorsPreview;
})();
