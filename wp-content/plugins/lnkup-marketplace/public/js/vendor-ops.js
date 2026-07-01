(function () {
  var root = document.getElementById('lnkup-vendor-ops');
  if (!root) {
    return;
  }

  var select = document.getElementById('lnkup-vendor-ops-event-select');
  if (select) {
    select.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('view', 'operations');
      url.searchParams.set('app', select.value);
      window.location.href = url.toString();
    });
  }

  root.querySelectorAll('.lnkup-vendor-ops__tab[data-ops-tab]').forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      if (tab.getAttribute('href') && tab.getAttribute('href').indexOf('tab=') > -1) {
        return;
      }
      e.preventDefault();
      var key = tab.getAttribute('data-ops-tab');
      root.querySelectorAll('.lnkup-vendor-ops__tab').forEach(function (t) {
        t.classList.remove('is-active');
      });
      root.querySelectorAll('.lnkup-vendor-ops__panel').forEach(function (p) {
        p.classList.remove('is-active');
        p.hidden = true;
      });
      tab.classList.add('is-active');
      var panel = root.querySelector('[data-ops-panel="' + key + '"]');
      if (panel) {
        panel.classList.add('is-active');
        panel.hidden = false;
      }
    });
  });
})();
