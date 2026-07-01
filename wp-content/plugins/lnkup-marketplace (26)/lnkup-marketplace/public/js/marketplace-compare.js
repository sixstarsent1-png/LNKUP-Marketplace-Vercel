(function () {
  var compare = [];

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.lnkup-compare-add');
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-opportunity-id'), 10);
    if (compare.indexOf(id) > -1) {
      compare = compare.filter(function (x) { return x !== id; });
      btn.classList.remove('is-active');
    } else {
      if (compare.length >= 4) { alert('Compare up to 4 opportunities.'); return; }
      compare.push(id);
      btn.classList.add('is-active');
    }
    updateTray();
  });

  function updateTray() {
    var tray = document.getElementById('lnkup-compare-tray');
    var inner = document.getElementById('lnkup-compare-inner');
    if (!tray || !inner) return;
    tray.hidden = compare.length === 0;
    inner.innerHTML = compare.map(function (id) {
      return '<span class="lnkup-compare-chip" data-id="' + id + '">#' + id + ' <button type="button" aria-label="Remove">&times;</button></span>';
    }).join('');
    inner.querySelectorAll('.lnkup-compare-chip button').forEach(function (removeBtn) {
      removeBtn.addEventListener('click', function () {
        var chip = removeBtn.closest('.lnkup-compare-chip');
        var rid = parseInt(chip.getAttribute('data-id'), 10);
        compare = compare.filter(function (x) { return x !== rid; });
        document.querySelectorAll('.lnkup-compare-add[data-opportunity-id="' + rid + '"]').forEach(function (b) {
          b.classList.remove('is-active');
        });
        updateTray();
      });
    });
  }

  function formatVal(key, val) {
    if (val === null || val === undefined || val === '') return '—';
    if (typeof val === 'boolean') return val ? 'Yes' : 'No';
    if (key === 'vendor_fee') return '$' + val;
    if (key === 'organizer_rating') return val + '/5';
    return val;
  }

  document.getElementById('lnkup-compare-open') && document.getElementById('lnkup-compare-open').addEventListener('click', function () {
    if (!compare.length) return;
    var cfg = window.lnkupAjax || {};
    fetch(cfg.restUrl + '/compare?ids=' + compare.join(','), {
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (r) { return r.json(); }).then(function (data) {
      var html = '<div class="lnkup-compare-table-wrap"><table class="lnkup-compare-table"><thead><tr><th></th>';
      data.items.forEach(function (item) { html += '<th>' + item.name + '</th>'; });
      html += '</tr></thead><tbody>';
      var rows = [
        ['Fee', 'vendor_fee'], ['Attendance', 'attendance'], ['Date', 'date'],
        ['City', 'city'], ['State', 'state'], ['Load-in', 'load_in_time'],
        ['Power', 'power_available'], ['Water', 'water_available'],
        ['Tent Allowed', 'tent_allowed'], ['Generator', 'generator_allowed'],
        ['Organizer Rating', 'organizer_rating'], ['Verified Organizer', 'organizer_verified']
      ];
      rows.forEach(function (row) {
        html += '<tr><td>' + row[0] + '</td>';
        data.items.forEach(function (item) {
          html += '<td>' + formatVal(row[1], item[row[1]]) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      var modal = document.createElement('div');
      modal.className = 'lnkup-compare-modal';
      modal.innerHTML = '<div class="lnkup-compare-modal__inner">' +
        '<div class="lnkup-compare-modal__header"><h3>Compare Opportunities</h3>' +
        '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-compare-close">Close</button></div>' +
        html + '</div>';
      document.body.appendChild(modal);
      document.body.classList.add('lnkup-compare-open');
      modal.querySelector('#lnkup-compare-close').addEventListener('click', function () {
        modal.remove();
        document.body.classList.remove('lnkup-compare-open');
      });
      modal.addEventListener('click', function (ev) {
        if (ev.target === modal) {
          modal.remove();
          document.body.classList.remove('lnkup-compare-open');
        }
      });
    });
  });
})();
