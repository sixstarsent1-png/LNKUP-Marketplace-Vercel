(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.connectorRestUrl || '';
  var nonce = ajax.restNonce || '';
  var API_ERROR = 'We couldn\'t load this section. Try refreshing or contact support.';

  function safeFetch(path) {
    return fetch(base + path, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce }
    }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (!r.ok || ct.indexOf('application/json') === -1) {
        throw new Error('api_error');
      }
      return r.json();
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function currency(val) {
    var n = parseFloat(val) || 0;
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function copyText(text, btn) {
    if (!text) {
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (btn) {
          btn.classList.add('is-copied');
          btn.textContent = 'Copied';
          setTimeout(function () {
            btn.classList.remove('is-copied');
            btn.textContent = 'Copy link';
          }, 2000);
        }
      });
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  function renderKpis(stats) {
    stats = stats || {};
    return (
      '<div class="lnkup-connector-kpis">' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Vendor signups</span><strong>' + escapeHtml(stats.vendor_signups || 0) + '</strong></div>' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Organizer signups</span><strong>' + escapeHtml(stats.organizer_signups || 0) + '</strong></div>' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Profiles completed</span><strong>' + escapeHtml(stats.profiles_completed || 0) + '</strong></div>' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Vendor applications</span><strong>' + escapeHtml(stats.applications || 0) + '</strong></div>' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Paid bookings</span><strong>' + escapeHtml(stats.paid_bookings || 0) + '</strong></div>' +
      '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">Attributed revenue</span><strong>' + escapeHtml(currency(stats.attributed_revenue)) + '</strong></div>' +
      '</div>'
    );
  }

  function renderLinks(links) {
    links = links || {};
    var items = [
      { key: 'vendor', label: 'Vendor sign-up link' },
      { key: 'organizer', label: 'Organizer sign-up link' },
      { key: 'general', label: 'General sign-up link' },
      { key: 'city_page', label: 'City market page link' }
    ];
    var html = '<div class="lnkup-connector-links">';
    items.forEach(function (item) {
      var url = links[item.key];
      if (!url) {
        return;
      }
      html += '<div class="lnkup-connector-link-row">' +
        '<label class="lnkup-connector-link-row__label">' + escapeHtml(item.label) + '</label>' +
        '<div class="lnkup-connector-link-row__field">' +
        '<input type="text" readonly value="' + escapeHtml(url) + '" class="lnkup-connector-link-input" />' +
        '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-url="' + escapeHtml(url) + '">Copy link</button>' +
        '</div></div>';
    });
    if (links.code) {
      html += '<p class="lnkup-muted">Referral code: <strong>' + escapeHtml(links.code) + '</strong></p>';
    }
    html += '</div>';
    return html;
  }

  function renderTable(rows, emptyMsg) {
    if (!rows || !rows.length) {
      return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>' + escapeHtml(emptyMsg) + '</p></div>';
    }
    var html = '<div class="lnkup-table-wrap"><table class="lnkup-table"><thead><tr><th>Name</th><th>Email</th><th>Stage</th><th>Date</th></tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr><td>' + escapeHtml(row.name) + '</td><td>' + escapeHtml(row.email_masked) + '</td><td>' + escapeHtml(row.stage) + '</td><td>' + escapeHtml(row.signup_date) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderActivity(events) {
    if (!events || !events.length) {
      return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No activity yet.</p></div>';
    }
    var html = '<ul class="lnkup-connector-activity">';
    events.forEach(function (ev) {
      html += '<li><span class="lnkup-connector-activity__type">' + escapeHtml(ev.event_type) + '</span> <span class="lnkup-muted">' + escapeHtml(ev.created_at) + '</span></li>';
    });
    html += '</ul>';
    return html;
  }

  function renderRewards(rows) {
    if (!rows || !rows.length) {
      return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No rewards yet.</p></div>';
    }
    var html = '<div class="lnkup-table-wrap"><table class="lnkup-table"><thead><tr><th>Event</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr><td>' + escapeHtml(row.event_type) + '</td><td>' + escapeHtml(currency(row.amount)) + '</td><td>' + escapeHtml(row.reward_status) + '</td><td>' + escapeHtml(row.created_at) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function failPanel(panel, message) {
    if (!panel) {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelError(panel, {
        title: message || API_ERROR,
        onRetry: function () {
          panel.dataset.loaded = '';
          loadPanel(panel);
        }
      });
      return;
    }
    panel.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>' + escapeHtml(message || API_ERROR) + '</p></div>';
    panel.dataset.loaded = '1';
  }

  function loadPanel(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    var view = panel.getAttribute('data-view');
    panel.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card"></div>';
    var promise;
    if (view === 'home') {
      promise = safeFetch('/connector/dashboard').then(function (data) {
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderKpis(data.stats) + renderLinks(data.links) + '</div>';
      });
    } else if (view === 'links') {
      promise = safeFetch('/connector/links').then(function (links) {
        panel.innerHTML = renderLinks(links);
      });
    } else if (view === 'vendors' || view === 'organizers') {
      promise = safeFetch('/connector/referrals?role=' + view).then(function (data) {
        var rows = view === 'vendors' ? data.vendors : data.organizers;
        panel.innerHTML = renderTable(rows, 'No referrals yet.');
      });
    } else if (view === 'activity') {
      promise = safeFetch('/connector/referrals').then(function (data) {
        panel.innerHTML = renderActivity(data.activity);
      });
    } else if (view === 'rewards') {
      promise = safeFetch('/connector/rewards').then(function (rows) {
        panel.innerHTML = renderRewards(rows);
      });
    } else {
      panel.innerHTML = '';
      return;
    }
    promise.then(function () {
      panel.dataset.loaded = '1';
      panel.querySelectorAll('.lnkup-connector-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
          copyText(btn.getAttribute('data-url'), btn);
        });
      });
    }).catch(function () {
      failPanel(panel);
    });
  }

  function initInterestForm() {
    var form = document.querySelector('[data-testid="lnkup-connector-interest-form"]');
    if (!form) {
      return;
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = form.querySelector('.lnkup-connector-interest-status');
      var btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.disabled = true;
      }
      var payload = {};
      new FormData(form).forEach(function (val, key) {
        payload[key] = val;
      });
      var restBase = (ajax.restUrl || '').replace(/\/marketplace\/?$/, '') || '/wp-json/lnkup/v1';
      fetch(restBase + '/connector/interest', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.message || API_ERROR);
          }
          return body;
        });
      }).then(function () {
        if (status) {
          status.textContent = 'Thanks! We received your application and will be in touch.';
          status.classList.add('is-success');
        }
        form.reset();
      }).catch(function (err) {
        if (status) {
          status.textContent = err.message || API_ERROR;
          status.classList.add('is-error');
        }
      }).finally(function () {
        if (btn) {
          btn.disabled = false;
        }
      });
    });
  }

  function init() {
    var root = document.getElementById('lnkup-connector-dashboard');
    if (!root) {
      initInterestForm();
      return;
    }
    var view = root.getAttribute('data-view') || 'home';
    var panel = root.querySelector('[data-connector-panel]');
    if (panel) {
      panel.setAttribute('data-view', view);
      loadPanel(panel);
    }
    document.querySelectorAll('.lnkup-connector-nav a').forEach(function (link) {
      link.addEventListener('click', function () {
        document.querySelectorAll('.lnkup-connector-nav a').forEach(function (a) { a.classList.remove('is-active'); });
        link.classList.add('is-active');
      });
    });
    initInterestForm();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
