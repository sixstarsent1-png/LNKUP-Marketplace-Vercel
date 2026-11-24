(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.mapsRestUrl || '';
  var nonce = ajax.restNonce || '';
  var providerCfg = ajax.mapProvider || {};

  /**
   * Map REST fetch helper.
   */
  function mapFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var type = r.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          throw new Error('Something went wrong. Refresh and try again.');
        }
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error((body && body.message) || 'The map could not be updated.');
          }
          return body;
        });
      });
  }

  function mapToast(message, type) {
    if (typeof window.lnkupShowToast === 'function') {
      window.lnkupShowToast(message, type || 'success');
    }
  }

  function mapFailed(err) {
    mapToast((err && err.message) || 'The map could not be updated.', 'error');
  }

  function askLabel(title, label, value) {
    if (window.LNKupUI && typeof window.LNKupUI.askText === 'function') {
      return window.LNKupUI.askText({ title: title, label: label, value: value || '', required: true, confirmLabel: 'Add' });
    }
    return Promise.resolve(null);
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  window.lnkupMapFetch = mapFetch;

  function renderElements(container, elements, options) {
    options = options || {};
    container.innerHTML = '';
    (elements || []).forEach(function (el) {
      if (options.layers && options.layers[el.layer] === false) {
        return;
      }
      var node = document.createElement('button');
      node.type = 'button';
      node.className = 'lnkup-map-el lnkup-map-el--' + escapeHtml(el.element_type).replace(/[^a-z_]/g, '');
      if (el.live_status) {
        node.className += ' lnkup-map-status--' + el.live_status;
      }
      node.style.left = el.pos_x + '%';
      node.style.top = el.pos_y + '%';
      node.style.width = (el.width || 8) + '%';
      node.style.height = (el.height || 8) + '%';
      if (el.rotation) {
        node.style.transform = 'rotate(' + el.rotation + 'deg)';
      }
      node.textContent = el.label || el.element_type;
      node.dataset.elementId = el.id;
      node.dataset.linkedId = el.linked_id || '';
      node.addEventListener('click', function (e) {
        e.stopPropagation();
        if (options.onSelect) {
          options.onSelect(el, node);
        }
      });
      container.appendChild(node);
    });
  }

  function renderRoutes(svg, routes) {
    if (!svg) {
      return;
    }
    svg.innerHTML = '';
    (routes || []).forEach(function (route) {
      var pts = route.path || [];
      if (pts.length < 2) {
        return;
      }
      var d = 'M ' + pts.map(function (p) { return p.x + ' ' + p.y; }).join(' L ');
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', d);
      if (route.restricted) {
        path.setAttribute('stroke', '#ef4444');
      }
      svg.appendChild(path);
    });
  }

  /**
   * Map builder for organizer ops.
   */
  /** Layout warnings before publishing (not a safety certification). */
  function layoutWarnings(elements) {
    var warnings = [];
    var seen = {};
    elements.forEach(function (el) {
      if (el.element_type !== 'booth') {
        return;
      }
      var key = String(el.label || '').trim().toUpperCase();
      if (key && seen[key]) {
        warnings.push('Booth ' + el.label + ' appears more than once.');
      }
      seen[key] = true;
    });
    for (var i = 0; i < elements.length; i++) {
      for (var j = i + 1; j < elements.length; j++) {
        var a = elements[i];
        var b = elements[j];
        var aw = Number(a.width || 8), ah = Number(a.height || 8), bw = Number(b.width || 8), bh = Number(b.height || 8);
        var overlap = Number(a.pos_x) < Number(b.pos_x) + bw && Number(b.pos_x) < Number(a.pos_x) + aw &&
          Number(a.pos_y) < Number(b.pos_y) + bh && Number(b.pos_y) < Number(a.pos_y) + ah;
        if (overlap && a.element_type === 'booth' && b.element_type === 'booth') {
          warnings.push(a.label + ' overlaps ' + b.label + '.');
        }
      }
    }
    return warnings.slice(0, 12);
  }

  /** Accessible table of every element on the map (same data as the canvas). */
  function renderElementTable(shell, elements) {
    var host = shell.querySelector('[data-map-table]');
    if (!host) {
      host = document.createElement('details');
      host.className = 'oos-map-table';
      host.setAttribute('data-map-table', '');
      host.innerHTML = '<summary>Map as a table</summary><div></div>';
      shell.appendChild(host);
    }
    var rows = elements.map(function (el) {
      return '<tr><td>' + escapeHtml(el.label || '') + '</td><td>' + escapeHtml(String(el.element_type || '').replace(/_/g, ' ')) + '</td><td>' + escapeHtml(el.vendor_name || (el.linked_id ? 'Linked booth' : '—')) + '</td><td>' +
        Math.round(Number(el.pos_x) || 0) + '%, ' + Math.round(Number(el.pos_y) || 0) + '%</td></tr>';
    }).join('');
    host.querySelector('div').innerHTML = elements.length
      ? '<div class="vos-table-wrap"><table class="vos-table"><caption class="vos-sr">Map elements</caption><thead><tr><th scope="col">Label</th><th scope="col">Type</th><th scope="col">Vendor</th><th scope="col">Position (left, top)</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
      : '<p class="lnkup-muted">Nothing is on the map yet.</p>';
  }

  /** Start screen: no map, upload a floor plan, or build an interactive layout. */
  function showStartScreen(shell, eventId, onLayout) {
    if (shell.querySelector('[data-map-start]')) {
      return;
    }
    var start = document.createElement('section');
    start.className = 'oos-map-start';
    start.setAttribute('data-map-start', '');
    start.setAttribute('data-testid', 'lnkup-map-start');
    start.innerHTML = '<h3>How do you want to lay out booths?</h3>' +
      '<div class="oos-map-start__grid">' +
      '<div class="oos-map-start__card"><strong>No map</strong><p class="lnkup-muted">Use the booth list. Vendors see their booth number on their pass.</p><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-map-start-choice="none">Use the booth list</button></div>' +
      '<div class="oos-map-start__card"><strong>Upload a floor plan</strong><p class="lnkup-muted">A PDF export or image of the venue map. Vendors can open it from their pass.</p><label class="lnkup-btn lnkup-btn--outline lnkup-btn--sm">Choose image<input type="file" accept="image/*" class="lnkup-map-upload-input vos-sr" data-event-id="' + escapeHtml(eventId) + '" /></label></div>' +
      '<div class="oos-map-start__card"><strong>Interactive layout</strong><p class="lnkup-muted">Place your existing booths on a canvas and publish a live map.</p><button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-map-start-choice="layout">Build a layout</button></div>' +
      '</div>';
    shell.insertBefore(start, shell.firstChild);
    shell.classList.add('is-starting');
    start.addEventListener('click', function (e) {
      var choice = e.target.closest('[data-map-start-choice]');
      if (!choice) {
        return;
      }
      if (choice.getAttribute('data-map-start-choice') === 'none') {
        var boothsTab = document.querySelector('[data-ops-tab="booths"]');
        if (boothsTab) {
          boothsTab.click();
        }
        return;
      }
      start.remove();
      shell.classList.remove('is-starting');
      onLayout();
    });
  }

  function initMapBuilder(shell) {
    var eventId = shell.getAttribute('data-event-id');
    if (!eventId || !base || shell.dataset.builderInit === '1') {
      return;
    }
    shell.dataset.builderInit = '1';
    var canvasWrap = shell.querySelector('#lnkup-map-canvas-wrap');
    var elContainer = shell.querySelector('#lnkup-map-elements');
    var routeSvg = shell.querySelector('#lnkup-map-routes');
    var drawer = shell.querySelector('#lnkup-map-drawer');
    var state = { elements: [], routes: [], map: null, layers: {} };

    function inspect(el) {
      if (!drawer) {
        return;
      }
      drawer.hidden = false;
      shell.querySelector('.lnkup-map-shell__body').classList.add('has-drawer');
      drawer.innerHTML = '<h3>' + escapeHtml(el.label) + '</h3>' +
        '<dl class="vos-kv"><dt>Type</dt><dd>' + escapeHtml(String(el.element_type || '').replace(/_/g, ' ')) + '</dd>' +
        '<dt>Booth row</dt><dd>' + (el.linked_id ? 'Linked' : 'Not linked') + '</dd>' +
        '<dt>Vendor</dt><dd>' + escapeHtml(el.vendor_name || '—') + '</dd>' +
        '<dt>Position</dt><dd>' + Math.round(Number(el.pos_x) || 0) + '%, ' + Math.round(Number(el.pos_y) || 0) + '%</dd></dl>' +
        '<p class="lnkup-muted">Assign vendors from the Booths tab; the map follows the booth list.</p>';
    }

    function redraw() {
      renderElements(elContainer, state.elements, { layers: state.layers, onSelect: inspect });
      renderElementTable(shell, state.elements);
    }

    mapFetch('/events/' + eventId).then(function (data) {
      if (!data || data.code) {
        return;
      }
      state.map = data.map;
      state.elements = data.elements || [];
      state.routes = data.routes || [];
      redraw();
      renderRoutes(routeSvg, state.routes);
      if (!state.elements.length && !shell.querySelector('#lnkup-map-canvas-img')) {
        showStartScreen(shell, eventId, function () {});
      }
    }).catch(function (err) {
      if (elContainer) {
        elContainer.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml((err && err.message) || 'Could not load the map.') + '</p>';
      }
    });

    /** Place an existing booth row on the map, so map shapes never duplicate booths. */
    function placeBooth() {
      var placed = {};
      state.elements.forEach(function (el) { if (el.linked_id) { placed[el.linked_id] = true; } });
      var opsBase = String(ajax.operationsRestUrl || '').replace(/\/$/, '');
      if (!opsBase) {
        return;
      }
      fetch(opsBase + '/events/' + encodeURIComponent(eventId) + '/booths', { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var free = ((res && res.items) || []).filter(function (b) { return !placed[b.id]; });
          if (!free.length) {
            mapToast('Every booth is already on the map. Add booths on the Booths tab first.', 'error');
            return;
          }
          askLabel('Place a booth', 'Booth number (' + free.slice(0, 8).map(function (b) { return b.booth_number; }).join(', ') + (free.length > 8 ? '…' : '') + ')', free[0].booth_number).then(function (num) {
            var booth = free.filter(function (b) { return String(b.booth_number).toUpperCase() === String(num || '').trim().toUpperCase(); })[0];
            if (!num) {
              return;
            }
            if (!booth) {
              mapToast('That booth number is not in your booth list, or it is already on the map.', 'error');
              return;
            }
            var el = { element_type: 'booth', label: booth.booth_number, linked_type: 'booth', linked_id: booth.id, pos_x: 10 + Math.random() * 70, pos_y: 10 + Math.random() * 70, layer: 'booths', visibility: 'public' };
            mapFetch('/events/' + eventId + '/elements', { method: 'POST', body: { elements: [el] } }).then(function (out) {
              if (out.items) {
                state.elements = state.elements.concat(out.items);
                redraw();
              }
            }).catch(mapFailed);
          });
        }).catch(mapFailed);
    }

    shell.addEventListener('click', function (e) {
      var action = e.target.closest('[data-map-action]');
      if (!action) {
        return;
      }
      var key = action.getAttribute('data-map-action');
      if (key === 'add-booth') {
        placeBooth();
      }
      if (key === 'save' && state.map) {
        mapFetch('/events/' + eventId, { method: 'POST', body: { status: 'draft' } })
          .then(function () { mapToast('Map saved.'); })
          .catch(mapFailed);
      }
      if (key === 'publish') {
        var warnings = layoutWarnings(state.elements);
        if (warnings.length && !window.confirm('Layout warnings (not a safety check):\n\n- ' + warnings.join('\n- ') + '\n\nPublish anyway?')) {
          return;
        }
        mapFetch('/events/' + eventId + '/publish', { method: 'POST' })
          .then(function () { mapToast('Map published.'); })
          .catch(mapFailed);
      }
      if (key === 'layers') {
        var panel = shell.querySelector('.lnkup-map-layer-panel');
        if (panel) {
          panel.hidden = !panel.hidden;
          return;
        }
        panel = document.createElement('div');
        panel.className = 'lnkup-map-layer-panel';
        ['booths', 'parking', 'routes', 'safety'].forEach(function (layer) {
          var label = document.createElement('label');
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.checked = true;
          cb.dataset.layer = layer;
          cb.addEventListener('change', function () {
            state.layers[layer] = cb.checked;
            renderElements(elContainer, state.elements, { layers: state.layers, onSelect: inspect });
          });
          label.appendChild(cb);
          label.appendChild(document.createTextNode(layer));
          panel.appendChild(label);
        });
        canvasWrap.appendChild(panel);
      }
    });

    if (canvasWrap) {
      canvasWrap.addEventListener('dblclick', function (e) {
        if (!state.map || e.target.closest('.lnkup-map-el')) {
          return;
        }
        var rect = canvasWrap.getBoundingClientRect();
        var pos_x = ((e.clientX - rect.left) / rect.width) * 100;
        var pos_y = ((e.clientY - rect.top) / rect.height) * 100;
        askLabel('Add map element', 'Element label', 'New element').then(function (label) {
          if (!label) {
            return;
          }
          mapFetch('/events/' + eventId + '/elements', {
            method: 'POST',
            body: { elements: [{ element_type: 'custom', label: label, pos_x: pos_x, pos_y: pos_y, layer: 'booths', visibility: 'public' }] }
          }).then(function (res) {
            if (res.items) {
              state.elements = state.elements.concat(res.items);
              redraw();
            }
          }).catch(mapFailed);
        });
      });
    }
  }

  /**
   * Live operations map polling.
   */
  function initLiveMap(shell) {
    var eventId = shell.getAttribute('data-event-id');
    if (!eventId) {
      return;
    }
    var elContainer = shell.querySelector('#lnkup-map-elements');
    var drawer = shell.querySelector('#lnkup-map-drawer');

    function refresh() {
      mapFetch('/events/' + eventId + '/live').then(function (data) {
        if (!data || !elContainer) {
          return;
        }
        if ((!data.elements || !data.elements.length) && !shell.querySelector('.lnkup-map-empty') && !shell.querySelector('#lnkup-map-canvas-img')) {
          var wrap = shell.querySelector('#lnkup-map-canvas-wrap');
          if (wrap) {
            wrap.insertAdjacentHTML('afterbegin', '<div class="lnkup-map-empty"><p class="lnkup-muted">No map data yet. Build your venue layout to get started.</p><div class="lnkup-map-empty__actions"><button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-ops-goto-tab" data-ops-tab="map-builder">Open Map Builder</button></div></div>');
          }
        }
        renderElements(elContainer, data.elements || [], {
          onSelect: function (el) {
            if (!drawer) {
              return;
            }
            drawer.hidden = false;
            var html = '<h3>' + escapeHtml(el.label) + '</h3>';
            html += '<p>Status: ' + escapeHtml(el.live_status || 'unknown') + '</p>';
            if (el.booth && el.booth.application_id) {
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm" data-ops-status="checked_in" data-app-id="' + el.booth.application_id + '">Check in</button>';
            }
            drawer.innerHTML = html;
          }
        });
      }).catch(function () {
        // Live polling retries on the next tick; the last good map stays visible.
      });
    }
    refresh();
    setInterval(refresh, 30000);

    shell.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ops-status]');
      if (!btn || !window.lnkupOpsFetch) {
        return;
      }
      var appId = btn.getAttribute('data-app-id');
      Promise.resolve(window.lnkupOpsFetch('/applications/' + appId + '/check-in', { method: 'POST' })).then(refresh).catch(mapFailed);
    });
  }

  /**
   * Vendor packet map.
   */
  function initPacketMap(wrap) {
    var appId = wrap.getAttribute('data-app-id');
    if (!appId) {
      return;
    }
    mapFetch('/packet/' + appId).then(function (data) {
      if (!data || data.code) {
        wrap.innerHTML = '<p class="lnkup-muted">Map unavailable.</p>';
        return;
      }
      var shell = document.createElement('div');
      shell.className = 'lnkup-map-shell';
      shell.innerHTML = '<div class="lnkup-map-canvas-wrap" style="min-height:240px"><div class="lnkup-map-elements" id="lnkup-packet-map-el"></div></div>';
      wrap.appendChild(shell);
      var container = shell.querySelector('#lnkup-packet-map-el');
      var boothId = data.booth ? data.booth.id : null;
      renderElements(container, data.elements || [], {
        onSelect: function () {}
      });
      container.querySelectorAll('.lnkup-map-el').forEach(function (node) {
        if (boothId && node.dataset.linkedId === String(boothId)) {
          node.classList.add('is-selected');
        }
      });
      if (data.venue && data.venue.lat) {
        var dir = document.createElement('a');
        dir.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost';
        dir.href = 'https://www.openstreetmap.org/directions?from=&to=' + data.venue.lat + ',' + data.venue.lng;
        dir.target = '_blank';
        dir.rel = 'noopener';
        dir.textContent = 'Directions';
        wrap.appendChild(dir);
      }
    }).catch(function () {
      wrap.innerHTML = '<p class="lnkup-muted">Map unavailable.</p>';
    });
  }

  /**
   * Discovery pin drawer.
   */
  window.lnkupOpenPinDrawer = function (pin) {
    var existing = document.getElementById('lnkup-map-pin-drawer');
    if (existing) {
      existing.remove();
    }
    var backdrop = document.createElement('div');
    backdrop.className = 'lnkup-map-pin-drawer__backdrop';
    backdrop.addEventListener('click', function () { drawer.remove(); backdrop.remove(); });
    var drawer = document.createElement('div');
    drawer.id = 'lnkup-map-pin-drawer';
    drawer.className = 'lnkup-map-pin-drawer is-open';
    drawer.setAttribute('role', 'dialog');
    var cats = (pin.categories || []).join(', ');
    drawer.innerHTML = '<h3>' + escapeHtml(pin.title) + '</h3>' +
      '<p>' + escapeHtml(pin.city + ', ' + pin.state) + '</p>' +
      '<p>Fee: $' + escapeHtml(String(pin.vendor_fee)) + ' · Spots: ' + escapeHtml(String(pin.spots_remaining)) + '/' + escapeHtml(String(pin.spots_total)) + '</p>' +
      (cats ? '<p>' + escapeHtml(cats) + '</p>' : '') +
      (pin.deadline ? '<p>Deadline: ' + escapeHtml(pin.deadline) + '</p>' : '') +
      '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:1rem">' +
      '<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="' + escapeHtml(pin.apply_url) + '">Apply</a>' +
      '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="https://www.openstreetmap.org/directions?from=&to=' + pin.lat + ',' + pin.lng + '" target="_blank" rel="noopener">Directions</a>' +
      '</div>';
    document.body.appendChild(backdrop);
    document.body.appendChild(drawer);
  };

  function initShells() {
    document.querySelectorAll('.lnkup-map-shell').forEach(function (shell) {
      var mode = shell.getAttribute('data-mode') || 'view';
      if (mode === 'builder') {
        initMapBuilder(shell);
      } else if (mode === 'live') {
        initLiveMap(shell);
      }
    });
    document.querySelectorAll('.lnkup-map-packet-wrap').forEach(initPacketMap);
    bindMapUpload();
  }
  function bindMapUpload() {
    if (document.documentElement.dataset.lnkupMapUploadBound) {
      return;
    }
    document.documentElement.dataset.lnkupMapUploadBound = '1';
    document.addEventListener('change', function (e) {
      var input = e.target && e.target.classList && e.target.classList.contains('lnkup-map-upload-input') ? e.target : null;
      if (!input || !input.files || !input.files[0]) {
        return;
      }
      var eventId = input.getAttribute('data-event-id');
      if (!eventId) {
        return;
      }
      var wpRoot = String(ajax.restUrl || '').replace(/lnkup\/v1\/?$/, '');
      var fd = new FormData();
      fd.append('file', input.files[0]);
      fetch(wpRoot + 'wp/v2/media', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce },
        body: fd
      }).then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok || !body || !body.id) {
            throw new Error((body && body.message) || 'Upload failed');
          }
          return mapFetch('/events/' + eventId, { method: 'POST', body: { attachment_id: body.id } });
        });
      }).then(function () {
        window.location.reload();
      }).catch(function (err) {
        input.value = '';
        mapFailed(err && err.message ? err : new Error('The floor plan could not be uploaded.'));
      });
    });
  }

  window.lnkupMapsInitShells = initShells;

  document.addEventListener('lnkup-ops-panel-open', function (e) {
    if (!e.detail) {
      return;
    }
    if (e.detail.tab === 'map-builder' || e.detail.tab === 'live-map') {
      initShells();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initShells);
  } else {
    initShells();
  }
})();
