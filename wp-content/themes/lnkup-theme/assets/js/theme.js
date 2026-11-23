document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('lnkup-mobile-nav-toggle');
  var nav = document.getElementById('lnkup-mobile-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      nav.hidden = open;
    });
  }

  function bindDismissibleBar(barId, btnId, storageKey) {
    var bar = document.getElementById(barId);
    var dismissBtn = document.getElementById(btnId);
    if (!bar || !dismissBtn) {
      return;
    }
    try {
      if (localStorage.getItem(storageKey) === '1') {
        bar.hidden = true;
      }
    } catch (e) {
      /* ignore */
    }
    dismissBtn.addEventListener('click', function () {
      bar.hidden = true;
      try {
        localStorage.setItem(storageKey, '1');
      } catch (err) {
        /* ignore */
      }
    });
  }

  bindDismissibleBar('lnkup-app-download-bar', 'lnkup-app-download-bar-dismiss', 'lnkup_app_download_bar_dismissed');

  var qrRoot = document.querySelector('.lnkup-app-qr');
  if (qrRoot && document.documentElement.classList.contains('lnkup-desktop')) {
    var qrButton = qrRoot.querySelector('.lnkup-app-download__link');
    var qrPanel = qrRoot.querySelector('.lnkup-app-qr__panel');
    if (qrButton && qrPanel) {
      function closeQrPanel() {
        qrButton.setAttribute('aria-expanded', 'false');
        qrPanel.hidden = true;
      }
      qrButton.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var open = qrButton.getAttribute('aria-expanded') === 'true';
        qrButton.setAttribute('aria-expanded', open ? 'false' : 'true');
        qrPanel.hidden = open;
      });
      document.addEventListener('click', function (event) {
        if (!qrRoot.contains(event.target)) {
          closeQrPanel();
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          closeQrPanel();
        }
      });
    }
  }

  var sheet = document.getElementById('lnkup-app-download-sheet');
  var root = document.documentElement;
  if (!sheet || !root.classList.contains('lnkup-ios') || document.getElementById('lnkup-checkout-modal')) {
    return;
  }

  var sheetKey = 'lnkup_app_download_sheet_at';
  var week = 7 * 24 * 60 * 60 * 1000;
  var dismissed = false;
  try {
    var stored = parseInt(localStorage.getItem(sheetKey) || '0', 10);
    dismissed = stored > 0 && Date.now() - stored < week;
  } catch (e) {
    dismissed = false;
  }

  function dismissSheet() {
    sheet.hidden = true;
    try {
      localStorage.setItem(sheetKey, String(Date.now()));
    } catch (err) {
      /* ignore */
    }
  }

  // The prompt never takes over a first page view or the homepage hero: it waits for the second
  // page in a visit, and it is a bar the visitor can ignore, not a dialog.
  var views = 0;
  try {
    views = parseInt(sessionStorage.getItem('lnkup_page_views') || '0', 10) + 1;
    sessionStorage.setItem('lnkup_page_views', String(views));
  } catch (e) {
    views = 1;
  }
  var stickyAction = document.querySelector('.lnkup-public-event-bar, .lnkup-mp-v2__compare-tray:not([hidden]), [data-sticky-action]');
  if (!dismissed && views >= 2 && !document.body.classList.contains('home') && !stickyAction) {
    sheet.hidden = false;
  }

  sheet.querySelectorAll('[data-app-sheet-dismiss]').forEach(function (el) {
    el.addEventListener('click', dismissSheet);
  });

  var download = sheet.querySelector('[data-app-sheet-download]');
  if (download) {
    download.addEventListener('click', function () {
      try {
        localStorage.setItem(sheetKey, String(Date.now()));
      } catch (err) {
        /* ignore */
      }
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !sheet.hidden) {
      dismissSheet();
    }
  });
});
