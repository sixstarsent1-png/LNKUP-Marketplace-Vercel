/**
 * LNKup public website 5.0 behaviour: header menus, mobile drawer, announcement, tabs,
 * search with autocomplete, organizer earnings calculator, reveal-on-scroll.
 * No user data is read or stored here; pages stay safe to cache.
 */
(function () {
  'use strict';

  var cfg = window.lnkupPublic || {};
  var i18n = cfg.i18n || {};
  var doc = document;

  function $all(sel, root) {
    return Array.prototype.slice.call((root || doc).querySelectorAll(sel));
  }

  /* ---------------- Header dropdowns ---------------- */

  function closeMenus(except) {
    $all('[data-lp-menu-trigger][aria-expanded="true"]').forEach(function (btn) {
      if (btn === except) return;
      btn.setAttribute('aria-expanded', 'false');
      var menu = doc.getElementById(btn.getAttribute('aria-controls'));
      if (menu) menu.hidden = true;
    });
  }

  $all('[data-lp-menu-trigger]').forEach(function (btn) {
    var menu = doc.getElementById(btn.getAttribute('aria-controls'));
    if (!menu) return;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = btn.getAttribute('aria-expanded') === 'true';
      closeMenus(btn);
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      menu.hidden = open;
      if (!open) {
        var first = menu.querySelector('a');
        if (first && e.detail === 0) first.focus();
      }
    });
    menu.addEventListener('keydown', function (e) {
      var links = $all('a', menu);
      var idx = links.indexOf(doc.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        links[(idx + 1) % links.length].focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        links[(idx - 1 + links.length) % links.length].focus();
      } else if (e.key === 'Escape') {
        closeMenus();
        btn.focus();
      }
    });
    menu.addEventListener('focusout', function (e) {
      if (e.relatedTarget && !menu.contains(e.relatedTarget) && e.relatedTarget !== btn) {
        btn.setAttribute('aria-expanded', 'false');
        menu.hidden = true;
      }
    });
  });

  doc.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('[data-lp-menu]')) closeMenus();
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenus();
  });

  var header = doc.querySelector('[data-lp-header]');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------------- Mobile drawer ---------------- */

  var drawer = doc.querySelector('[data-lp-drawer]');
  var opener = doc.querySelector('[data-lp-drawer-open]');
  if (drawer && opener) {
    var lastFocus = null;
    var focusables = function () {
      return $all('a[href], button:not([tabindex="-1"]), summary, [tabindex]:not([tabindex="-1"])', drawer).filter(function (el) {
        return el.offsetParent !== null;
      });
    };
    var openDrawer = function () {
      lastFocus = doc.activeElement && doc.activeElement !== doc.body ? doc.activeElement : opener;
      drawer.hidden = false;
      opener.setAttribute('aria-expanded', 'true');
      doc.body.classList.add('lp-scroll-lock');
      var items = focusables();
      if (items.length) items[0].focus();
    };
    var closeDrawer = function () {
      drawer.hidden = true;
      opener.setAttribute('aria-expanded', 'false');
      doc.body.classList.remove('lp-scroll-lock');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    };
    opener.addEventListener('click', openDrawer);
    $all('[data-lp-drawer-close]', drawer).forEach(function (el) {
      el.addEventListener('click', closeDrawer);
    });
    drawer.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeDrawer();
        return;
      }
      if (e.key !== 'Tab') return;
      var items = focusables();
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && doc.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && doc.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });
    doc.addEventListener('focusin', function (e) {
      if (drawer.hidden || drawer.contains(e.target)) return;
      var items = focusables();
      if (items.length) items[0].focus();
    });
    window.addEventListener('resize', function () {
      if (!drawer.hidden && window.innerWidth >= 1024) closeDrawer();
    });
  }

  /* ---------------- Announcement ---------------- */

  $all('[data-lp-announce]').forEach(function (bar) {
    var key = bar.getAttribute('data-lp-announce');
    try {
      if (localStorage.getItem(key) === '1') {
        bar.hidden = true;
        return;
      }
    } catch (e) { /* storage blocked */ }
    var close = bar.querySelector('[data-lp-announce-close]');
    if (close) {
      close.addEventListener('click', function () {
        bar.hidden = true;
        try { localStorage.setItem(key, '1'); } catch (e) { /* storage blocked */ }
      });
    }
  });

  [
    ['lnkup-launch-bar', 'lnkup-launch-bar-dismiss', 'lnkup_launch_bar_dismissed']
  ].forEach(function (ids) {
    var bar = doc.getElementById(ids[0]);
    var btn = doc.getElementById(ids[1]);
    if (!bar || !btn || btn.getAttribute('data-lp-bound')) return;
    btn.setAttribute('data-lp-bound', '1');
    try {
      if (localStorage.getItem(ids[2]) === '1') bar.hidden = true;
    } catch (e) { /* storage blocked */ }
    btn.addEventListener('click', function () {
      bar.hidden = true;
      try { localStorage.setItem(ids[2], '1'); } catch (e) { /* storage blocked */ }
    });
  });

  /* ---------------- Tabs (role toggles, event sets, search modes) ---------------- */

  $all('[data-lp-tabs]').forEach(function (list) {
    var tabs = $all('[role="tab"]', list);
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = doc.getElementById(t.getAttribute('aria-controls'));
        if (panel) panel.hidden = !on;
      });
      if (focus) tab.focus();
    }
    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab, false); });
      tab.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
        if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
        if (e.key === 'Home') next = tabs[0];
        if (e.key === 'End') next = tabs[tabs.length - 1];
        if (next) {
          e.preventDefault();
          select(next, true);
        }
      });
    });
  });

  /* ---------------- Search autocomplete ---------------- */

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(null, args); }, ms);
    };
  }

  function fetchJson(url) {
    return fetch(url, { credentials: 'omit', headers: { Accept: 'application/json' } }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (!res.ok || type.indexOf('application/json') === -1) throw new Error('bad response');
      return res.json();
    });
  }

  $all('[data-lp-suggest]').forEach(function (input) {
    var list = doc.getElementById(input.getAttribute('aria-controls'));
    var live = doc.getElementById(input.getAttribute('data-lp-live'));
    var kind = input.getAttribute('data-lp-suggest');
    if (!list || !cfg.restUrl) return;
    var options = [];
    var active = -1;
    var cityList = [];
    try {
      cityList = JSON.parse(input.getAttribute('data-lp-cities') || '[]');
    } catch (e) {
      cityList = [];
    }

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    }

    function render(groups) {
      list.innerHTML = '';
      options = [];
      groups.forEach(function (group) {
        if (!group.items.length) return;
        var head = doc.createElement('li');
        head.className = 'lp-suggest__group';
        head.setAttribute('role', 'presentation');
        head.textContent = group.label;
        list.appendChild(head);
        group.items.forEach(function (item) {
          var li = doc.createElement('li');
          li.className = 'lp-suggest__option';
          li.id = list.id + '-opt-' + options.length;
          li.setAttribute('role', 'option');
          li.setAttribute('aria-selected', 'false');
          li.textContent = item.label;
          li.addEventListener('mousedown', function (e) {
            e.preventDefault();
            choose(item);
          });
          list.appendChild(li);
          options.push({ el: li, item: item });
        });
      });
      if (!options.length) {
        close();
        if (live) live.textContent = i18n.noResults || '';
        return;
      }
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (live) live.textContent = (i18n.results || '%d').replace('%d', String(options.length));
    }

    function highlight(i) {
      options.forEach(function (o, j) { o.el.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
      active = i;
      if (options[i]) {
        input.setAttribute('aria-activedescendant', options[i].el.id);
        options[i].el.scrollIntoView({ block: 'nearest' });
      }
    }

    function choose(item) {
      if (item.city) {
        var form = input.form;
        var cityField = form && form.querySelector('[name="city"]');
        var stateField = form && form.querySelector('[name="state"]');
        if (cityField && cityField !== input) {
          cityField.value = item.city;
          input.value = '';
        } else {
          input.value = item.city;
        }
        if (stateField) stateField.value = item.state || '';
      } else if (item.category) {
        var catField = input.form && input.form.querySelector('[name="categories"]');
        if (catField) {
          catField.value = item.category;
          input.value = '';
        } else {
          input.value = item.category;
        }
      } else {
        input.value = item.value || item.label;
      }
      close();
      if (item.submit && input.form) input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
    }

    function localCities(q) {
      q = q.toLowerCase();
      return cityList.filter(function (c) { return c.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 6).map(function (c) {
        return { label: c.label, city: c.city, state: c.state };
      });
    }

    var lookup = debounce(function () {
      var q = input.value.trim();
      if (kind === 'city') {
        render([{ label: i18n.cities || 'Cities', items: q ? localCities(q) : localCities('').slice(0, 6) }]);
        return;
      }
      if (q.length < 2) {
        close();
        return;
      }
      fetchJson(cfg.restUrl + 'marketplace/suggestions?q=' + encodeURIComponent(q)).then(function (data) {
        if (input.value.trim() !== q) return;
        var cats = (data.categories || []).filter(function (c) { return typeof c === 'string' && c.toLowerCase().indexOf(q.toLowerCase()) !== -1; });
        var seen = {};
        render([
          { label: i18n.events || 'Events', items: (data.events || []).filter(function (e) { return e.label && e.label.toLowerCase().indexOf(q.toLowerCase()) !== -1; }).slice(0, 5).map(function (e) { return { label: e.label, value: e.label.split(' — ')[0], submit: true }; }) },
          { label: i18n.cities || 'Cities', items: localCities(q) },
          { label: i18n.categories || 'Categories', items: cats.filter(function (c) { if (seen[c]) return false; seen[c] = true; return true; }).slice(0, 5).map(function (c) { return { label: c, value: c }; }) }
        ]);
      }).catch(function () {
        render([{ label: i18n.cities || 'Cities', items: localCities(q) }]);
      });
    }, 180);

    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', function () {
      if (kind === 'city' && input.form) {
        var stateField = input.form.querySelector('[name="state"]');
        if (stateField) stateField.value = '';
      }
      lookup();
    });
    input.addEventListener('focus', function () { if (kind === 'city') lookup(); });
    input.addEventListener('blur', function () { setTimeout(close, 120); });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(Math.min(options.length - 1, active + 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(Math.max(0, active - 1));
      } else if (e.key === 'Enter' && active > -1) {
        e.preventDefault();
        choose(options[active].item);
      } else if (e.key === 'Escape') {
        close();
      }
    });
  });

  /* Drop empty fields so shared search URLs stay short and readable. */
  $all('form[data-lp-search-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      $all('input, select', form).forEach(function (el) {
        if (el.name && !el.value) el.disabled = true;
      });
      setTimeout(function () {
        $all('input, select', form).forEach(function (el) { el.disabled = false; });
      }, 0);
    });
  });

  /* ---------------- Organizer earnings calculator ---------------- */

  $all('[data-lp-calc]').forEach(function (root) {
    var pct = parseFloat(root.getAttribute('data-commission')) || 0;
    var currency = new Intl.NumberFormat(doc.documentElement.lang || 'en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
    var inputs = $all('input[type="range"]', root);
    function update() {
      var values = {};
      inputs.forEach(function (input) {
        values[input.name] = parseFloat(input.value) || 0;
        var out = root.querySelector('output[for="' + input.id + '"]');
        if (out) out.textContent = input.name === 'booth' ? currency.format(values[input.name]) : String(values[input.name]);
      });
      var gross = (values.booths || 0) * (values.booth || 0);
      var fee = Math.round(gross * pct) / 100;
      var net = gross - fee;
      var set = function (key, value) {
        var el = root.querySelector('[data-out="' + key + '"]');
        if (el) el.textContent = currency.format(value);
      };
      set('gross', gross);
      set('fee', fee);
      set('net', net);
    }
    inputs.forEach(function (input) { input.addEventListener('input', update); });
    update();
  });

  /* ---------------- Reveal on scroll ---------------- */

  var reveals = $all('.lp-reveal');
  if (reveals.length && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-visible'); });
  }
})();
