/**
 * LNKup OS shell behavior shared by the vendor and organizer workspaces: collapsible
 * nav groups, persistent sidebar collapse, unread notification count, in-page tabs,
 * drawers and dialogs (focus trap, Escape, focus restore, scroll lock), menus, and
 * table row selection with a bulk bar.
 *
 * Exposes window.LNKupOS = { drawer, dialog, open, close, fetchJson, toast }.
 */
(function () {
  'use strict';

  var cfg = window.lnkupOS || {};
  var role = cfg.role || (document.body.classList.contains('lnkup-oos') ? 'organizer' : 'vendor');
  var STORE_COLLAPSED = 'lnkupOsNavCollapsed:' + role;
  var STORE_GROUPS = 'lnkupOsNavGroups:' + role;
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function read(key, fallback) {
    try {
      var raw = window.localStorage.getItem(key);
      return raw === null ? fallback : JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }

  function write(key, value) {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      /* storage disabled */
    }
  }

  /* ------------------------------------------------------------ nav */

  function groupLinks(button) {
    var links = [];
    var node = button.nextElementSibling;
    while (node && node.classList.contains('lnkup-dashboard__nav-link')) {
      links.push(node);
      node = node.nextElementSibling;
    }
    return links;
  }

  function setGroup(button, open) {
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    groupLinks(button).forEach(function (link) {
      if (open || link.classList.contains('is-active')) {
        link.removeAttribute('data-nav-collapsed');
      } else {
        link.setAttribute('data-nav-collapsed', '');
      }
    });
  }

  function bindGroups() {
    var state = read(STORE_GROUPS, {});
    document.querySelectorAll('.lnkup-dashboard .lnkup-dashboard__nav-group[data-nav-group]').forEach(function (button) {
      var key = button.getAttribute('data-nav-group');
      setGroup(button, state[key] !== false);
      button.addEventListener('click', function () {
        var open = button.getAttribute('aria-expanded') !== 'true';
        setGroup(button, open);
        state[key] = open;
        write(STORE_GROUPS, state);
      });
    });
  }

  function bindCollapse() {
    var shell = document.querySelector('.lnkup-dashboard');
    var toggle = document.querySelector('[data-vos-collapse]');
    if (!shell || !toggle) {
      return;
    }
    function apply(collapsed) {
      shell.classList.toggle('is-nav-collapsed', collapsed);
      toggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      shell.querySelectorAll('.lnkup-dashboard__nav-link').forEach(function (link) {
        var label = link.querySelector('.lnkup-dashboard__nav-text');
        if (collapsed && label) {
          link.setAttribute('title', label.textContent.trim());
        } else if (!link.hasAttribute('data-locked-hint')) {
          link.removeAttribute('title');
        }
      });
    }
    apply(read(STORE_COLLAPSED, false) === true);
    toggle.addEventListener('click', function () {
      var next = !shell.classList.contains('is-nav-collapsed');
      apply(next);
      write(STORE_COLLAPSED, next);
    });
  }

  /* ------------------------------------------------------------ fetch */

  /**
   * JSON request against lnkup/v1 that never throws HTML or notices at the caller.
   * Resolves { ok, status, data } and never rejects.
   */
  function fetchJson(path, options) {
    if (!cfg.root || !window.fetch) {
      return Promise.resolve({ ok: false, status: 0, data: null });
    }
    var opts = options || {};
    var headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: headers };
    if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    return window.fetch(cfg.root + String(path).replace(/^\//, ''), init).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        return { ok: false, status: res.status, data: null };
      }
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      }, function () {
        return { ok: false, status: res.status, data: null };
      });
    }).catch(function () {
      return { ok: false, status: 0, data: null };
    });
  }

  function loadUnread() {
    var badge = document.querySelector('[data-vos-notify-count]');
    if (!badge) {
      return;
    }
    fetchJson('comms/inbox/count?role=' + encodeURIComponent(role)).then(function (res) {
      var count = res.ok && res.data ? Number(res.data.unread || 0) : 0;
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = false;
        badge.parentElement.setAttribute('aria-label', 'Notifications, ' + count + ' unread');
      }
    });
  }

  /* ------------------------------------------------------------ tabs */

  function bindTabs() {
    document.querySelectorAll('[data-vos-tabs]').forEach(function (root) {
      var buttons = root.querySelectorAll('[role="tab"]');
      buttons.forEach(function (button) {
        button.addEventListener('click', function () {
          var target = button.getAttribute('aria-controls');
          buttons.forEach(function (b) {
            var on = b === button;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
            var panel = document.getElementById(b.getAttribute('aria-controls'));
            if (panel) {
              panel.hidden = !on;
            }
          });
          if (target && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('section', button.getAttribute('data-section') || '');
            window.history.replaceState(null, '', url.toString());
          }
        });
        button.addEventListener('keydown', function (e) {
          var list = Array.prototype.slice.call(buttons);
          var i = list.indexOf(button);
          var next = null;
          if (e.key === 'ArrowRight') { next = list[(i + 1) % list.length]; }
          if (e.key === 'ArrowLeft') { next = list[(i - 1 + list.length) % list.length]; }
          if (next) {
            e.preventDefault();
            next.focus();
            next.click();
          }
        });
      });
    });
  }

  function bindTabMore() {
    document.querySelectorAll('.vos-tab-more').forEach(function (more) {
      more.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.lnkup-fin-tab')) {
          more.removeAttribute('open');
        }
      });
    });
  }

  /* ------------------------------------------------- drawers & dialogs */

  var stack = [];

  function focusables(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function isDirty(el) {
    return el.getAttribute('data-vos-dirty') === 'true';
  }

  /**
   * Open a drawer or dialog by element or id.
   * @param {string|HTMLElement} target
   * @param {HTMLElement} [trigger] Element to refocus on close.
   */
  function open(target, trigger) {
    var el = typeof target === 'string' ? document.getElementById(target) : target;
    if (!el) {
      return null;
    }
    el.hidden = false;
    el.removeAttribute('data-vos-dirty');
    stack.push({ el: el, trigger: trigger || document.activeElement });
    document.body.classList.add('lnkup-os-locked');
    var first = el.querySelector('[autofocus]') || focusables(el.querySelector('.vos-drawer__body, .vos-dialog__body') || el)[0] || focusables(el)[0];
    if (first) {
      window.setTimeout(function () { first.focus(); }, 0);
    }
    el.dispatchEvent(new CustomEvent('vos:open', { bubbles: true }));
    return el;
  }

  /**
   * Close the given overlay (or the top one). Asks before discarding unsaved changes
   * unless `force` is true.
   */
  function close(target, force) {
    var entry = null;
    var el = typeof target === 'string' ? document.getElementById(target) : target;
    for (var i = stack.length - 1; i >= 0; i--) {
      if (!el || stack[i].el === el) {
        entry = stack[i];
        stack.splice(i, 1);
        break;
      }
    }
    if (!entry) {
      if (el) { el.hidden = true; }
      return;
    }
    if (!force && isDirty(entry.el) && !window.confirm('Discard unsaved changes?')) {
      stack.push(entry);
      return;
    }
    entry.el.hidden = true;
    entry.el.removeAttribute('data-vos-dirty');
    if (!stack.length) {
      document.body.classList.remove('lnkup-os-locked');
    }
    entry.el.dispatchEvent(new CustomEvent('vos:close', { bubbles: true }));
    if (entry.trigger && typeof entry.trigger.focus === 'function' && document.contains(entry.trigger)) {
      entry.trigger.focus();
    }
  }

  function bindOverlays() {
    document.addEventListener('click', function (e) {
      var opener = e.target.closest && e.target.closest('[data-vos-open]');
      if (opener) {
        e.preventDefault();
        open(opener.getAttribute('data-vos-open'), opener);
        return;
      }
      var closer = e.target.closest && e.target.closest('[data-vos-close]');
      if (closer) {
        var overlay = closer.closest('[data-vos-overlay]');
        if (overlay) {
          e.preventDefault();
          close(overlay);
        }
      }
    });
    document.addEventListener('input', function (e) {
      var overlay = e.target.closest && e.target.closest('[data-vos-overlay]');
      if (overlay && e.target.closest('form')) {
        overlay.setAttribute('data-vos-dirty', 'true');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!stack.length) {
        return;
      }
      var top = stack[stack.length - 1].el;
      if (e.key === 'Escape') {
        e.preventDefault();
        close(top);
        return;
      }
      if (e.key !== 'Tab') {
        return;
      }
      var items = focusables(top);
      if (!items.length) {
        return;
      }
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      } else if (!top.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
      }
    });
  }

  /* -------------------------------------------------------------- menus */

  function bindMenus() {
    document.addEventListener('click', function (e) {
      document.querySelectorAll('details.vos-menu[open]').forEach(function (menu) {
        if (!menu.contains(e.target) || (e.target.closest && e.target.closest('.vos-menu__item'))) {
          menu.removeAttribute('open');
        }
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') {
        return;
      }
      document.querySelectorAll('details.vos-menu[open]').forEach(function (menu) {
        menu.removeAttribute('open');
        var summary = menu.querySelector('summary');
        if (summary) { summary.focus(); }
      });
    });
  }

  /* ------------------------------------------------- table selection */

  function syncSelection(wrap) {
    var rows = wrap.querySelectorAll('[data-vos-select-row]');
    var checked = wrap.querySelectorAll('[data-vos-select-row]:checked');
    var all = wrap.querySelector('[data-vos-select-all]');
    if (all) {
      all.checked = rows.length > 0 && checked.length === rows.length;
      all.indeterminate = checked.length > 0 && checked.length < rows.length;
    }
    rows.forEach(function (box) {
      var tr = box.closest('tr');
      if (tr) { tr.classList.toggle('is-selected', box.checked); }
    });
    var bar = wrap.nextElementSibling;
    if (bar && bar.hasAttribute('data-vos-bulk-bar')) {
      bar.hidden = checked.length === 0;
      var count = bar.querySelector('[data-vos-bulk-count]');
      if (count) {
        count.textContent = checked.length + ' selected';
      }
    }
    wrap.dispatchEvent(new CustomEvent('vos:selection', { bubbles: true, detail: { ids: selectedIds(wrap) } }));
  }

  function selectedIds(wrap) {
    return Array.prototype.map.call(wrap.querySelectorAll('[data-vos-select-row]:checked'), function (box) {
      return box.value;
    });
  }

  function bindSelection() {
    document.addEventListener('change', function (e) {
      var wrap = e.target.closest && e.target.closest('[data-vos-select]');
      if (!wrap) {
        return;
      }
      if (e.target.hasAttribute('data-vos-select-all')) {
        wrap.querySelectorAll('[data-vos-select-row]').forEach(function (box) {
          box.checked = e.target.checked;
        });
      }
      if (e.target.hasAttribute('data-vos-select-all') || e.target.hasAttribute('data-vos-select-row')) {
        syncSelection(wrap);
      }
    });
  }

  /* -------------------------------------------------------------- toast */

  function toast(message, tone) {
    var region = document.getElementById('lnkup-os-toast');
    if (!region) {
      region = document.createElement('div');
      region.id = 'lnkup-os-toast';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      region.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100002;display:grid;gap:8px;';
      document.body.appendChild(region);
    }
    var item = document.createElement('p');
    item.className = 'vos-notice' + (tone ? ' vos-notice--' + tone : '');
    item.style.cssText = 'margin:0;box-shadow:0 8px 24px rgb(0 0 0 / .15);';
    item.textContent = message;
    region.appendChild(item);
    window.setTimeout(function () { item.remove(); }, 4200);
  }

  function init() {
    bindTabMore();
    bindGroups();
    bindCollapse();
    loadUnread();
    bindTabs();
    bindOverlays();
    bindMenus();
    bindSelection();
  }

  window.LNKupOS = {
    open: open,
    close: close,
    drawer: { open: open, close: close },
    dialog: { open: open, close: close },
    fetchJson: fetchJson,
    selectedIds: selectedIds,
    toast: toast,
    config: cfg
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
