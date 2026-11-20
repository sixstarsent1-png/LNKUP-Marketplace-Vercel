(function () {
  'use strict';

  var config = window.lnkupAppPromo || {};
  var storageKey = 'lnkup_app_promo_dismissed_until';
  var snoozeMs = (parseInt(config.snoozeDays, 10) || 60) * 24 * 60 * 60 * 1000;

  function detectDevice() {
    var ua = navigator.userAgent || '';
    var iPadOS = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    if (/iPhone|iPad|iPod/.test(ua) || iPadOS) {
      return 'ios';
    }
    if (/Android/i.test(ua)) {
      return 'android';
    }
    return 'desktop';
  }

  function isSnoozedLocally() {
    try {
      return parseInt(localStorage.getItem(storageKey) || '0', 10) > Date.now();
    } catch (e) {
      return false;
    }
  }

  function snoozeLocally() {
    try {
      localStorage.setItem(storageKey, String(Date.now() + snoozeMs));
    } catch (e) {
      /* storage unavailable */
    }
  }

  function snoozeOnServer() {
    if (!config.loggedIn || !config.ajaxUrl || !config.nonce) {
      return;
    }
    var body = new FormData();
    body.append('action', 'lnkup_dismiss_app_promo');
    body.append('lnkup_nonce', config.nonce);
    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
      })
      .catch(function (error) {
        console.error('LNKup app promo: could not save dismissal', error);
      });
  }

  function bindOptOut() {
    document.querySelectorAll('[data-lnkup-app-promo-opt-out]').forEach(function (input) {
      var root = input.closest('.lnkup-app-promo-pref');
      var status = root ? root.querySelector('[data-lnkup-app-promo-opt-out-status]') : null;
      input.addEventListener('change', function () {
        if (!config.ajaxUrl || !config.nonce) {
          return;
        }
        var hide = input.checked;
        var body = new FormData();
        body.append('action', 'lnkup_app_promo_opt_out');
        body.append('lnkup_nonce', config.nonce);
        body.append('hide', hide ? '1' : '');
        input.disabled = true;
        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
          .then(function (response) {
            var type = response.headers.get('content-type') || '';
            if (!response.ok || type.indexOf('application/json') === -1) {
              throw new Error('HTTP ' + response.status);
            }
            return response.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error('Request failed');
            }
            if (status) {
              status.textContent = hide ? 'App suggestions hidden.' : 'App suggestions turned back on.';
            }
          })
          .catch(function (error) {
            console.error('LNKup app promo: could not save preference', error);
            input.checked = !hide;
            if (status) {
              status.textContent = 'We could not save that. Try again.';
            }
          })
          .then(function () {
            input.disabled = false;
          });
      });
    });
  }

  function init() {
    bindOptOut();
    var device = detectDevice();
    var promos = document.querySelectorAll('[data-lnkup-app-promo]');
    var hideForVisitor = !config.loggedIn && isSnoozedLocally();

    promos.forEach(function (promo) {
      promo.setAttribute('data-device', device);
      if (hideForVisitor) {
        promo.hidden = true;
      }
    });

    document.addEventListener('click', function (event) {
      var dismiss = event.target.closest('[data-lnkup-app-promo-dismiss]');
      if (!dismiss) {
        return;
      }
      event.preventDefault();
      document.querySelectorAll('[data-lnkup-app-promo]').forEach(function (promo) {
        promo.hidden = true;
      });
      snoozeLocally();
      snoozeOnServer();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
