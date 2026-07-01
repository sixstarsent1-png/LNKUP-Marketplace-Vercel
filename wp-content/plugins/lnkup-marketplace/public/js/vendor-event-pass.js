(function () {
  var ajax = window.lnkupAjax || {};
  var packetsBase = ajax.packetsRestUrl || '';
  var nonce = ajax.restNonce || '';

  function packetsFetch(path, options) {
    options = options || {};
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    var url = path.indexOf('http') === 0 ? path : packetsBase + path;
    return fetch(url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function () {
            throw new Error('packets_api_error');
          });
        }
        return r.json();
      });
  }

  function initReadinessRings() {
    document.querySelectorAll('.lnkup-vendor-pass__readiness-ring').forEach(function (el) {
      var pct = parseInt(el.getAttribute('data-percent') || '0', 10);
      el.style.setProperty('--percent', String(Math.min(100, Math.max(0, pct))));
    });
  }

  function trackPassSections() {
    var opsFetch = window.lnkupOpsFetch;
    if (!opsFetch) {
      return;
    }
    document.querySelectorAll('.lnkup-vendor-pass:not(.lnkup-vendor-pass--locked) .lnkup-vendor-pass__section').forEach(function (section) {
      if (section.dataset.engaged) {
        return;
      }
      var root = section.closest('.lnkup-vendor-pass');
      var appId = root ? root.getAttribute('data-app-id') : '';
      if (!appId) {
        return;
      }
      section.dataset.engaged = '1';
      var key = ((section.querySelector('h3') || {}).textContent || 'section').slice(0, 40).toLowerCase().replace(/\s+/g, '_');
      opsFetch('/applications/' + appId + '/packet/engage', {
        method: 'POST',
        body: { section: key, action: 'viewed' }
      }).catch(function () {});
    });
  }

  function refreshPass(root) {
    var appId = root.getAttribute('data-app-id');
    if (!appId) {
      return;
    }
    packetsFetch('/pass/' + appId).then(function () {
      if (window.lnkupRenderQrCodes) {
        window.lnkupRenderQrCodes();
      }
      initReadinessRings();
    }).catch(function () {});
  }

  function initVendorPass() {
    document.querySelectorAll('.lnkup-vendor-pass').forEach(function (root) {
      initReadinessRings();
      refreshPass(root);
    });
    if (window.lnkupRenderQrCodes) {
      window.lnkupRenderQrCodes();
    }
    trackPassSections();
  }

  document.addEventListener('DOMContentLoaded', initVendorPass);
  document.addEventListener('lnkup-ops-panel-open', function (e) {
    if (e.detail && e.detail.tab === 'pass') {
      initVendorPass();
    }
  });
})();
