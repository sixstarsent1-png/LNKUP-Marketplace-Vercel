(function () {
  var map;
  var markerGroup;
  var clusterLoaded = false;
  var pinCache = [];

  function loadScript(src) {
    return new Promise(function (resolve) {
      var script = document.createElement('script');
      script.src = src;
      script.onload = resolve;
      document.body.appendChild(script);
    });
  }

  function loadStylesheet(href) {
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
  }

  function getMapFetch() {
    return window.lnkupMapFetch || null;
  }

  function fetchPins(query) {
    var mapFetch = getMapFetch();
    if (!mapFetch) {
      return Promise.resolve([]);
    }
    var qs = query ? '?' + query : '';
    return mapFetch('/discovery/pins' + qs).then(function (data) {
      return (data && data.pins) ? data.pins : [];
    }).catch(function () { return []; });
  }

  function buildPopupFromPin(pin) {
    return '<div class="lnkup-map-popup">' +
      '<strong>' + pin.title + '</strong>' +
      '<p>' + pin.city + ', ' + pin.state + '</p>' +
      '<p>$' + pin.vendor_fee + ' · ' + pin.spots_remaining + ' spots left</p>' +
      '<div class="lnkup-map-popup__actions">' +
      '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-map-pin-detail" data-pin="' + encodeURIComponent(JSON.stringify(pin)) + '">Details</button>' +
      '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' + pin.apply_url + '">Apply</a>' +
      '</div></div>';
  }

  function buildPopup(card) {
    var title = card.querySelector('h3');
    var id = card.getAttribute('data-opportunity-id');
    var lat = parseFloat(card.getAttribute('data-lat'));
    var lng = parseFloat(card.getAttribute('data-lng'));
    var cfg = window.lnkupAjax || {};
    var applyBase = cfg.opportunitiesUrl || '/opportunities/';
    var applyUrl = applyBase + (applyBase.indexOf('?') > -1 ? '&' : '?') + 'apply=' + id;
    var directionsUrl = 'https://www.openstreetmap.org/directions?from=&to=' + lat + ',' + lng;
    return '<div class="lnkup-map-popup">' +
      '<strong>' + (title ? title.textContent : '') + '</strong>' +
      '<div class="lnkup-map-popup__actions">' +
      '<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="' + applyUrl + '">Apply</a> ' +
      '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' + directionsUrl + '" target="_blank" rel="noopener">Directions</a>' +
      '</div></div>';
  }

  function renderPins(pins) {
    if (!map || !markerGroup) {
      return;
    }
    markerGroup.clearLayers();
    var bounds = [];
    pins.forEach(function (pin) {
      if (!pin.lat || !pin.lng) {
        return;
      }
      var marker = L.marker([pin.lat, pin.lng]);
      marker.bindPopup(buildPopupFromPin(pin));
      marker.on('click', function () {
        if (window.lnkupOpenPinDrawer) {
          window.lnkupOpenPinDrawer(pin);
        }
      });
      markerGroup.addLayer(marker);
      bounds.push([pin.lat, pin.lng]);
    });
    if (bounds.length) {
      map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
    }
  }

  window.lnkupInitMap = function () {
    var el = document.getElementById('lnkup-map-view');
    if (!el || map) {
      if (map) {
        window.lnkupMapInvalidateSize();
        window.lnkupMapRefresh();
      }
      return;
    }

    loadStylesheet('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
    loadStylesheet('https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css');
    loadStylesheet('https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css');

    var chain = Promise.resolve();
    if (typeof L === 'undefined') {
      chain = chain.then(function () { return loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'); });
    }
    chain.then(function () {
      if (typeof L.markerClusterGroup === 'undefined') {
        return loadScript('https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js');
      }
    }).then(function () {
      clusterLoaded = true;
      initMap(el);
    });
  };

  function initMap(el) {
    var cfg = (window.lnkupAjax && window.lnkupAjax.mapProvider) || {};
    var center = cfg.defaultCenter || [35.2271, -80.8431];
    map = L.map(el).setView(center, cfg.defaultZoom || 5);
    L.tileLayer(cfg.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: cfg.attribution || '&copy; OpenStreetMap'
    }).addTo(map);
    markerGroup = clusterLoaded && L.markerClusterGroup
      ? L.markerClusterGroup({ maxClusterRadius: 50, disableClusteringAtZoom: 15 })
      : L.layerGroup();
    map.addLayer(markerGroup);
    window.lnkupMapInvalidateSize();
    window.lnkupMapRefresh();
  }

  window.lnkupMapInvalidateSize = function () {
    if (!map) {
      return;
    }
    requestAnimationFrame(function () {
      map.invalidateSize();
    });
  };

  window.lnkupMapRefresh = function (query) {
    if (!map || !markerGroup) {
      return;
    }
    fetchPins(query).then(function (pins) {
      if (pins.length) {
        pinCache = pins;
        renderPins(pins);
        return;
      }
      markerGroup.clearLayers();
      var bounds = [];
      document.querySelectorAll('.lnkup-event-card--v2').forEach(function (card) {
        var lat = parseFloat(card.getAttribute('data-lat'));
        var lng = parseFloat(card.getAttribute('data-lng'));
        if (!lat || !lng) {
          return;
        }
        var marker = L.marker([lat, lng]);
        marker.bindPopup(buildPopup(card));
        markerGroup.addLayer(marker);
        bounds.push([lat, lng]);
      });
      if (bounds.length) {
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
      }
    });
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.lnkup-map-pin-detail');
    if (btn && window.lnkupOpenPinDrawer) {
      try {
        window.lnkupOpenPinDrawer(JSON.parse(decodeURIComponent(btn.getAttribute('data-pin'))));
      } catch (err) { /* ignore */ }
    }
  });
})();
