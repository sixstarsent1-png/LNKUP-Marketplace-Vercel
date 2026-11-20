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
  function initMapBuilder(shell) {
    var eventId = shell.getAttribute('data-event-id');
    if (!eventId || !base) {
      return;
    }
    var canvasWrap = shell.querySelector('#lnkup-map-canvas-wrap');
    var elContainer = shell.querySelector('#lnkup-map-elements');
    var routeSvg = shell.querySelector('#lnkup-map-routes');
    var drawer = shell.querySelector('#lnkup-map-drawer');
    var state = { elements: [], routes: [], map: null, layers: {} };

    mapFetch('/events/' + eventId).then(function (data) {
      if (!data || data.code) {
        return;
      }
      state.map = data.map;
      state.elements = data.elements || [];
      state.routes = data.routes || [];
      renderElements(elContainer, state.elements, {
        onSelect: function (el) {
          if (drawer) {
            drawer.hidden = false;
            shell.querySelector('.lnkup-map-shell__body').classList.add('has-drawer');
            drawer.innerHTML = '<h3>' + escapeHtml(el.label) + '</h3><p>' + escapeHtml(el.element_type) + '</p>';
          }
        }
      });
      renderRoutes(routeSvg, state.routes);
    }).catch(function (err) {
      if (elContainer) {
        elContainer.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml((err && err.message) || 'Could not load the map.') + '</p>';
      }
    });

    shell.addEventListener('click', function (e) {
      var action = e.target.closest('[data-map-action]');
      if (!action) {
        return;
      }
      var key = action.getAttribute('data-map-action');
      if (key === 'add-booth') {
        askLabel('Add booth', 'Booth number').then(function (num) {
          if (!num) {
            return;
          }
          var el = {
            element_type: 'booth',
            label: num,
            pos_x: 10 + Math.random() * 70,
            pos_y: 10 + Math.random() * 70,
            layer: 'booths',
            visibility: 'public'
          };
          mapFetch('/events/' + eventId + '/elements', { method: 'POST', body: { elements: [el] } }).then(function (res) {
            if (res.items) {
              state.elements = state.elements.concat(res.items);
              renderElements(elContainer, state.elements, {});
            }
          }).catch(mapFailed);
          if (window.lnkupOpsFetch) {
            Promise.resolve(window.lnkupOpsFetch('/events/' + eventId + '/booths', { method: 'POST', body: { booth_number: num, booth_type: 'standard' } })).catch(mapFailed);
          }
        });
      }
      if (key === 'save' && state.map) {
        mapFetch('/events/' + eventId, { method: 'POST', body: { status: 'draft' } })
          .then(function () { mapToast('Map saved.'); })
          .catch(mapFailed);
      }
      if (key === 'publish') {
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
            renderElements(elContainer, state.elements, { layers: state.layers });
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
              renderElements(elContainer, state.elements, {});
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
