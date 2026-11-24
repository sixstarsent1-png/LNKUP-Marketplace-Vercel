(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.lnkup-follow-tour[data-tour-id]');
    if (!btn) return;
    e.preventDefault();
    var cfg = window.lnkupAjax || {};
    if (!cfg.restUrl || !cfg.restNonce || btn.disabled) return;
    btn.disabled = true;
    fetch(cfg.restUrl + '/favorites', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.restNonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ type: 'tours', id: parseInt(btn.getAttribute('data-tour-id'), 10) })
    }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (!res.ok || type.indexOf('application/json') === -1) throw new Error('Request failed');
      return res.json();
    }).then(function (data) {
      var on = !!(data && data.favorited);
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.textContent = btn.getAttribute(on ? 'data-label-on' : 'data-label-off') || btn.textContent;
    }).catch(function () {
      btn.setAttribute('aria-invalid', 'true');
    }).finally(function () {
      btn.disabled = false;
    });
  });
})();
