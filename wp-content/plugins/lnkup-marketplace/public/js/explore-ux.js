/**
 * Explore interaction layer on top of marketplace-v2.js:
 * - the advanced filter drawer behaves as a sheet (dialog semantics, close button, Escape,
 *   backdrop click, focus kept inside, focus returned to the Filters button);
 * - the Filters button shows how many filters are active;
 * - empty results offer to search all cities when a city filter caused them;
 * - coming back from an event restores the loaded pages and scroll position.
 */
(function () {
  'use strict';

  var root = document.getElementById('lnkup-marketplace');
  if (!root) return;

  var drawer = document.getElementById('lnkup-filter-drawer');
  var toggle = document.getElementById('lnkup-filter-toggle');
  var RETURN_KEY = 'lnkup_explore_return';

  // ---------------------------------------------------------------- Filter sheet

  function isOpen() {
    return drawer && !drawer.classList.contains('lnkup-mp-v2__filter-drawer--closed');
  }

  function focusables() {
    return Array.prototype.filter.call(
      drawer.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
      function (el) { return !el.disabled && el.offsetParent !== null; }
    );
  }

  function closeSheet() {
    if (!isOpen()) return;
    if (toggle) toggle.click();
    else drawer.classList.add('lnkup-mp-v2__filter-drawer--closed');
  }

  if (drawer) {
    var inner = drawer.querySelector('.lnkup-mp-v2__filter-inner');
    if (inner && !inner.querySelector('.lnkup-filter-sheet__close')) {
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'lnkup-filter-sheet__close';
      close.setAttribute('aria-label', 'Close filters');
      close.innerHTML = '&times;';
      close.addEventListener('click', closeSheet);
      inner.insertBefore(close, inner.firstChild);
    }

    var wasOpen = false;
    new MutationObserver(function () {
      var open = isOpen();
      if (open === wasOpen) return;
      wasOpen = open;
      if (open) {
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-label', 'Filters');
        var first = drawer.querySelector('.lnkup-filter-sheet__close');
        if (first) first.focus();
        document.documentElement.style.overflow = 'hidden';
      } else {
        drawer.removeAttribute('aria-modal');
        document.documentElement.style.overflow = '';
        if (toggle) toggle.focus();
      }
    }).observe(drawer, { attributes: true, attributeFilter: ['class'] });

    drawer.addEventListener('click', function (event) {
      if (event.target === drawer) closeSheet();
    });

    document.addEventListener('keydown', function (event) {
      if (!isOpen()) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeSheet();
        return;
      }
      if (event.key !== 'Tab') return;
      var items = focusables();
      if (!items.length) return;
      var firstEl = items[0];
      var lastEl = items[items.length - 1];
      if (event.shiftKey && document.activeElement === firstEl) {
        event.preventDefault();
        lastEl.focus();
      } else if (!event.shiftKey && document.activeElement === lastEl) {
        event.preventDefault();
        firstEl.focus();
      }
    });

    var apply = document.getElementById('lnkup-apply-filters');
    if (apply) {
      apply.addEventListener('click', function () {
        window.setTimeout(closeSheet, 0);
      });
    }
  }

  // ---------------------------------------------------------------- Active filter count

  function updateCount() {
    if (!toggle) return;
    var wrap = document.getElementById('lnkup-active-filters');
    var n = wrap && !wrap.hidden ? wrap.querySelectorAll('.lnkup-active-filter-chip').length : 0;
    var badge = toggle.querySelector('.lnkup-filter-count');
    if (!n) {
      if (badge) badge.remove();
      toggle.classList.remove('has-filters');
      toggle.setAttribute('aria-label', 'Filters');
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'lnkup-filter-count';
      badge.setAttribute('aria-hidden', 'true');
      toggle.appendChild(badge);
    }
    badge.textContent = String(n);
    toggle.classList.add('has-filters');
    toggle.setAttribute('aria-label', 'Filters, ' + n + ' active');
  }

  // ---------------------------------------------------------------- Empty results

  function enhanceEmpty() {
    var empty = document.getElementById('lnkup-empty-state');
    var city = document.getElementById('lnkup-find-city');
    var form = document.getElementById('lnkup-search-form');
    if (!empty || empty.hidden || !city || !form) return;
    var existing = empty.querySelector('.lnkup-empty-actions');
    if (existing) existing.remove();
    var value = (city.value || '').trim();
    if (!value) return;
    var actions = document.createElement('div');
    actions.className = 'lnkup-empty-actions';
    var all = document.createElement('button');
    all.type = 'button';
    all.className = 'lnkup-btn lnkup-btn--outline lnkup-btn--sm';
    all.textContent = 'Search all cities';
    all.addEventListener('click', function () {
      city.value = '';
      city.dispatchEvent(new Event('input', { bubbles: true }));
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });
    actions.appendChild(all);
    var clear = document.getElementById('lnkup-empty-clear');
    if (clear && clear.parentNode === empty) {
      clear.parentNode.insertBefore(actions, clear.nextSibling);
      actions.insertBefore(clear, all);
    } else {
      empty.appendChild(actions);
    }
  }

  // ---------------------------------------------------------------- Return position

  var grid = document.getElementById('lnkup-results-grid');

  function remember() {
    try {
      sessionStorage.setItem(RETURN_KEY, JSON.stringify({
        href: window.location.pathname + window.location.search,
        y: window.scrollY,
        cards: grid ? grid.children.length : 0,
        at: Date.now()
      }));
    } catch (e) { /* ignore */ }
  }

  if (grid) {
    grid.addEventListener('click', function (event) {
      var link = event.target.closest('a[href]');
      if (link && !link.hasAttribute('data-apply-id')) remember();
    });
  }

  var pending = null;
  try {
    var saved = JSON.parse(sessionStorage.getItem(RETURN_KEY) || 'null');
    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    var back = nav ? nav.type === 'back_forward' : false;
    if (saved && back && saved.href === window.location.pathname + window.location.search && Date.now() - saved.at < 30 * 60 * 1000) {
      pending = saved;
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    }
  } catch (e) { pending = null; }

  var attempts = 0;
  function restore() {
    if (!pending || !grid) return;
    if (grid.children.length < pending.cards && attempts < 6) {
      // Let the infinite loader fetch the next page, then try again on its results event.
      attempts++;
      window.scrollTo(0, document.documentElement.scrollHeight);
      return;
    }
    window.scrollTo(0, pending.y);
    pending = null;
    try { sessionStorage.removeItem(RETURN_KEY); } catch (e) { /* ignore */ }
  }

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) pending = null;
  });

  // marketplace-v2.js fires this before it re-renders the chips and the empty state.
  document.addEventListener('lnkup:marketplace-results', function () {
    window.setTimeout(function () {
      updateCount();
      enhanceEmpty();
      restore();
    }, 0);
  });

  updateCount();
  enhanceEmpty();
  if (pending) window.requestAnimationFrame(restore);
})();
