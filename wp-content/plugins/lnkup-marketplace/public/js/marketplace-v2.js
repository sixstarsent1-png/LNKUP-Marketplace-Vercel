(function () {
  var cfg = window.lnkupAjax || {};
  var root = document.getElementById('lnkup-marketplace');
  if (!root) return;

  var phase = cfg.marketplacePhase || root.getAttribute('data-phase') || '1a';
  var eventTypeTree = cfg.eventTypeTree || [];
  var RECENT_KEY = 'lnkup_recent_views';
  var VIEW_KEY = 'lnkup_marketplace_view';
  var state = { page: 1, loading: false, hasMore: true, filters: { has_spots: true }, q: '', sort: 'newest', lat: null, lng: null };
  var debounceTimer;
  var gridEl = document.getElementById('lnkup-results-grid');

  function phaseAtLeast(min) {
    var order = { '1a': 1, '1b': 2, '1c': 3 };
    return (order[phase] || 1) >= (order[min] || 1);
  }

  function api(path, opts) {
    if (!cfg.restUrl) return Promise.reject(new Error('REST unavailable'));
    opts = opts || {};
    var url = cfg.restUrl + path;
    if (opts.query) {
      var qs = new URLSearchParams();
      Object.keys(opts.query).forEach(function (k) {
        if (opts.query[k] !== undefined && opts.query[k] !== null && opts.query[k] !== '') {
          qs.set(k, opts.query[k]);
        }
      });
      url += (url.indexOf('?') > -1 ? '&' : '?') + qs.toString();
    }
    return fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: Object.assign({ 'X-WP-Nonce': cfg.restNonce || '', 'Content-Type': 'application/json' }, opts.headers || {}),
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('application/json') === -1) {
        return r.text().then(function (body) {
          console.error('Marketplace API error', r.status, body.slice(0, 300));
          throw new Error('api_error');
        });
      }
      return r.json().then(function (body) {
        if (!r.ok || (body && body.code)) {
          var err = new Error((body && body.message) || 'Request failed');
          err.code = body && body.code;
          err.status = r.status;
          err.data = body && body.data;
          throw err;
        }
        return body;
      });
    });
  }

  function ajaxFilter(query) {
    if (!cfg.url) return Promise.reject(new Error('AJAX unavailable'));
    var fd = new FormData();
    fd.append('action', 'lnkup_filter_opportunities');
    fd.append('lnkup_nonce', cfg.nonce || '');
    Object.keys(query).forEach(function (k) {
      if (query[k] !== undefined && query[k] !== null && query[k] !== '') {
        fd.append(k, query[k]);
      }
    });
    return fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Filter failed');
        return {
          html: res.data.html,
          total: res.data.count,
          page: res.data.page || 1,
          pages: res.data.pages || 1,
          active_filters: res.data.active_filters || [],
          empty_state: res.data.empty_state,
          pagination: res.data.pagination || ''
        };
      });
  }

  function syncRelevanceSort() {
    if (state.q && (!state.sort || state.sort === 'newest')) {
      state.sort = 'relevance';
      var sortEl = document.getElementById('lnkup-sort');
      if (sortEl && sortEl.querySelector('option[value="relevance"]')) {
        sortEl.value = 'relevance';
      }
    }
  }

  function hasActiveDiscoveryFilters() {
    if (state.q) return true;
    var keys = Object.keys(state.filters || {});
    return keys.some(function (k) {
      if (k === 'has_spots' && state.filters[k] === true) return false;
      return state.filters[k] !== undefined && state.filters[k] !== null && state.filters[k] !== '' && state.filters[k] !== false;
    });
  }

  var exploreHasData = false;
  var exploreTabCache = {};

  function toggleDiscoveryRails() {
    var explore = document.getElementById('lnkup-explore-more');
    if (!explore) return;
    var hide = hasActiveDiscoveryFilters() || !exploreHasData;
    explore.classList.toggle('is-hidden', hide);
    explore.hidden = hide;
  }

  function buildQuery() {
    collectFilters();
    var q = Object.assign({}, state.filters, { q: state.q, sort: state.sort, page: state.page, per_page: 24 });
    if (state.lat && state.lng) { q.lat = state.lat; q.lng = state.lng; }
    return q;
  }

  function findEventTypeParent(name) {
    if (!name || !eventTypeTree.length) return '';
    var match = eventTypeTree.find(function (group) {
      return group.name === name;
    });
    if (match) return name;
    for (var i = 0; i < eventTypeTree.length; i++) {
      var group = eventTypeTree[i];
      if (!group.children) continue;
      for (var j = 0; j < group.children.length; j++) {
        if (group.children[j].name === name) {
          return group.name;
        }
      }
    }
    return '';
  }

  function fillMarketplaceEventTypeChildren(parentName, selectedChild) {
    var childSelect = document.getElementById('lnkup-filter-event-type');
    var hint = document.getElementById('lnkup-filter-event-type-hint');
    if (!childSelect) return;
    childSelect.innerHTML = '<option value="">All subtypes</option>';
    childSelect.disabled = false;
    if (hint) {
      if (parentName) {
        hint.textContent = 'Showing all ' + parentName + ' events when no subtype is selected.';
        hint.hidden = false;
      } else {
        hint.textContent = 'Choose a category to filter by subtype.';
        hint.hidden = false;
      }
    }
    if (!parentName) return;
    var group = eventTypeTree.find(function (item) {
      return item.name === parentName;
    });
    if (!group || !group.children) return;
    group.children.forEach(function (child) {
      var opt = document.createElement('option');
      opt.value = child.name;
      opt.textContent = child.name;
      if (selectedChild && selectedChild === child.name) opt.selected = true;
      childSelect.appendChild(opt);
    });
  }

  function bindMarketplaceEventTypeCascade() {
    var parentSelect = document.getElementById('lnkup-filter-event-type-parent');
    var childSelect = document.getElementById('lnkup-filter-event-type');
    if (!parentSelect || !childSelect) return;
    parentSelect.addEventListener('change', function () {
      fillMarketplaceEventTypeChildren(parentSelect.value, '');
      collectFilters();
      resetAndSearch();
    });
    childSelect.addEventListener('change', function () {
      collectFilters();
      resetAndSearch();
    });
  }

  function collectFilters() {
    state.filters = { has_spots: true };
    root.querySelectorAll('[data-filter]').forEach(function (el) {
      var key = el.getAttribute('data-filter');
      if (el.type === 'checkbox') {
        if (el.checked) {
          if (key === 'lnkup_only') {
            state.filters.include_external = false;
          } else {
            state.filters[key] = true;
          }
        }
        else if (key === 'has_spots') delete state.filters.has_spots;
        else if (key === 'include_external') state.filters[key] = false;
      } else if (el.value) {
        state.filters[key] = el.value;
      }
    });
    var cats = [];
    root.querySelectorAll('[data-filter-cat]:checked').forEach(function (el) { cats.push(el.getAttribute('data-filter-cat')); });
    if (cats.length) state.filters.categories = cats.join(',');

    var types = [];
    root.querySelectorAll('[data-filter-event-type]:checked').forEach(function (el) { types.push(el.getAttribute('data-filter-event-type')); });
    var typeParentSelect = document.getElementById('lnkup-filter-event-type-parent');
    var typeSelect = document.getElementById('lnkup-filter-event-type');
    if (typeSelect && typeSelect.value) {
      types.push(typeSelect.value);
    } else if (typeParentSelect && typeParentSelect.value) {
      types.push(typeParentSelect.value);
    }
    if (types.length) state.filters.event_types = types.join(',');

    var markets = [];
    var marketSelect = root.querySelector('[data-filter="market_select"]');
    if (marketSelect && marketSelect.value) markets.push(marketSelect.value);
    if (markets.length) state.filters.markets = markets.join(',');

    var docs = [];
    root.querySelectorAll('[data-filter-doc]:checked').forEach(function (el) { docs.push(el.getAttribute('data-filter-doc')); });
    if (docs.length) state.filters.doc_required = docs.join(',');
  }

  function mergeSearchChip(chips) {
    var merged = (chips || []).slice();
    if (state.q) {
      var hasQ = merged.some(function (c) { return c.key === 'q'; });
      if (!hasQ) {
        merged.unshift({ key: 'q', value: state.q, label: 'Search: ' + state.q });
      }
    }
    return merged;
  }

  function renderActiveFilters(chips) {
    var wrap = document.getElementById('lnkup-active-filters');
    if (!wrap) return;
    if (!chips || !chips.length) {
      wrap.hidden = true;
      wrap.innerHTML = '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-clear-filters-inline">Clear all</button>';
      bindClearInline();
      return;
    }
    var html = '<div class="lnkup-filter-chips">';
    chips.forEach(function (chip) {
      html += '<button type="button" class="lnkup-filter-chip lnkup-active-filter-chip" data-filter-key="' + escapeAttr(chip.key) + '" data-filter-value="' + escapeAttr(chip.value || '') + '">' + escapeHtml(chip.label) + ' <span aria-hidden="true">×</span></button>';
    });
    html += '</div><button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-clear-filters-inline">Clear all</button>';
    wrap.innerHTML = html;
    wrap.hidden = false;
    bindClearInline();
  }

  function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;');
  }

  function showError(show) {
    var err = document.getElementById('lnkup-error-state');
    if (err) err.hidden = !show;
  }

  function applyResults(data, append) {
    var grid = document.getElementById('lnkup-results-grid');
    var empty = document.getElementById('lnkup-empty-state');
    var count = document.getElementById('lnkup-result-count');
    var pagination = document.getElementById('lnkup-pagination');
    if (!grid) return;
    if (!append) grid.innerHTML = '';
    if (data.html) grid.insertAdjacentHTML('beforeend', data.html);
    state.hasMore = (data.page || 1) < (data.pages || 1);
    if (count) count.textContent = (data.total || 0) + ' opportunities';
    var legal = document.getElementById('lnkup-external-legal');
    if (legal) legal.hidden = !data.has_external;
    document.dispatchEvent(new CustomEvent('lnkup:marketplace-results', { detail: data }));
    if (empty) empty.hidden = !(data.empty_state || (data.total || 0) === 0);
    if (pagination && !append) pagination.innerHTML = data.pagination || '';
    renderActiveFilters(mergeSearchChip(data.active_filters || []));
    pushUrlFilters();
    if ((data.total || 0) === 0 && phaseAtLeast('1b')) loadEmptySuggestions();
    trackCards();
    showError(false);
    toggleDiscoveryRails();
    try {
      var saved = localStorage.getItem(VIEW_KEY);
      if (saved) {
        applyViewMode(saved, false);
      }
    } catch (e) { /* ignore */ }
  }

  function loadResults(append) {
    if (state.loading) return;
    state.loading = true;
    var loader = document.getElementById('lnkup-infinite-loader');
    if (loader) loader.hidden = false;
    var count = document.getElementById('lnkup-result-count');
    if (count && !append) count.textContent = 'Searching…';
    if (!append) renderActiveFilters(mergeSearchChip([]));
    showError(false);
    if (!append && gridEl && window.LNKupUI && typeof LNKupUI.panelSkeleton === 'function') {
      LNKupUI.panelSkeleton(gridEl, 6);
    }
    var empty = document.getElementById('lnkup-empty-state');
    if (!append && empty) empty.hidden = true;

    var query = buildQuery();
    var fetcher = cfg.restUrl ? api('/search', { query: query }) : ajaxFilter(query);

    fetcher.then(function (data) {
      applyResults(data, append);
      state.loading = false;
      if (loader) loader.hidden = true;
      if (phaseAtLeast('1b') && window.lnkupMapRefresh) window.lnkupMapRefresh();
    }).catch(function () {
      if (cfg.restUrl && cfg.url) {
        ajaxFilter(query).then(function (data) {
          applyResults(data, append);
        }).catch(function () { showError(true); }).finally(finishLoad);
        return;
      }
      showError(true);
      finishLoad();
    });

    function finishLoad() {
      state.loading = false;
      if (loader) loader.hidden = true;
    }
  }

  function loadEmptySuggestions() {
    if (!cfg.restUrl) return;
    api('/sections/trending', { query: { section: 'trending', limit: 6 } }).then(function (data) {
      var el = document.getElementById('lnkup-empty-suggestions');
      if (el && data.html) el.innerHTML = data.html;
    });
  }

  function getExploreCollections() {
    var el = document.getElementById('lnkup-explore-collections-data');
    if (!el || !el.textContent) return [];
    try {
      var parsed = JSON.parse(el.textContent);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function renderCollectionsChips(collections) {
    var html = '<div class="lnkup-mp-v2__collection-chips">';
    collections.forEach(function (coll) {
      html += '<button type="button" class="lnkup-collection-chip" data-collection="' + escapeAttr(coll.slug) + '">' + escapeHtml(coll.label) + '</button>';
    });
    html += '</div>';
    return html;
  }

  function applyCollectionFilters(slug) {
    var collections = getExploreCollections();
    var coll = collections.find(function (c) { return c.slug === slug; });
    if (coll && coll.filters && Object.keys(coll.filters).length) {
      clearAllFilters();
      applyFiltersObject(coll.filters);
      resetAndSearch();
      return;
    }
    if (coll && coll.section === 'closing_soon') {
      clearAllFilters();
      applyFiltersObject({ closing_soon: true });
      resetAndSearch();
      return;
    }
    api('/collections/' + slug, { query: { limit: 24 } }).then(function (data) {
      if (data && data.items && data.items.length) {
        var grid = document.getElementById('lnkup-results-grid');
        if (grid) {
          grid.innerHTML = data.html || '';
          trackCards();
        }
        var count = document.getElementById('lnkup-result-count');
        if (count) count.textContent = (data.items.length || 0) + ' opportunities';
      }
    }).catch(function () {
      applyFiltersObject({ collection: slug });
      resetAndSearch();
    });
  }

  function activateExploreTab(tabKey) {
    var tablist = document.getElementById('lnkup-explore-tablist');
    var panels = document.getElementById('lnkup-explore-panels');
    if (!tablist || !panels) return;
    tablist.querySelectorAll('.lnkup-mp-v2__explore-tab').forEach(function (btn) {
      var active = btn.getAttribute('data-tab') === tabKey;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.querySelectorAll('.lnkup-mp-v2__explore-panel').forEach(function (panel) {
      var active = panel.getAttribute('data-tab') === tabKey;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
      if (active && !panel.dataset.loaded && exploreTabCache[tabKey]) {
        panel.innerHTML = exploreTabCache[tabKey];
        panel.dataset.loaded = '1';
        trackCards();
      }
    });
  }

  function initExploreMore() {
    if (!phaseAtLeast('1b') || !cfg.restUrl) return;
    var explore = document.getElementById('lnkup-explore-more');
    var loading = document.getElementById('lnkup-explore-loading');
    var tabsWrap = document.getElementById('lnkup-explore-tabs');
    var tablist = document.getElementById('lnkup-explore-tablist');
    var panels = document.getElementById('lnkup-explore-panels');
    if (!explore || !loading || !tabsWrap || !tablist || !panels) return;

    var collections = getExploreCollections();
    var tabDefs = [
      { key: 'recommended', label: 'Recommended', fetch: function () {
        if (!cfg.isLoggedIn) return Promise.resolve({ items: [] });
        return api('/sections/recommended', { query: { limit: 8 } });
      } },
      { key: 'trending', label: 'Trending', fetch: function () {
        return api('/sections/trending', { query: { section: 'trending', limit: 8 } });
      } },
      { key: 'closing', label: 'Closing Soon', fetch: function () {
        return api('/sections/trending', { query: { section: 'closing_soon', limit: 8 } });
      } },
      { key: 'applied', label: 'Most Applied', fetch: function () {
        return api('/sections/trending', { query: { section: 'most_applied', limit: 8 } });
      } }
    ];

    Promise.allSettled(tabDefs.map(function (def) {
      return def.fetch().then(function (data) {
        return { def: def, data: data };
      });
    })).then(function (results) {
      var tabs = [];
      results.forEach(function (result) {
        if (result.status !== 'fulfilled' || !result.value) return;
        var def = result.value.def;
        var data = result.value.data;
        var items = (data && data.items) ? data.items : [];
        if (!items.length) return;
        var html = data.html || '';
        exploreTabCache[def.key] = '<div class="lnkup-event-grid lnkup-mp-v2__rail lnkup-mp-v2__rail-scroll-x">' + html + '</div>';
        tabs.push({ key: def.key, label: def.label });
      });

      if (collections.length) {
        exploreTabCache.collections = renderCollectionsChips(collections);
        tabs.push({ key: 'collections', label: 'Collections' });
      }

      loading.hidden = true;

      if (!tabs.length) {
        exploreHasData = false;
        explore.hidden = true;
        toggleDiscoveryRails();
        return;
      }

      exploreHasData = true;
      var tabHtml = '';
      var panelHtml = '';
      tabs.forEach(function (tab, index) {
        var active = index === 0;
        tabHtml += '<button type="button" class="lnkup-mp-v2__explore-tab' + (active ? ' is-active' : '') + '" role="tab" data-tab="' + escapeAttr(tab.key) + '" aria-selected="' + (active ? 'true' : 'false') + '">' + escapeHtml(tab.label) + '</button>';
        panelHtml += '<div class="lnkup-mp-v2__explore-panel' + (active ? ' is-active' : '') + '" role="tabpanel" data-tab="' + escapeAttr(tab.key) + '"' + (active ? '' : ' hidden') + '></div>';
      });
      tablist.innerHTML = tabHtml;
      panels.innerHTML = panelHtml;

      tabsWrap.hidden = false;
      activateExploreTab(tabs[0].key);

      tablist.addEventListener('click', function (e) {
        var btn = e.target.closest('.lnkup-mp-v2__explore-tab');
        if (!btn) return;
        activateExploreTab(btn.getAttribute('data-tab'));
      });

      toggleDiscoveryRails();
    }).catch(function () {
      loading.hidden = true;
      exploreHasData = false;
      explore.hidden = true;
      toggleDiscoveryRails();
    });
  }

  function loadSuggestions(q) {
    if (!phaseAtLeast('1b') || !cfg.restUrl) return;
    var box = document.getElementById('lnkup-suggestions');
    if (!box) return;
    api('/suggestions', { query: { q: q || '' } }).then(function (data) {
      var html = '';
      (data.recent || []).forEach(function (t) { html += '<button type="button" class="lnkup-suggestion-item" data-q="' + escapeAttr(t) + '">' + escapeHtml(t) + '</button>'; });
      (data.cities || []).forEach(function (t) { html += '<button type="button" class="lnkup-suggestion-item" data-q="' + escapeAttr(t) + '">' + escapeHtml(t) + '</button>'; });
      (data.events || []).forEach(function (e) { html += '<button type="button" class="lnkup-suggestion-item" data-id="' + e.id + '">' + escapeHtml(e.label) + '</button>'; });
      box.innerHTML = html || '<span class="lnkup-muted">Type to search…</span>';
      box.hidden = false;
    });
  }

  function parseUrlFilters() {
    var params = new URLSearchParams(window.location.search);
    var encoded = params.get('lnkup_restore');
    if (encoded) {
      try {
        applyFiltersObject(JSON.parse(atob(decodeURIComponent(encoded))));
        return;
      } catch (e) { /* fall through */ }
    }
    var searchInput = document.getElementById('lnkup-search-input');
    if (params.get('q')) {
      state.q = params.get('q');
      if (searchInput) searchInput.value = state.q;
    } else if (params.get('s')) {
      state.q = params.get('s');
      if (searchInput) searchInput.value = state.q;
    }
    if (params.get('sort')) {
      state.sort = params.get('sort');
      var sortEl = document.getElementById('lnkup-sort');
      if (sortEl) sortEl.value = state.sort;
    }
    if (params.get('page')) state.page = parseInt(params.get('page'), 10) || 1;
    root.querySelectorAll('[data-filter]').forEach(function (el) {
      var key = el.getAttribute('data-filter');
      if (!params.has(key)) return;
      var val = params.get(key);
      if (el.type === 'checkbox') el.checked = val === '1' || val === 'true';
      else el.value = val;
    });
    if (params.get('categories')) {
      params.get('categories').split(',').forEach(function (cat) {
        var el = root.querySelector('[data-filter-cat="' + cat + '"]');
        if (el) el.checked = true;
      });
    }
    if (params.get('event_types')) {
      var etValues = params.get('event_types').split(',');
      var firstType = etValues[0] || '';
      var parentName = findEventTypeParent(firstType);
      var parentSel = document.getElementById('lnkup-filter-event-type-parent');
      var childSel = document.getElementById('lnkup-filter-event-type');
      if (parentSel && parentName) {
        parentSel.value = parentName;
        fillMarketplaceEventTypeChildren(parentName, parentName === firstType ? '' : firstType);
      }
      if (childSel && parentName && parentName !== firstType) {
        childSel.value = firstType;
      }
      etValues.forEach(function (t) {
        var cb = root.querySelector('[data-filter-event-type="' + t + '"]');
        if (cb) cb.checked = true;
      });
    }
    collectFilters();
  }

  function pushUrlFilters() {
    var q = buildQuery();
    var params = new URLSearchParams();
    Object.keys(q).forEach(function (k) {
      if (q[k] === true) params.set(k, '1');
      else if (q[k] !== false && q[k] !== null && q[k] !== undefined && q[k] !== '') params.set(k, q[k]);
    });
    var current = new URLSearchParams(window.location.search);
    if (current.get('apply')) {
      params.set('apply', current.get('apply'));
    }
    var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    if (window.location.pathname + window.location.search !== next) {
      window.history.replaceState({}, '', next);
    }
  }

  function applyFiltersObject(filters) {
    if (!filters || typeof filters !== 'object') return;
    if (filters.q) {
      state.q = filters.q;
      var searchInput = document.getElementById('lnkup-search-input');
      if (searchInput) searchInput.value = filters.q;
    }
    if (filters.sort) {
      state.sort = filters.sort;
      var sortEl = document.getElementById('lnkup-sort');
      if (sortEl) sortEl.value = filters.sort;
    }
    Object.keys(filters).forEach(function (key) {
      var el = root.querySelector('[data-filter="' + key + '"]');
      if (el) {
        if (el.type === 'checkbox') el.checked = !!filters[key];
        else el.value = filters[key] || '';
      }
    });
    if (filters.event_types) {
      var types = String(filters.event_types).split(',');
      var firstType = types[0] || '';
      var parentName = findEventTypeParent(firstType);
      var parentSel = document.getElementById('lnkup-filter-event-type-parent');
      var childSel = document.getElementById('lnkup-filter-event-type');
      if (parentSel && parentName) {
        parentSel.value = parentName;
        fillMarketplaceEventTypeChildren(parentName, parentName === firstType ? '' : firstType);
      }
      if (childSel && parentName && parentName !== firstType) {
        childSel.value = firstType;
      }
      types.forEach(function (t) {
        var cb = root.querySelector('[data-filter-event-type="' + t + '"]');
        if (cb) cb.checked = true;
      });
    }
    if (filters.categories) {
      String(filters.categories).split(',').forEach(function (cat) {
        var el = root.querySelector('[data-filter-cat="' + cat + '"]');
        if (el) el.checked = true;
      });
    }
    collectFilters();
  }

  function clearAllFilters() {
    root.querySelectorAll('[data-filter]').forEach(function (el) {
      if (el.type === 'checkbox') el.checked = el.getAttribute('data-filter') === 'has_spots';
      else el.value = '';
    });
    root.querySelectorAll('[data-filter-cat], [data-filter-event-type], [data-filter-doc]').forEach(function (el) { el.checked = false; });
    var parentSel = document.getElementById('lnkup-filter-event-type-parent');
    var sel = document.getElementById('lnkup-filter-event-type');
    if (parentSel) parentSel.value = '';
    if (sel) {
      sel.innerHTML = '<option value="">All subtypes</option>';
      sel.value = '';
      sel.disabled = false;
    }
    state.filters = { has_spots: true };
    state.q = '';
    var searchInput = document.getElementById('lnkup-search-input');
    if (searchInput) searchInput.value = '';
    resetAndSearch();
  }

  function resetAndSearch() {
    state.page = 1;
    state.hasMore = true;
    loadResults(false);
  }

  function parseApplyId(href) {
    try {
      var url = new URL(href, window.location.origin);
      return parseInt(url.searchParams.get('apply'), 10) || 0;
    } catch (e) {
      return 0;
    }
  }

  function setApplyUrl(oppId) {
    var params = new URLSearchParams(window.location.search);
    if (oppId) {
      params.set('apply', String(oppId));
    } else {
      params.delete('apply');
    }
    var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', next);
  }

  function closeApplyModal() {
    var modal = document.getElementById('lnkup-apply-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.hidden = true;
    document.body.classList.remove('lnkup-apply-open');
    var body = document.getElementById('lnkup-apply-modal-body');
    if (body) body.innerHTML = '';
    setApplyUrl(0);
  }

  function openApplyModal(oppId, triggerEl) {
    var modal = document.getElementById('lnkup-apply-modal');
    var body = document.getElementById('lnkup-apply-modal-body');
    if (!modal || !body || !oppId) return;
    if (triggerEl) {
      triggerEl.classList.add('is-loading');
      triggerEl.setAttribute('aria-busy', 'true');
    }
    body.innerHTML = '<p class="lnkup-muted">Loading…</p>';
    modal.hidden = false;
    modal.classList.add('is-open');
    document.body.classList.add('lnkup-apply-open');
    setApplyUrl(oppId);
    if (!cfg.restUrl) {
      body.innerHTML = '<p class="lnkup-muted">Unable to load application form.</p>';
      if (triggerEl) {
        triggerEl.classList.remove('is-loading');
        triggerEl.removeAttribute('aria-busy');
      }
      return;
    }
    api('/apply/' + oppId).then(function (data) {
      body.innerHTML = data.html || '';
      if (triggerEl && data.button_state) {
        if (data.button_state === 'already_applied' || data.button_state === 'applied') {
          triggerEl.textContent = 'Applied';
          triggerEl.classList.add('is-disabled');
        } else if (data.button_state === 'closed' || data.button_state === 'full') {
          triggerEl.textContent = data.button_state === 'full' ? 'Full' : 'Closed';
          triggerEl.classList.add('is-disabled');
        }
      }
    }).catch(function () {
      body.innerHTML = '<p class="lnkup-muted">Could not load application form. <a href="' + escapeAttr(cfg.opportunitiesUrl || window.location.pathname) + '?apply=' + oppId + '">Try again</a></p>';
    }).finally(function () {
      if (triggerEl) {
        triggerEl.classList.remove('is-loading');
        triggerEl.removeAttribute('aria-busy');
      }
    });
  }

  window.lnkupCloseApplyModal = closeApplyModal;
  window.lnkupOpenApplyModal = openApplyModal;

  function bindClearInline() {
    var btn = document.getElementById('lnkup-clear-filters-inline');
    if (btn) btn.addEventListener('click', clearAllFilters);
  }

  function trackGuestRecent(id) {
    if (cfg.isLoggedIn) return;
    try {
      var list = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      list = list.filter(function (x) { return x !== id; });
      list.unshift(id);
      localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 20)));
    } catch (e) { /* ignore */ }
  }

  function trackCards() {
    root.querySelectorAll('.lnkup-event-card--v2').forEach(function (card) {
      if (card.dataset.tracked) return;
      card.dataset.tracked = '1';
      var id = card.getAttribute('data-opportunity-id');
      trackGuestRecent(parseInt(id, 10));
      if (cfg.restUrl) api('/track/view', { method: 'POST', body: { opportunity_id: parseInt(id, 10), type: 'opportunity' } });
    });
  }

  function toggleDrawer(open) {
    var drawer = document.getElementById('lnkup-filter-drawer');
    if (!drawer) return;
    var show = open !== undefined ? open : drawer.classList.contains('lnkup-mp-v2__filter-drawer--closed');
    drawer.classList.toggle('lnkup-mp-v2__filter-drawer--closed', !show);
    document.getElementById('lnkup-filter-toggle') && document.getElementById('lnkup-filter-toggle').setAttribute('aria-expanded', show ? 'true' : 'false');
  }

  var searchInput = document.getElementById('lnkup-search-input');
  var searchForm = document.getElementById('lnkup-search-form');

  function runSearchNow() {
    if (searchInput) state.q = searchInput.value.trim();
    syncRelevanceSort();
    clearTimeout(debounceTimer);
    if (phaseAtLeast('1b')) loadSuggestions(state.q);
    resetAndSearch();
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      runSearchNow();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        state.q = searchInput.value.trim();
        if (phaseAtLeast('1b')) loadSuggestions(state.q);
        resetAndSearch();
      }, 300);
    });
    searchInput.addEventListener('focus', function () {
      if (phaseAtLeast('1b')) loadSuggestions(state.q);
    });
  }

  document.getElementById('lnkup-suggestions') && document.getElementById('lnkup-suggestions').addEventListener('click', function (e) {
    var btn = e.target.closest('.lnkup-suggestion-item');
    if (!btn || !searchInput) return;
    if (btn.dataset.id) {
      document.getElementById('lnkup-suggestions').hidden = true;
      openApplyModal(parseInt(btn.dataset.id, 10));
      return;
    }
    if (btn.dataset.q) { searchInput.value = btn.dataset.q; state.q = btn.dataset.q; resetAndSearch(); }
    document.getElementById('lnkup-suggestions').hidden = true;
  });

  document.getElementById('lnkup-sort') && document.getElementById('lnkup-sort').addEventListener('change', function (e) {
    state.sort = e.target.value;
    if (state.sort === 'closest' && navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (pos) {
        state.lat = pos.coords.latitude;
        state.lng = pos.coords.longitude;
        resetAndSearch();
      }, resetAndSearch);
    } else resetAndSearch();
  });

  document.getElementById('lnkup-filter-attendance') && document.getElementById('lnkup-filter-attendance').addEventListener('change', function () {
    collectFilters();
    resetAndSearch();
  });

  root.addEventListener('change', function (e) {
    var key = e.target && e.target.getAttribute && e.target.getAttribute('data-filter');
    if (key === 'lnkup_only' && e.target.checked) {
      var inc = root.querySelector('[data-filter="include_external"]');
      if (inc) inc.checked = false;
    }
    if (key === 'include_external' && e.target.checked) {
      var only = root.querySelector('[data-filter="lnkup_only"]');
      if (only) only.checked = false;
    }
  });

  var marketSelectEl = root.querySelector('[data-filter="market_select"]');
  if (marketSelectEl) {
    marketSelectEl.addEventListener('change', function () {
      collectFilters();
      resetAndSearch();
    });
  }

  document.getElementById('lnkup-apply-filters') && document.getElementById('lnkup-apply-filters').addEventListener('click', function () {
    collectFilters();
    resetAndSearch();
    toggleDrawer(false);
  });

  document.getElementById('lnkup-clear-filters') && document.getElementById('lnkup-clear-filters').addEventListener('click', clearAllFilters);
  document.getElementById('lnkup-empty-clear') && document.getElementById('lnkup-empty-clear').addEventListener('click', clearAllFilters);
  document.getElementById('lnkup-error-retry') && document.getElementById('lnkup-error-retry').addEventListener('click', resetAndSearch);

  document.getElementById('lnkup-filter-toggle') && document.getElementById('lnkup-filter-toggle').addEventListener('click', function () { toggleDrawer(); });
  document.getElementById('lnkup-filter-fab') && document.getElementById('lnkup-filter-fab').addEventListener('click', function () { toggleDrawer(true); });

  /**
   * Render saved search chips for logged-in vendors.
   */
  function loadSavedSearchChips() {
    if (!cfg.isLoggedIn || !cfg.isVendor) {
      return;
    }
    var wrap = document.getElementById('lnkup-saved-searches');
    var chips = document.getElementById('lnkup-saved-search-chips');
    if (!wrap || !chips) {
      return;
    }
    api('/saved-searches').then(function (res) {
      var rows = Array.isArray(res) ? res : (res && res.searches ? res.searches : []);
      if (!rows || !rows.length) {
        wrap.hidden = true;
        return;
      }
      wrap.hidden = false;
      chips.innerHTML = '';
      rows.forEach(function (row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lnkup-badge lnkup-badge--secondary lnkup-saved-search-chip';
        btn.textContent = row.name;
        btn.addEventListener('click', function () {
          var f = row.filters || {};
          state.q = f.q || '';
          state.sort = f.sort || 'newest';
          state.filters = Object.assign({ has_spots: true }, f);
          delete state.filters.q;
          delete state.filters.sort;
          var input = document.getElementById('lnkup-search-input');
          if (input) {
            input.value = state.q;
          }
          resetAndSearch();
        });
        chips.appendChild(btn);
      });
    }).catch(function () {
      wrap.hidden = true;
    });
  }

  var saveSearchModal = document.getElementById('lnkup-save-search-modal');
  var saveSearchForm = document.getElementById('lnkup-save-search-form');
  var saveSearchSaving = false;

  function filtersAreEmpty() {
    collectFilters();
    if (state.q && state.q.trim()) return false;
    var keys = Object.keys(state.filters || {});
    return keys.filter(function (k) {
      if (k === 'has_spots') return false;
      var v = state.filters[k];
      if (Array.isArray(v)) return v.length > 0;
      return v !== '' && v !== null && v !== undefined && v !== false;
    }).length === 0;
  }

  function buildFilterSummaryChips(container) {
    if (!container) return;
    container.innerHTML = '';
    collectFilters();
    var summary = [];
    if (state.q) summary.push({ label: 'Search: ' + state.q });
    Object.keys(state.filters || {}).forEach(function (key) {
      if (key === 'has_spots') return;
      var val = state.filters[key];
      if (Array.isArray(val) && val.length) {
        val.forEach(function (v) { summary.push({ label: v }); });
      } else if (val && val !== true) {
        summary.push({ label: key + ': ' + val });
      } else if (val === true) {
        summary.push({ label: key.replace(/_/g, ' ') });
      }
    });
    if (!summary.length) {
      container.innerHTML = '<span class="lnkup-muted">' + 'All new opportunities' + '</span>';
      return;
    }
    summary.forEach(function (chip) {
      var span = document.createElement('span');
      span.className = 'lnkup-badge lnkup-badge--outline';
      span.textContent = chip.label;
      container.appendChild(span);
    });
  }

  function toggleSaveSearchModal(open) {
    if (!saveSearchModal) return;
    var guest = document.getElementById('lnkup-save-search-logged-out');
    var emptyWarn = document.getElementById('lnkup-save-search-empty-warning');
    var errEl = document.getElementById('lnkup-save-search-error');
    var okEl = document.getElementById('lnkup-save-search-success');
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    if (okEl) okEl.hidden = true;
    if (!open) {
      saveSearchModal.hidden = true;
      document.body.classList.remove('lnkup-modal-open');
      return;
    }
    saveSearchModal.hidden = false;
    document.body.classList.add('lnkup-modal-open');
    if (!cfg.isLoggedIn || !cfg.isVendor) {
      if (guest) guest.hidden = false;
      if (saveSearchForm) saveSearchForm.hidden = true;
      return;
    }
    if (guest) guest.hidden = true;
    if (saveSearchForm) saveSearchForm.hidden = false;
    var nameInput = document.getElementById('lnkup-save-search-name');
    if (nameInput && !nameInput.value) {
      nameInput.value = state.q || '';
    }
    buildFilterSummaryChips(document.getElementById('lnkup-save-search-summary'));
    if (emptyWarn) emptyWarn.hidden = !filtersAreEmpty();
  }

  saveSearchModal && saveSearchModal.querySelectorAll('[data-save-search-close]').forEach(function (el) {
    el.addEventListener('click', function () { toggleSaveSearchModal(false); });
  });

  document.getElementById('lnkup-save-search') && document.getElementById('lnkup-save-search').addEventListener('click', function () {
    toggleSaveSearchModal(true);
  });

  saveSearchForm && saveSearchForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (saveSearchSaving) return;
    var nameInput = document.getElementById('lnkup-save-search-name');
    var alertsEl = document.getElementById('lnkup-save-search-alerts');
    var freqEl = document.getElementById('lnkup-save-search-frequency');
    var errEl = document.getElementById('lnkup-save-search-error');
    var okEl = document.getElementById('lnkup-save-search-success');
    var submitBtn = document.getElementById('lnkup-save-search-submit');
    var name = nameInput ? nameInput.value.trim() : '';
    if (!name) {
      name = filtersAreEmpty() ? 'All new opportunities' : (state.q || 'My Search');
    }
    collectFilters();
    saveSearchSaving = true;
    if (submitBtn) submitBtn.disabled = true;
    if (errEl) { errEl.hidden = true; }
    api('/saved-searches', {
      method: 'POST',
      body: {
        name: name,
        filters: Object.assign({}, state.filters, { q: state.q, sort: state.sort }),
        alert_enabled: alertsEl ? alertsEl.checked : true,
        frequency: freqEl ? freqEl.value : 'daily'
      }
    }).then(function () {
      if (okEl) okEl.hidden = false;
      loadSavedSearchChips();
      if (window.lnkupSavedSearches && window.lnkupSavedSearches.refresh) {
        window.lnkupSavedSearches.refresh();
      }
      setTimeout(function () { toggleSaveSearchModal(false); }, 1200);
    }).catch(function (err) {
      if (err && err.code === 'duplicate_search' && errEl) {
        errEl.textContent = err.message + ' ' + (cfg.favoritesUrl ? 'Manage saved searches in your dashboard.' : '');
        errEl.hidden = false;
        return;
      }
      if (errEl) {
        errEl.textContent = err.message || 'Could not save search.';
        errEl.hidden = false;
      }
    }).finally(function () {
      saveSearchSaving = false;
      if (submitBtn) submitBtn.disabled = false;
    });
  });

  window.lnkupMarketplaceSaveSearch = {
    open: toggleSaveSearchModal,
    buildSummary: buildFilterSummaryChips
  };

  root.querySelectorAll('.lnkup-quick-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      try {
        var quick = JSON.parse(chip.getAttribute('data-quick-filter') || '{}');
        applyFiltersObject(quick);
        resetAndSearch();
      } catch (e) { /* ignore */ }
    });
  });

  document.getElementById('lnkup-active-filters') && document.getElementById('lnkup-active-filters').addEventListener('click', function (e) {
    var chip = e.target.closest('.lnkup-active-filter-chip');
    if (!chip) return;
    var key = chip.getAttribute('data-filter-key');
    var val = chip.getAttribute('data-filter-value');
    if (key === 'q') { state.q = ''; if (searchInput) searchInput.value = ''; }
    else if (key === 'sort') { state.sort = 'newest'; var sortEl = document.getElementById('lnkup-sort'); if (sortEl) sortEl.value = 'newest'; }
    else {
      var el = root.querySelector('[data-filter="' + key + '"]');
      if (el) { if (el.type === 'checkbox') el.checked = false; else el.value = ''; }
      if (key === 'categories' || key === 'event_types' || key === 'markets') {
        root.querySelectorAll('[data-filter-cat="' + val + '"], [data-filter-event-type="' + val + '"]').forEach(function (c) { c.checked = false; });
        if (key === 'event_types') {
          var parentSel = document.getElementById('lnkup-filter-event-type-parent');
          var sel = document.getElementById('lnkup-filter-event-type');
          if (parentSel) parentSel.value = '';
          if (sel) {
            sel.innerHTML = '<option value="">All subtypes</option>';
            sel.value = '';
            sel.disabled = true;
          }
        }
      }
    }
    resetAndSearch();
  });

  if (phaseAtLeast('1b')) {
    var exploreEl = document.getElementById('lnkup-explore-more');
    if (exploreEl) {
      exploreEl.addEventListener('click', function (e) {
        var chip = e.target.closest('.lnkup-collection-chip');
        if (!chip || !exploreEl.contains(chip)) return;
        applyCollectionFilters(chip.getAttribute('data-collection'));
      });
    }
  }

  function applyViewMode(view, persist) {
    if (!view) return;
    root.querySelectorAll('[data-view]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-view') === view);
    });
    var splitLayout = document.getElementById('lnkup-split-layout');
    var gridView = document.getElementById('lnkup-grid-view');
    var mapView = document.getElementById('lnkup-map-view');
    var grid = document.getElementById('lnkup-results-grid');
    if (view === 'list' && grid) grid.classList.add('lnkup-event-grid--list');
    else if (grid) grid.classList.remove('lnkup-event-grid--list');
    if (view === 'grid' || view === 'list') {
      if (splitLayout) splitLayout.classList.remove('is-split');
      if (gridView) gridView.hidden = false;
      if (mapView) mapView.hidden = true;
    } else if (view === 'map' && phaseAtLeast('1b')) {
      if (splitLayout) splitLayout.classList.remove('is-split');
      if (gridView) gridView.hidden = true;
      if (mapView) mapView.hidden = false;
      if (window.lnkupInitMap) window.lnkupInitMap();
      if (window.lnkupMapInvalidateSize) window.lnkupMapInvalidateSize();
    } else if (view === 'split' && phaseAtLeast('1b')) {
      if (splitLayout) splitLayout.classList.add('is-split');
      if (gridView) gridView.hidden = false;
      if (mapView) mapView.hidden = false;
      if (window.lnkupInitMap) window.lnkupInitMap();
      if (window.lnkupMapInvalidateSize) window.lnkupMapInvalidateSize();
    } else {
      applyViewMode('grid', false);
      return;
    }
    if (persist !== false) {
      try { localStorage.setItem(VIEW_KEY, view); } catch (e) { /* ignore */ }
    }
  }

  root.querySelectorAll('[data-view]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      applyViewMode(btn.getAttribute('data-view'));
    });
  });

  try {
    var savedView = localStorage.getItem(VIEW_KEY);
    if (savedView) applyViewMode(savedView, false);
  } catch (e) { /* ignore */ }

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting && state.hasMore && !state.loading) {
        state.page += 1;
        loadResults(true);
      }
    });
  }, { rootMargin: '200px' });
  var sentinel = document.getElementById('lnkup-infinite-loader');
  if (sentinel) observer.observe(sentinel);

  document.addEventListener('click', function (e) {
    var fav = e.target.closest('.lnkup-event-card__favorite');
    if (fav && cfg.isLoggedIn && cfg.restUrl) {
      e.preventDefault();
      api('/favorites', { method: 'POST', body: { type: fav.getAttribute('data-favorite-type'), id: parseInt(fav.getAttribute('data-favorite-id'), 10) } }).then(function (res) {
        fav.classList.toggle('is-active', res.favorited);
        fav.setAttribute('aria-pressed', res.favorited ? 'true' : 'false');
      });
      return;
    }
    var applyLink = e.target.closest('a[href*="apply="]');
    if (applyLink && root.contains(applyLink)) {
      var oppId = parseApplyId(applyLink.href);
      if (oppId) {
        e.preventDefault();
        openApplyModal(oppId, applyLink);
      }
      return;
    }
    if (e.target.closest('[data-apply-close]')) {
      closeApplyModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeApplyModal();
  });

  parseUrlFilters();
  bindMarketplaceEventTypeCascade();
  var parentSelInit = document.getElementById('lnkup-filter-event-type-parent');
  fillMarketplaceEventTypeChildren(parentSelInit ? parentSelInit.value : '', '');
  bindClearInline();
  var hasQuery = window.location.search.length > 1;
  var ssrLoaded = gridEl && gridEl.children.length > 0;
  if (hasQuery || !ssrLoaded) {
    resetAndSearch();
  } else {
    collectFilters();
    state.hasMore = true;
  }

  initExploreMore();
  loadSavedSearchChips();
  toggleDiscoveryRails();

  var initialApply = root.getAttribute('data-initial-apply');
  if (initialApply) {
    openApplyModal(parseInt(initialApply, 10));
  }
})();
