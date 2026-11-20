document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('lnkup-menu-toggle');
  var sidebar = document.getElementById('lnkup-sidebar');
  var overlay = document.getElementById('lnkup-sidebar-overlay');
  var searchToggle = document.getElementById('lnkup-search-toggle');
  var header = document.querySelector('.lnkup-workspace-bar');
  var searchForm = document.getElementById('lnkup-dashboard-search-form');

  var bottomMore = document.getElementById('lnkup-bottom-more');
  var workspaceMore = document.getElementById('lnkup-workspace-more');
  var sidebarReturn = null;

  function sidebarFocusables() {
    if (!sidebar) return [];
    return Array.prototype.slice.call(sidebar.querySelectorAll('a, button, summary, input, select, textarea')).filter(function (el) {
      return !el.hasAttribute('disabled') && el.getAttribute('aria-disabled') !== 'true' && el.tabIndex !== -1;
    });
  }

  function setSidebarOpen(open, returnTo) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (overlay) overlay.hidden = !open;
    document.body.classList.toggle('lnkup-sidebar-open', open);
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (bottomMore) {
      bottomMore.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (workspaceMore) {
      workspaceMore.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      sidebarReturn = returnTo || document.activeElement;
      var focusTarget = sidebar.querySelector('a, button, summary');
      if (focusTarget) {
        try {
          focusTarget.focus();
        } catch (e) {
          /* ignore */
        }
      }
    } else if (sidebarReturn && typeof sidebarReturn.focus === 'function') {
      try {
        sidebarReturn.focus();
      } catch (err) {
        /* ignore */
      }
      sidebarReturn = null;
    }
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      setSidebarOpen(!sidebar.classList.contains('is-open'), toggle);
    });
  }

  if (overlay) {
    overlay.addEventListener('click', function () {
      setSidebarOpen(false);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && profileMenu && !profileMenu.hidden) {
      setProfileOpen(false);
      return;
    }
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) {
      setSidebarOpen(false);
      return;
    }
    if (e.key === 'Tab' && sidebar && sidebar.classList.contains('is-open') && window.matchMedia('(max-width: 1023px)').matches) {
      var items = sidebarFocusables();
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
    if (e.key === 'Escape' && header && header.classList.contains('is-search-open')) {
      header.classList.remove('is-search-open');
      if (searchToggle) {
        searchToggle.setAttribute('aria-expanded', 'false');
      }
    }
  });

  if (searchToggle && header && searchForm) {
    searchToggle.addEventListener('click', function () {
      var open = !header.classList.contains('is-search-open');
      header.classList.toggle('is-search-open', open);
      searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        var input = searchForm.querySelector('input[type="search"]');
        if (input) {
          try {
            input.focus();
          } catch (e) {
            /* ignore */
          }
        }
      }
    });
  }

  if (bottomMore) {
    bottomMore.addEventListener('click', function () {
      setSidebarOpen(true);
    });
  }

  var profileBtn = document.getElementById('lnkup-workspace-profile');
  var profileMenu = document.getElementById('lnkup-workspace-profile-menu');

  function setProfileOpen(open) {
    if (!profileBtn || !profileMenu) {
      return;
    }
    profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    profileMenu.hidden = !open;
  }

  if (profileBtn && profileMenu) {
    profileBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      setProfileOpen(profileMenu.hidden);
    });
    document.addEventListener('click', function (event) {
      if (profileMenu.hidden) {
        return;
      }
      if (profileBtn.contains(event.target) || profileMenu.contains(event.target)) {
        return;
      }
      setProfileOpen(false);
    });
    var copyBtn = profileMenu.querySelector('[data-copy-url]');
    if (copyBtn) {
      copyBtn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var url = copyBtn.getAttribute('data-copy-url') || '';
        var original = copyBtn.textContent;
        function markCopied() {
          copyBtn.textContent = 'Copied';
          window.setTimeout(function () {
            copyBtn.textContent = original;
          }, 1500);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(markCopied).catch(function () {});
          return;
        }
        markCopied();
      });
    }
  }

  if (workspaceMore && sidebar) {
    workspaceMore.addEventListener('click', function () {
      setProfileOpen(false);
      setSidebarOpen(!sidebar.classList.contains('is-open'), workspaceMore);
    });
  }

  document.querySelectorAll('.lnkup-dashboard__nav-link.is-locked, .lnkup-dashboard__bottom-link.is-locked').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
    });
  });

  /**
   * Strip filter/query suffixes from organizer hashes.
   */
  function sectionHash(raw) {
    var hash = (raw || '').replace(/^#/, '');
    if (!hash) {
      return '';
    }
    return hash.split('&')[0].split('?')[0];
  }

  /**
   * Legacy organizer hashes redirect to current destinations.
   */
  function normalizeOrganizerHash() {
    var hash = sectionHash(window.location.hash || '');
    if (window.location.pathname.indexOf('organizer-dashboard') === -1) {
      return hash;
    }
    var full = (window.location.hash || '').replace(/^#/, '');
    var pricingMatch = full.match(/^pricing-(\d+)$/);
    if (pricingMatch) {
      document.documentElement.setAttribute('data-lnkup-pricing-event', pricingMatch[1]);
      return 'events';
    }
    if (hash === 'create-opportunity') {
      document.documentElement.removeAttribute('data-lnkup-create-mode');
      return 'events';
    }
    var target = hash;
    if (hash === 'payments') {
      target = 'financial';
    } else if (hash === 'import' || hash === 'create-event') {
      target = 'create';
    }
    if (target !== hash && target === sectionHash(full)) {
      if (window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + target);
      } else {
        window.location.hash = target;
      }
      hash = target;
    } else if (target !== hash) {
      hash = target;
    }
    return hash;
  }

  /**
   * Whether a hash matches a nav link (including data-also-active aliases).
   */
  function hashMatchesNavLink(link, hash) {
    if (!link) return false;
    var linkHash = link.getAttribute('data-hash') || '';
    if (linkHash === hash) return true;
    var also = link.getAttribute('data-also-active') || '';
    if (!also) return false;
    return also.split(',').some(function (h) { return h.trim() === hash; });
  }

  var organizerTitles = {
    overview: 'Home',
    events: 'Events',
    applications: 'Vendor requests',
    vendors: 'Discover vendors',
    financial: 'Financial',
    help: 'Help',
    create: 'Launch event',
    lists: 'Vendor Lists',
    invites: 'Invites',
    referrals: 'Referral Invites',
    operations: 'Operations',
    intelligence: 'Intelligence'
  };

  /**
   * Update organizer shell H1 from hash.
   */
  function syncOrganizerShellTitle(hash) {
    var titleEl = document.getElementById('lnkup-dashboard-shell-title');
    if (!titleEl || titleEl.getAttribute('data-lnkup-role') !== 'organizer') {
      return;
    }
    var key = hash || 'overview';
    if (organizerTitles[key]) {
      titleEl.textContent = organizerTitles[key];
    }
  }

  /**
   * Highlight nav for hash anchors (organizer).
   */
  function syncHashNav() {
    if (window.location.pathname.indexOf('organizer-dashboard') > -1 && !(window.location.hash || '').replace(/^#/, '')) {
      return;
    }
    var hash = normalizeOrganizerHash();
    if (!hash || hash === 'overview') {
      hash = hash || 'overview';
    }
    document.querySelectorAll('.lnkup-dashboard__nav-link[data-hash], .lnkup-dashboard__nav-link[data-also-active]').forEach(function (link) {
      var active = hashMatchesNavLink(link, hash);
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('.lnkup-dashboard__bottom-link[data-hash], .lnkup-dashboard__bottom-link[data-also-active]').forEach(function (link) {
      link.classList.toggle('is-active', hashMatchesNavLink(link, hash));
    });
    if (window.location.pathname.indexOf('organizer-dashboard') > -1) {
      return;
    }
    document.querySelectorAll('.lnkup-dashboard__bottom-link[data-hash="overview"]').forEach(function (link) {
      if (hash === 'overview' || !window.location.hash.replace(/^#/, '')) {
        link.classList.add('is-active');
      }
    });

    var navMore = document.querySelector('.lnkup-dashboard__nav-more');
    if (navMore) {
      var moreActive = navMore.querySelector('.lnkup-dashboard__nav-link.is-active');
      if (moreActive) {
        navMore.setAttribute('open', '');
      }
    }

    syncOrganizerShellTitle(hash);
  }

  /**
   * Highlight nav for vendor ?view= param.
   */
  function syncViewNav() {
    var params = new URLSearchParams(window.location.search);
    var view = params.get('view') || '';
    var path = window.location.pathname;

    document.querySelectorAll('.lnkup-dashboard__nav-link').forEach(function (link) {
      link.classList.remove('is-active');
      link.removeAttribute('aria-current');
    });
    document.querySelectorAll('.lnkup-dashboard__bottom-link[data-view], .lnkup-dashboard__bottom-link[data-slug]').forEach(function (link) {
      link.classList.remove('is-active');
    });

    document.querySelectorAll('.lnkup-dashboard__nav-link[data-view]').forEach(function (link) {
      var linkView = link.getAttribute('data-view') || '';
      if (linkView && linkView === view && path.indexOf('vendor-dashboard') > -1) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      }
    });

    var homeLink = document.querySelector('.lnkup-dashboard__nav-link[href*="vendor-dashboard"]:not([data-view])');
    if (homeLink && path.indexOf('vendor-dashboard') > -1 && !view) {
      homeLink.classList.add('is-active');
      homeLink.setAttribute('aria-current', 'page');
    }

    if (path.indexOf('opportunities') > -1) {
      document.querySelectorAll('.lnkup-dashboard__nav-link[href*="opportunities"]').forEach(function (link) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      });
      document.querySelectorAll('.lnkup-dashboard__bottom-link[data-slug="opportunities"]').forEach(function (link) {
        link.classList.add('is-active');
      });
    }

    if (path.indexOf('favorites') > -1) {
      document.querySelectorAll('.lnkup-dashboard__nav-link[href*="favorites"]').forEach(function (link) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      });
    }

    if (path.indexOf('vendor-profile') > -1) {
      document.querySelectorAll('.lnkup-dashboard__nav-link[href*="vendor-profile"]').forEach(function (link) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      });
    }

    document.querySelectorAll('.lnkup-dashboard__bottom-link[data-view]').forEach(function (link) {
      var linkView = link.getAttribute('data-view') || '';
      if (linkView === view && path.indexOf('vendor-dashboard') > -1) {
        link.classList.add('is-active');
      }
      if (!view && linkView === '' && path.indexOf('vendor-dashboard') > -1 && !link.getAttribute('data-slug')) {
        link.classList.add('is-active');
      }
    });

    if (path.indexOf('organizer-dashboard') > -1) {
      var orgView = view || (params.get('event') ? 'events' : 'home');
      if (orgView === 'overview' || orgView === 'payments') {
        orgView = orgView === 'payments' ? 'financial' : 'home';
      }
      document.querySelectorAll('.lnkup-dashboard__nav-link[data-view], .lnkup-dashboard__bottom-link[data-view]').forEach(function (link) {
        var linkView = link.getAttribute('data-view') || '';
        var active = linkView === orgView;
        link.classList.toggle('is-active', active);
        if (link.classList.contains('lnkup-dashboard__nav-link')) {
          if (active) {
            link.setAttribute('aria-current', 'page');
          } else {
            link.removeAttribute('aria-current');
          }
        }
      });
      var orgMore = document.querySelector('.lnkup-dashboard__nav-more');
      if (orgMore && orgMore.querySelector('.lnkup-dashboard__nav-link.is-active')) {
        orgMore.setAttribute('open', '');
      }
      var workspaceEvent = params.get('event') || params.get('event_filter');
      if (workspaceEvent && /^\d+$/.test(workspaceEvent)) {
        document.querySelectorAll('.lnkup-dashboard__nav-link[data-view="applications"], .lnkup-dashboard__bottom-link[data-view="applications"]').forEach(function (link) {
          try {
            var url = new URL(link.href, window.location.origin);
            url.searchParams.set('event_filter', workspaceEvent);
            link.href = url.toString();
          } catch (e) {
            // Leave the link unchanged if the URL cannot be parsed.
          }
        });
      }
    }
  }

  function scrollOrganizerHash() {
    if (!document.querySelector('.lnkup-organizer-dash')) {
      return;
    }
    var hash = (window.location.hash || '').replace(/^#/, '').split('&')[0];
    if (!hash || ['applications', 'events', 'financial', 'create', 'operations'].indexOf(hash) === -1) {
      return;
    }
    var go = function () {
      if (typeof window.lnkupOrganizerOpenSection === 'function') {
        window.lnkupOrganizerOpenSection(hash);
        return;
      }
      var el = document.getElementById(hash);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(go);
    });
    window.setTimeout(go, 150);
  }

  function redirectOrganizerLegacyHash() {
    if (window.location.pathname.indexOf('organizer-dashboard') === -1) {
      return false;
    }
    var raw = (window.location.hash || '').replace(/^#/, '');
    if (!raw) {
      return false;
    }
    var section = raw.split('&')[0].split('?')[0];
    var params = new URLSearchParams(window.location.search);
    var map = { overview: 'home', payments: 'financial', import: 'create', 'create-event': 'create', 'create-opportunity': 'events' };
    var pricing = section.match(/^pricing-(\d+)$/);
    if (pricing) {
      params.set('view', 'events');
      params.set('event', pricing[1]);
      params.set('edit', '1');
    } else {
      var view = map[section] || section || 'home';
      var allowed = ['home', 'events', 'applications', 'financial', 'vendors', 'lists', 'invites', 'referrals', 'operations', 'intelligence', 'create', 'help'];
      if (allowed.indexOf(view) === -1) {
        view = 'home';
      }
      params.set('view', view);
    }
    raw.split('&').slice(1).forEach(function (part) {
      var bits = part.split('=');
      if (!bits[0]) {
        return;
      }
      var key = decodeURIComponent(bits[0]);
      var value = decodeURIComponent(bits.slice(1).join('=') || '');
      if (key === 'event' && params.get('view') === 'applications') {
        key = 'event_filter';
      }
      params.set(key, value);
    });
    window.location.replace(window.location.pathname + '?' + params.toString());
    return true;
  }

  window.addEventListener('hashchange', function () {
    if (redirectOrganizerLegacyHash()) {
      return;
    }
    syncHashNav();
  });
  window.addEventListener('hashchange', scrollOrganizerHash);
  syncHashNav();
  syncViewNav();
  scrollOrganizerHash();

  /**
   * Vendor home subnav: show one section at a time (Overview / Upcoming / Recommendations).
   */
  function syncVendorHomeSubnav() {
    var root = document.querySelector('.lnkup-vendor-dash');
    if (!root) {
      return;
    }
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (!hash || hash === 'overview') {
      hash = 'overview';
    }
    var valid = ['overview', 'upcoming', 'recommendations'];
    if (valid.indexOf(hash) === -1) {
      return;
    }
    document.querySelectorAll('[data-vendor-home-panel]').forEach(function (panel) {
      var active = panel.getAttribute('data-vendor-home-panel') === hash;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
    document.querySelectorAll('.lnkup-dash-subnav__link[data-subnav]').forEach(function (link) {
      var id = link.getAttribute('data-subnav');
      link.classList.toggle('is-active', id === hash);
      if (id === hash) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  window.addEventListener('hashchange', syncVendorHomeSubnav);
  syncVendorHomeSubnav();

  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      var id = anchor.getAttribute('href');
      if (!id || id === '#') return;
      var target = document.querySelector(id);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (sidebar && sidebar.classList.contains('is-open')) setSidebarOpen(false);
      }
    });
  });

  /**
   * Application table tabs. Selection is stored in app_filter so reload and back work.
   */
  function applyVendorAppFilter(tab, updateUrl) {
    document.querySelectorAll('.lnkup-dash-tabs__btn[data-app-tab]').forEach(function (b) {
      var isActive = b.getAttribute('data-app-tab') === tab;
      b.classList.toggle('is-active', isActive);
      b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('#lnkup-vendor-apps-table tbody tr').forEach(function (row) {
      var rowTab = row.getAttribute('data-app-tab');
      var show = tab === 'all' || rowTab === tab;
      row.hidden = !show;
    });
    document.querySelectorAll('#lnkup-vendor-apps-workflow .lnkup-workflow-card').forEach(function (card) {
      var filterTab = card.getAttribute('data-app-filter') || card.getAttribute('data-app-tab');
      var show = tab === 'all' || filterTab === tab;
      card.hidden = !show;
    });
    document.querySelectorAll('#lnkup-vendor-apps-workflow .lnkup-workflow-section').forEach(function (section) {
      var visible = section.querySelectorAll('.lnkup-workflow-card:not([hidden])').length > 0;
      section.hidden = !visible && tab !== 'all';
    });
    var filterEmpty = document.getElementById('lnkup-vendor-apps-filter-empty');
    if (filterEmpty) {
      filterEmpty.hidden = tab === 'all' || document.querySelectorAll('#lnkup-vendor-apps-workflow .lnkup-workflow-card:not([hidden])').length > 0;
    }
    if (updateUrl && window.history.pushState) {
      var url = new URL(window.location.href);
      if (!tab || tab === 'all') {
        url.searchParams.delete('app_filter');
      } else {
        url.searchParams.set('app_filter', tab);
      }
      window.history.pushState({ app_filter: tab }, '', url.toString());
    }
  }

  document.querySelectorAll('[data-app-tab]').forEach(function (btn) {
    if (btn.tagName !== 'BUTTON') return;
    btn.addEventListener('click', function () {
      applyVendorAppFilter(btn.getAttribute('data-app-tab') || 'all', true);
    });
  });

  var initialAppFilter = new URLSearchParams(window.location.search).get('app_filter') || 'all';
  if (document.querySelector('[data-app-tab]')) {
    applyVendorAppFilter(initialAppFilter, false);
  }
  window.addEventListener('popstate', function () {
    if (!document.querySelector('[data-app-tab]')) return;
    var next = new URLSearchParams(window.location.search).get('app_filter') || 'all';
    applyVendorAppFilter(next, false);
  });

  /**
   * Document card upload triggers.
   */
  document.querySelectorAll('[data-doc-upload]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var type = trigger.getAttribute('data-doc-upload');
      var form = document.getElementById('lnkup-document-upload-form');
      if (!form) return;
      var select = form.querySelector('#document_type');
      var file = form.querySelector('#lnkup_document');
      if (select && type) select.value = type;
      if (file) file.click();
    });
  });

  var docFile = document.getElementById('lnkup_document');
  if (docFile) {
    docFile.addEventListener('change', function () {
      if (docFile.files && docFile.files.length) {
        var form = document.getElementById('lnkup-document-upload-form');
        if (form) form.requestSubmit();
      }
    });
  }
});
