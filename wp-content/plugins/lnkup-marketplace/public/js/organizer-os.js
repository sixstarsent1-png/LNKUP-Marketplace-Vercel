/**
 * Organizer OS screen behavior. Shell, drawers, dialogs and table selection come from lnkup-os.js.
 */
(function () {
  'use strict';

  var OS = window.LNKupOS || null;
  var STORE_LAYOUT = 'lnkupOosEventsLayout';

  function read(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function write(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* storage disabled */
    }
  }

  /** Events portfolio: table or grid, remembered per browser. */
  function bindLayoutToggle() {
    var toggle = document.querySelector('[data-oos-layout-toggle]');
    if (!toggle) {
      return;
    }
    function apply(layout) {
      document.querySelectorAll('[data-oos-layout]').forEach(function (el) {
        el.hidden = el.getAttribute('data-oos-layout') !== layout;
      });
      toggle.querySelectorAll('[data-layout]').forEach(function (btn) {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-layout') === layout ? 'true' : 'false');
      });
    }
    var saved = read(STORE_LAYOUT);
    if (saved === 'table' || saved === 'grid') {
      apply(saved);
    }
    toggle.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-layout]');
      if (!btn) {
        return;
      }
      var layout = btn.getAttribute('data-layout');
      apply(layout);
      write(STORE_LAYOUT, layout);
    });
  }

  /** "New → Duplicate an event" lands here with ?duplicate=1. */
  function bindDuplicatePicker() {
    var picker = document.getElementById('lnkup-duplicate-picker');
    if (!picker || !OS) {
      return;
    }
    document.addEventListener('click', function (e) {
      var link = e.target.closest && e.target.closest('a.vos-menu__item[href*="duplicate=1"]');
      if (link && window.location.search.indexOf('view=events') !== -1) {
        e.preventDefault();
        OS.open(picker, link);
      }
    });
    var params = new URLSearchParams(window.location.search);
    if (params.get('duplicate') === '1') {
      OS.open(picker);
      params.delete('duplicate');
      if (window.history && window.history.replaceState) {
        var qs = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
      }
    }
  }

  /** Event switcher keeps the current tab (the option values already carry it). */
  function bindSwitcher() {
    var select = document.querySelector('[data-oos-switcher]');
    if (!select) {
      return;
    }
    select.addEventListener('change', function () {
      if (select.value) {
        window.location.href = select.value;
      }
    });
  }

  function showDrawerError(drawer, message) {
    var box = drawer.querySelector('[data-oos-inv-error]');
    if (!box) {
      return;
    }
    box.textContent = message;
    box.className = 'vos-notice vos-notice--danger';
    box.hidden = !message;
  }

  /** Inventory drawer: PATCH /organizer/opportunities/{id}/pricing (server refuses capacity below held + confirmed). */
  function bindInventory() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-oos-inventory-save]');
      if (!btn || !OS) {
        return;
      }
      var drawer = btn.closest('[data-vos-overlay]');
      var form = drawer ? drawer.querySelector('[data-oos-inventory-form]') : null;
      if (!form) {
        return;
      }
      var title = form.elements.title.value.trim();
      var fee = Number(form.elements.vendor_fee.value);
      var spots = parseInt(form.elements.spots_total.value, 10);
      var floor = parseInt(form.elements.spots_total.getAttribute('data-oos-floor') || '0', 10);
      if (!title) {
        showDrawerError(drawer, 'Give the booth type a name.');
        form.elements.title.focus();
        return;
      }
      if (!(fee >= 0)) {
        showDrawerError(drawer, 'Enter a price of $0 or more.');
        form.elements.vendor_fee.focus();
        return;
      }
      if (!(spots >= Math.max(1, floor))) {
        showDrawerError(drawer, 'Total spaces can’t be lower than ' + Math.max(1, floor) + ' (spaces already held or confirmed).');
        form.elements.spots_total.focus();
        return;
      }
      showDrawerError(drawer, '');
      btn.disabled = true;
      OS.fetchJson('organizer/opportunities/' + encodeURIComponent(btn.getAttribute('data-oos-inventory-save')) + '/pricing', {
        method: 'PATCH',
        body: { title: title, vendor_fee: fee, spots_total: spots }
      }).then(function (res) {
        btn.disabled = false;
        if (!res.ok) {
          showDrawerError(drawer, (res.data && res.data.message) ? res.data.message : 'Could not save this booth type. Try again.');
          return;
        }
        OS.close(drawer, true);
        OS.toast('Booth type saved.', 'success');
        window.location.reload();
      });
    });
  }

  /**
   * Application review drawer: moves the row's existing application card into the drawer (its
   * decision buttons keep their handlers) and back on close. Previous/Next follow the table order.
   */
  function bindApplicationDrawer() {
    var drawer = document.getElementById('oos-app-drawer');
    var store = document.querySelector('[data-oos-app-cards]');
    if (!drawer || !store || !OS) {
      return;
    }
    // The card store is hidden; overlays rendered inside it (application intel) must live outside.
    var intelDrawer = store.querySelector('#lnkup-intel-drawer');
    if (intelDrawer) {
      store.parentNode.insertBefore(intelDrawer, store.nextSibling);
    }
    var slot = drawer.querySelector('[data-oos-app-slot]');
    var title = drawer.querySelector('h2');
    var pos = drawer.querySelector('[data-oos-app-pos]');
    var current = 0;

    function order() {
      return Array.prototype.map.call(document.querySelectorAll('[data-oos-app-open]'), function (btn) {
        return btn.getAttribute('data-oos-app-open');
      });
    }

    function park() {
      var card = slot.querySelector('[data-app-id]');
      if (card) {
        store.appendChild(card);
      }
    }

    function show(appId, trigger) {
      var card = store.querySelector('[data-app-id="' + appId + '"]') || slot.querySelector('[data-app-id="' + appId + '"]');
      if (!card) {
        return;
      }
      park();
      slot.appendChild(card);
      current = appId;
      var ids = order();
      var at = ids.indexOf(String(appId));
      var opener = document.querySelector('[data-oos-app-open="' + appId + '"]');
      if (title && opener) {
        title.textContent = opener.textContent;
      }
      if (pos) {
        pos.textContent = (at + 1) + ' of ' + ids.length;
      }
      drawer.querySelector('[data-oos-app-nav="prev"]').disabled = at <= 0;
      drawer.querySelector('[data-oos-app-nav="next"]').disabled = at === -1 || at >= ids.length - 1;
      if (drawer.hidden) {
        OS.open(drawer, trigger);
      }
    }

    document.addEventListener('click', function (e) {
      var open = e.target.closest && e.target.closest('[data-oos-app-open]');
      if (open) {
        show(open.getAttribute('data-oos-app-open'), open);
        return;
      }
      var nav = e.target.closest && e.target.closest('[data-oos-app-nav]');
      if (nav && drawer.contains(nav)) {
        var ids = order();
        var at = ids.indexOf(String(current));
        var next = nav.getAttribute('data-oos-app-nav') === 'next' ? ids[at + 1] : ids[at - 1];
        if (next) {
          show(next);
        }
      }
    });
    drawer.addEventListener('vos:close', park);

    var deep = new URLSearchParams(window.location.search).get('app');
    if (deep && document.querySelector('[data-oos-app-open="' + deep + '"]')) {
      show(deep, document.querySelector('[data-oos-app-open="' + deep + '"]'));
    }
  }

  /** Before approving in bulk, compare the selection with open spaces per event. */
  function bindBulkCheck() {
    var note = document.querySelector('[data-oos-bulk-check]');
    if (!note) {
      return;
    }
    function update() {
      var perEvent = {};
      document.querySelectorAll('[data-request-select]:checked').forEach(function (box) {
        var id = box.getAttribute('data-event-id');
        perEvent[id] = perEvent[id] || { n: 0, open: Number(box.getAttribute('data-open-spaces') || 0), title: box.getAttribute('data-event-title') || '' };
        perEvent[id].n += 1;
      });
      var over = Object.keys(perEvent).filter(function (id) {
        return perEvent[id].n > perEvent[id].open;
      }).map(function (id) {
        var row = perEvent[id];
        return row.title + ': ' + row.n + ' selected, ' + row.open + ' open';
      });
      note.textContent = over.length ? 'Not enough open spaces to approve all — ' + over.join('; ') + '. Extra approvals will be refused.' : '';
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches && e.target.matches('[data-request-select]')) {
        update();
      }
    });
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-request-bulk-clear]')) {
        window.setTimeout(update, 0);
      }
    });
    var reloadTimer = null;
    document.addEventListener('lnkup-request-updated', function () {
      window.clearTimeout(reloadTimer);
      reloadTimer = window.setTimeout(function () { window.location.reload(); }, 1400);
    });
  }

  /** Vendors header "Import vendors": opens the existing importer, or goes to Vendors first. */
  function bindImport() {
    var importer = document.getElementById('lnkup-import-vendors-btn');
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-oos-import]');
      if (!btn) {
        return;
      }
      if (importer) {
        importer.click();
        return;
      }
      var url = new URL((OS && OS.config.dashboard) || window.location.href);
      url.searchParams.set('view', 'vendors');
      url.searchParams.set('scope', 'imported');
      url.searchParams.set('import', '1');
      window.location.href = url.toString();
    });
    if (importer && new URLSearchParams(window.location.search).get('import') === '1') {
      window.setTimeout(function () { importer.click(); }, 300);
    }
  }

  function init() {
    bindImport();
    bindLayoutToggle();
    bindDuplicatePicker();
    bindSwitcher();
    bindInventory();
    bindApplicationDrawer();
    bindBulkCheck();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
