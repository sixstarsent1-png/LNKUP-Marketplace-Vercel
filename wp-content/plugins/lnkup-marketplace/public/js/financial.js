(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.financialRestUrl || '';
  var nonce = ajax.restNonce || '';

  var API_ERROR_MSG = 'Could not load financial data.';

  function t(key, fallback) {
    if (window.LNKupUI && LNKupUI.t) {
      return LNKupUI.t(key, fallback);
    }
    var copy = window.lnkupUiCopy || {};
    return copy[key] || fallback || key;
  }

  function getFinancialRole() {
    var vendorShell = document.getElementById('lnkup-vendor-financial');
    if (vendorShell && vendorShell.getAttribute('data-role') === 'vendor') {
      return 'vendor';
    }
    var orgShell = document.getElementById('lnkup-organizer-financial');
    if (orgShell && orgShell.getAttribute('data-role') === 'organizer') {
      return 'organizer';
    }
    return '';
  }

  function appendRoleParam(path) {
    var role = getFinancialRole();
    if (!role) {
      return path;
    }
    return path + (path.indexOf('?') >= 0 ? '&' : '?') + 'role=' + encodeURIComponent(role);
  }

  function taxPdfLinks(data) {
    var w9 = (data && data.w9_pdf_url) || (base + '/tax-center/w9/pdf');
    var nec = (data && data.form_1099_pdf_url) || (base + '/tax-center/1099/pdf');
    function withNonce(url) {
      if (!nonce || url.indexOf('_wpnonce=') >= 0) {
        return url;
      }
      return url + (url.indexOf('?') >= 0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(nonce);
    }
    return '<p class="lnkup-fin-tax-pdfs">' +
      '<a class="lnkup-btn lnkup-btn--sm" href="' + withNonce(w9) + '" target="_blank" rel="noopener">Download W-9 summary PDF</a> ' +
      '<a class="lnkup-btn lnkup-btn--sm" href="' + withNonce(nec) + '" target="_blank" rel="noopener">Download 1099 summary PDF</a>' +
      '</p><p class="lnkup-muted">LNKup summaries for your records — not official IRS forms.</p>';
  }

  var ORGANIZER_DASH_ZERO = {
    gross_revenue: 0,
    available_balance: 0,
    pending_balance: 0,
    wallet_pending: 0,
    platform_fees: 0,
    payouts_paid: 0,
    refunds: 0,
    wallet: { available: 0, pending: 0, processing: 0, reserve_hold: 0 },
    charts: { revenue_by_month: [] }
  };

  function resolveOrganizerBalances(payload) {
    payload = payload || {};
    var display = payload.display_balances || {};
    var wallet = payload.wallet || payload.balances || {};
    var connect = payload.connect || {};
    var stripeBal = payload.stripe_balance || wallet.stripe_balance || {};
    var settlement = display.settlement_mode || wallet.settlement_mode || connect.settlement_mode || 'platform_treasury';
    var isDest = settlement === 'destination';
    var ledgerSettled = Number(display.connect_settlement || wallet.connect_settlement || 0);
    var stripeSynced = display.stripe_synced != null ? !!display.stripe_synced : !!stripeBal.synced;
    var balanceSource = display.balance_source || (isDest && stripeSynced ? 'stripe_connect' : 'ledger');

    var available = Number(display.available != null ? display.available : (wallet.available || payload.available_balance || 0));
    var pending = Number(display.pending != null ? display.pending : (wallet.pending || payload.pending_balance || payload.wallet_pending || 0));
    var processing = Number(display.processing != null ? display.processing : (wallet.processing || payload.processing_balance || 0));
    var reserve = Number(display.reserve_hold != null ? display.reserve_hold : (wallet.reserve_hold || payload.reserve_hold || 0));
    var sourceTypes = display.source_types || stripeBal.source_types || {};
    var connectAvailable = Number(display.connect_available != null ? display.connect_available : (stripeBal.available || 0));
    var platformRecoverable = Number(display.platform_recoverable != null ? display.platform_recoverable : 0);
    var ledgerClaim = Number(display.ledger_claim != null ? display.ledger_claim : (wallet.available || 0));

    if (isDest && stripeSynced) {
      // Prefer server display_balances (Connect + ledger-capped platform recovery).
      if (display.available == null) {
        available = Number(stripeBal.available != null ? stripeBal.available : available) + platformRecoverable;
      }
      if (display.pending == null) {
        pending = Number(stripeBal.pending != null ? stripeBal.pending : pending);
      }
      if (display.connect_available == null && stripeBal.available != null) {
        connectAvailable = Number(stripeBal.available);
      }
      if (platformRecoverable > 0) {
        balanceSource = 'stripe_connect_plus_recovery';
      } else {
        balanceSource = display.balance_source || 'stripe_connect';
      }
      if (stripeBal.source_types) {
        sourceTypes = display.source_types || stripeBal.source_types;
      }
    }

    return {
      available: available,
      pending: pending,
      processing: processing,
      reserve: reserve,
      settlement: settlement,
      isDest: isDest,
      ledgerSettled: ledgerSettled,
      ledgerClaim: ledgerClaim,
      connectAvailable: connectAvailable,
      platformRecoverable: platformRecoverable,
      stripeBal: stripeBal,
      stripeSynced: stripeSynced,
      balanceSource: balanceSource,
      sourceTypes: sourceTypes
    };
  }

  function formatSourceTypesBreakdown(sourceTypes) {
    if (!sourceTypes || typeof sourceTypes !== 'object') {
      return '';
    }
    var labels = { card: 'Card', bank_account: 'Bank', fpx: 'FPX' };
    var keys = Object.keys(sourceTypes);
    if (keys.length < 2) {
      return '';
    }
    return keys.map(function (key) {
      var cents = Number(sourceTypes[key]) || 0;
      var label = labels[key] || String(key).replace(/_/g, ' ');
      return label + ' $' + (cents / 100).toFixed(2);
    }).join(' · ');
  }

  function mergeOrganizerMoneyPayload(dash, walletData) {
    dash = dash || {};
    if (!walletData) {
      return dash;
    }
    dash.wallet = walletData.wallet || walletData.balances || dash.wallet || {};
    dash.balances = walletData.balances || walletData.wallet || dash.balances || {};
    dash.stripe_balance = walletData.stripe_balance || dash.stripe_balance || {};
    dash.display_balances = walletData.display_balances || dash.display_balances || {};
    if (!dash.connect && walletData.connect) {
      dash.connect = walletData.connect;
    }
    return dash;
  }

  function fetchOrganizerMoneyBalances() {
    return fetchFinancialJson('/organizer-balances').then(function (res) {
      if (res.ok) {
        return res;
      }
      return fetchFinancialJson('/organizer-wallet');
    });
  }

  function refreshOrganizerBalanceHero() {
    return fetchOrganizerMoneyBalances().then(function (balanceRes) {
      return fetchFinancialJson('/dashboard').then(function (dashRes) {
        var dash = dashRes.ok ? (dashRes.data || {}) : {};
        if (balanceRes.ok) {
          dash = mergeOrganizerMoneyPayload(dash, balanceRes.data || {});
        } else if (!dashRes.ok) {
          dash = {};
        }
        loadOrganizerBalanceHero(dash);
        return dash;
      });
    });
  }

  /**
   * Financial REST fetch with JSON safety checks.
   *
   * @param {string} path   Relative path under financial REST base.
   * @param {object} options Fetch options.
   * @return {Promise<{ok:boolean,data?:*,error?:string,status?:number}>}
   */
  function looksLikeJsonBody(body) {
    var trimmed = String(body || '').replace(/^\uFEFF/, '').trim();
    return trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[';
  }

  function resolveFinancialErrorMessage(parsed, status) {
    if (parsed && typeof parsed.message === 'string' && parsed.message.trim()) {
      return parsed.message.trim();
    }
    if (parsed && parsed.data && typeof parsed.data.message === 'string' && parsed.data.message.trim()) {
      return parsed.data.message.trim();
    }
    if (parsed && typeof parsed.code === 'string' && parsed.code.trim()) {
      return parsed.code.trim();
    }
    if (status) {
      return 'Request failed (HTTP ' + status + ')';
    }
    return API_ERROR_MSG;
  }

  function fetchFinancialJson(path, options) {
    options = options || {};
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + appendRoleParam(path), Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        return r.text().then(function (body) {
          var parsed = null;
          var shouldParse = body && (ct.indexOf('application/json') !== -1 || looksLikeJsonBody(body));
          if (shouldParse) {
            try {
              parsed = JSON.parse(body);
            } catch (parseErr) {
              console.error('Financial API JSON parse error', path, body.slice(0, 300));
            }
          }
          if (!r.ok) {
            console.error('Financial API error', r.status, (body || '').slice(0, 300));
            var msg = resolveFinancialErrorMessage(parsed, r.status);
            return { ok: false, error: msg, code: parsed && parsed.code, data: parsed, status: r.status };
          }
          if (!parsed) {
            console.error('Financial API JSON parse error', path, (body || '').slice(0, 300));
            return { ok: false, error: API_ERROR_MSG, status: r.status };
          }
          return { ok: true, data: parsed };
        });
      })
      .catch(function (err) {
        console.error('Financial API network error', path, err);
        return { ok: false, error: API_ERROR_MSG, status: 0 };
      });
  }

  function finFetch(path, options) {
    return fetchFinancialJson(path, options).then(function (result) {
      if (!result.ok) {
        var err = new Error(result.error || resolveFinancialErrorMessage(result.data, result.status));
        err.code = result.code;
        err.status = result.status;
        err.payload = result.data || null;
        throw err;
      }
      return result.data;
    });
  }

  function renderErrorCard(message) {
    if (window.LNKupUI) {
      return '<div class="lnkup-alert lnkup-alert--error lnkup-panel-state--error"><p class="lnkup-empty-state__title">' +
        escapeHtml(message || API_ERROR_MSG) + '</p></div>';
    }
    return '<div class="lnkup-fin-error-card"><p>' + escapeHtml(message || API_ERROR_MSG) + '</p></div>';
  }

  function failPanel(panel, message) {
    if (!panel) {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelError(panel, {
        title: message || API_ERROR_MSG,
        onRetry: function () {
          panel.dataset.loaded = '';
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        }
      });
      return;
    }
    panel.innerHTML = renderErrorCard(message);
    panel.dataset.loaded = '1';
  }

  function settledValue(result, fallback) {
    return result && result.status === 'fulfilled' ? result.value : fallback;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function statusChip(status) {
    return '<span class="lnkup-fin-status lnkup-fin-status--' + escapeHtml(status) + '">' + escapeHtml(String(status).replace(/_/g, ' ')) + '</span>';
  }

  function kpiGrid(items) {
    var html = '<div class="lnkup-fin-kpi-grid">';
    items.forEach(function (item) {
      html += '<div class="lnkup-fin-kpi"><p class="lnkup-fin-kpi__label">' + escapeHtml(item.label) + '</p><p class="lnkup-fin-kpi__value">' + escapeHtml(item.value) + '</p></div>';
    });
    html += '</div>';
    return html;
  }

  function renderInvoiceList(rows) {
    var appsUrl = '/vendor-dashboard/?view=applications';
    if (!rows || !rows.length) {
      if (window.LNKupUI) {
        var mount = document.createElement('div');
        LNKupUI.panelEmpty(mount, {
          title: LNKupUI.t('empty_invoices', 'No invoices yet'),
          desc: LNKupUI.t('empty_invoices_desc', 'Invoices appear when you are approved for events.'),
          ctaLabel: LNKupUI.t('view_applications', 'View applications'),
          ctaHref: appsUrl
        });
        return mount.innerHTML;
      }
      return '<p class="lnkup-fin-empty">No invoices yet.</p>';
    }
    var html = '<div class="lnkup-fin-invoice-cards">';
    rows.forEach(function (r) {
      var due = (r.due_date || '').slice(0, 10);
      var pdf = r.pdf_url || '';
      var viewLink = pdf
        ? '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' + escapeHtml(pdf) + '" target="_blank" rel="noopener" data-testid="lnkup-invoice-view">View</a>'
        : '';
      html += '<article class="lnkup-fin-invoice-card lnkup-surface--primary lnkup-micro-lift">' +
        '<div class="lnkup-fin-invoice-card__main">' +
        '<p class="lnkup-type-label">' + escapeHtml(r.invoice_number || 'Invoice') + '</p>' +
        '<h3 class="lnkup-type-h2">' + escapeHtml(r.event_title || '') + '</h3>' +
        '<p class="lnkup-type-meta">' + (due ? ('Due ' + escapeHtml(due)) : '') + '</p>' +
        '</div>' +
        '<div class="lnkup-fin-invoice-card__side">' +
        '<p class="lnkup-type-display">$' + Number(r.total_amount || 0).toFixed(2) + '</p>' +
        statusChip(r.status) +
        viewLink +
        '</div></article>';
    });
    html += '</div>';
    html += '<div class="lnkup-fin-table-wrap lnkup-fin-table-wrap--desktop-only"><table class="lnkup-fin-table"><thead><tr>' +
      '<th>Invoice</th><th>Event</th><th>Due</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var pdf = r.pdf_url || '';
      var viewCell = pdf
        ? '<a href="' + escapeHtml(pdf) + '" target="_blank" rel="noopener" data-testid="lnkup-invoice-view">View</a>'
        : '';
      html += '<tr class="lnkup-fin-row-hover"><td>' + escapeHtml(r.invoice_number) + '</td><td>' + escapeHtml(r.event_title) +
        '</td><td>' + escapeHtml((r.due_date || '').slice(0, 10)) + '</td><td>$' + Number(r.total_amount).toFixed(2) + '</td><td>' + statusChip(r.status) + '</td><td>' + viewCell + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function tableFromRows(headers, rows, emptyOpts) {
    if (!rows.length && emptyOpts && window.LNKupUI) {
      var mount = document.createElement('div');
      LNKupUI.panelEmpty(mount, emptyOpts);
      return mount.innerHTML;
    }
    var html = '<div class="lnkup-fin-table-wrap"><table class="lnkup-fin-table"><thead><tr>';
    headers.forEach(function (h) { html += '<th>' + escapeHtml(h) + '</th>'; });
    html += '</tr></thead><tbody>';
    if (!rows.length) {
      html += '<tr><td colspan="' + headers.length + '"><div class="lnkup-fin-empty-inline">' + escapeHtml((emptyOpts && emptyOpts.title) || 'No records yet.') + '</div></td></tr>';
    }
    rows.forEach(function (row) {
      html += '<tr>';
      row.forEach(function (cell) { html += '<td>' + cell + '</td>'; });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function bindSectionNav(shell) {
    if (!shell || shell.dataset.finSectionsBound) {
      return;
    }
    var sections = shell.querySelectorAll('.lnkup-fin-section');
    if (!sections.length) {
      return;
    }
    shell.dataset.finSectionsBound = '1';

    function showSection(sectionName, activateFirst) {
      sections.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-section') === sectionName);
      });
      shell.querySelectorAll('.lnkup-fin-tab[data-section]').forEach(function (tab) {
        var visible = tab.getAttribute('data-section') === sectionName;
        tab.classList.toggle('is-section-visible', visible);
      });
      if (activateFirst) {
        var firstTab = shell.querySelector('.lnkup-fin-tab[data-section="' + sectionName + '"].is-section-visible');
        if (firstTab) {
          firstTab.click();
        }
      }
    }

    sections.forEach(function (btn) {
      btn.addEventListener('click', function () {
        showSection(btn.getAttribute('data-section'), true);
      });
    });

    var activeTab = shell.querySelector('.lnkup-fin-tab.is-active[data-section]');
    showSection((activeTab && activeTab.getAttribute('data-section')) || 'money', false);
  }

  function bindTabs(shell) {
    if (!shell || shell.dataset.finTabsBound) {
      return;
    }
    shell.dataset.finTabsBound = '1';
    bindSectionNav(shell);
    shell.querySelectorAll('.lnkup-fin-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab');
        shell.querySelectorAll('.lnkup-fin-tab').forEach(function (t) {
          t.classList.remove('is-active');
          t.setAttribute('aria-selected', 'false');
        });
        shell.querySelectorAll('.lnkup-fin-panel').forEach(function (p) { p.classList.remove('is-active'); });
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');
        var panel = shell.querySelector('[data-panel="' + name + '"]');
        if (panel) {
          panel.classList.add('is-active');
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        }
      });
    });
  }

  function chartBars(items, valueKey, labelKey) {
    if (window.LNKupUI && window.LNKupUI.renderBarChart) {
      return window.LNKupUI.renderBarChart(items, {
        valueKey: valueKey || 'revenue',
        labelKey: labelKey || 'month',
        emptyLabel: 'No chart data yet.',
        ariaLabel: 'Financial chart'
      });
    }
    if (!items || !items.length) {
      return '<p class="lnkup-fin-empty">No chart data yet.</p>';
    }
    var max = Math.max.apply(null, items.map(function (i) { return Number(i[valueKey] || 0); })) || 1;
    var html = '<div class="lnkup-fin-chart">';
    items.forEach(function (item) {
      var h = Math.max(4, Math.round((Number(item[valueKey] || 0) / max) * 100));
      html += '<div class="lnkup-fin-chart__bar" style="height:' + h + '%" title="' + escapeHtml(item[labelKey] || '') + ': ' + Number(item[valueKey] || 0).toFixed(2) + '"></div>';
    });
    html += '</div>';
    return html;
  }

  function activityList(items) {
    if (!items || !items.length) {
      return '<p class="lnkup-fin-empty">No activity yet.</p>';
    }
    var html = '<ul class="lnkup-fin-activity">';
    items.forEach(function (item) {
      html += '<li><span>' + escapeHtml(item.message) + '</span><span class="lnkup-muted">' + escapeHtml((item.created_at || '').slice(0, 16)) + '</span></li>';
    });
    html += '</ul>';
    return html;
  }

  function refundRequestForm() {
    return '<form class="lnkup-fin-refund-form" id="lnkup-refund-form">' +
      '<h3>Request refund</h3>' +
      '<p class="lnkup-muted">Contested charges: use the Disputes tab instead.</p>' +
      '<label>Payment ID <input type="number" name="payment_id" required></label>' +
      '<label>Amount <input type="number" step="0.01" name="amount" required></label>' +
      '<label>Type <select name="refund_type"><option value="full">Full</option><option value="partial">Partial</option><option value="credit">Marketplace credit</option></select></label>' +
      '<label>Reason <textarea name="reason" rows="3"></textarea></label>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Submit request</button>' +
      '</form>';
  }

  function bindRefundForm(panel) {
    var form = panel.querySelector('#lnkup-refund-form');
    if (!form || form.dataset.bound) {
      return;
    }
    form.dataset.bound = '1';
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      finFetch('/refunds', {
        method: 'POST',
        body: {
          payment_id: Number(fd.get('payment_id')),
          amount: Number(fd.get('amount')),
          refund_type: fd.get('refund_type'),
          reason: fd.get('reason')
        }
      }).then(function (res) {
        if (res.id) {
          panel.dataset.loaded = '';
          panel.innerHTML = '<p>Refund request submitted.</p>';
        } else {
          alert(res.message || 'Could not submit refund');
        }
      });
    });
  }

  function disputeRequestForm(prefillPaymentId) {
    var pid = prefillPaymentId ? String(prefillPaymentId) : '';
    return '<form class="lnkup-fin-dispute-form" id="lnkup-dispute-form">' +
      '<h3>Dispute a charge</h3>' +
      '<p class="lnkup-muted">Open a case for a paid charge. The organizer reviews and may refund or issue credit.</p>' +
      '<label>Payment ID <input type="number" name="payment_id" required value="' + escapeHtml(pid) + '"></label>' +
      '<label>Reason <textarea name="reason" rows="4" minlength="10" required placeholder="Why is this charge incorrect?"></textarea></label>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Open dispute</button>' +
      '</form>';
  }

  function bindDisputeForm(panel) {
    var form = panel.querySelector('#lnkup-dispute-form');
    if (!form || form.dataset.bound) {
      return;
    }
    form.dataset.bound = '1';
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      finFetch('/disputes', {
        method: 'POST',
        body: {
          payment_id: Number(fd.get('payment_id') || 0),
          reason: String(fd.get('reason') || '')
        }
      }).then(function (res) {
        if (res && (res.code || (!res.id && !res.dispute))) {
          alert(res.message || 'Could not open dispute');
          return;
        }
        panel.dataset.loaded = '';
        panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
      }).catch(function () {
        alert('Could not open dispute');
      });
    });
  }

  function renderDisputeEvidence(list) {
    if (!list || !list.length) {
      return '<p class="lnkup-muted">No evidence notes yet.</p>';
    }
    var html = '<ul class="lnkup-fin-dispute-evidence">';
    list.forEach(function (item) {
      html += '<li><span class="lnkup-muted">' + escapeHtml((item.at || '').slice(0, 16)) + '</span> — ' + escapeHtml(item.message || '') + '</li>';
    });
    html += '</ul>';
    return html;
  }

  function bindVendorDisputeRespond(panel) {
    panel.querySelectorAll('.lnkup-dispute-respond').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        var note = window.prompt('Add a note for the organizer');
        if (!note) return;
        finFetch('/disputes/' + id + '/respond', { method: 'POST', body: { evidence: note } }).then(function (res) {
          if (res && res.code) {
            alert(res.message || 'Could not add note');
            return;
          }
          panel.dataset.loaded = '';
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        });
      });
    });
  }

  function bindOrganizerDisputeResolve(panel) {
    panel.querySelectorAll('[data-dispute-resolve]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        var outcome = btn.getAttribute('data-dispute-resolve');
        var note = '';
        if (outcome === 'denied') {
          note = window.prompt('Denial note (required)') || '';
          if (!note) return;
        } else {
          note = window.prompt('Optional note for the vendor') || '';
        }
        finFetch('/disputes/' + id + '/resolve', {
          method: 'POST',
          body: { outcome: outcome, note: note }
        }).then(function (res) {
          if (res && res.code) {
            alert(res.message || 'Could not resolve dispute');
            return;
          }
          panel.dataset.loaded = '';
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        });
      });
    });
    panel.querySelectorAll('.lnkup-dispute-respond').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        var note = window.prompt('Add evidence or a reply');
        if (!note) return;
        finFetch('/disputes/' + id + '/respond', { method: 'POST', body: { evidence: note } }).then(function () {
          panel.dataset.loaded = '';
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        });
      });
    });
  }

  function loadVendorOverview(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    Promise.allSettled([
      finFetch('/dashboard'),
      finFetch('/transactions?limit=10'),
      finFetch('/activity?limit=8'),
      finFetch('/reports/vendor')
    ]).then(function (results) {
      var dash = settledValue(results[0], {});
      var txns = settledValue(results[1], []);
      var activity = settledValue(results[2], []);
      var report = settledValue(results[3], {});
      if (results[0].status !== 'fulfilled' && results[1].status !== 'fulfilled' && results[3].status !== 'fulfilled') {
        failPanel(panel, 'Could not load financial overview.');
        return;
      }
      panel.dataset.loaded = '1';
      loadBalanceHero(dash, report);
      var openInvoices = Number(report.open_invoices || dash.open_invoices || 0);
      var dueSoon = Number(report.due_soon || dash.due_soon || 0);
      var credits = Number(report.credits_available || dash.credits_available || dash.wallet_balance || 0);
      var refunds = Number(report.refunds || dash.refunds || 0);
      var paid = Number(report.lifetime_spend || dash.lifetime_spend || dash.revenue_paid || 0);
      var html = '<div class="lnkup-fin-tab-panel">';
      html += kpiGrid([
        { label: 'Total spent', value: '$' + paid.toFixed(2) },
        { label: 'Open Invoices', value: String(openInvoices) },
        { label: 'Due Soon', value: String(dueSoon) },
        { label: 'Credits Available', value: '$' + credits.toFixed(2) },
        { label: 'Refunds', value: '$' + refunds.toFixed(2) }
      ]);
      html += '<h3>Recent transactions</h3>';
      html += vendorTransactionCards(txns);
      html += '<div class="lnkup-fin-txn-table-desktop">';
      html += tableFromRows(['Event', 'Amount', 'Status', 'Date', ''], (txns || []).map(function (t) {
        var action = '';
        if (t.status === 'pending' && t.id) {
          action = '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-pay-btn" data-payment-id="' + (t.payment_post_id || t.id) + '">Pay now</button>';
        } else if ((t.status === 'paid' || t.status === 'completed') && (t.payment_post_id || t.id)) {
          action = '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-dispute-charge-btn" data-payment-id="' + (t.payment_post_id || t.id) + '">Dispute charge</button>';
        }
        return [
          escapeHtml(t.event_title || t.public_id),
          escapeHtml('$' + Number(t.amount || 0).toFixed(2)),
          statusChip(t.status),
          escapeHtml((t.created_at || '').slice(0, 10)),
          action
        ];
      }));
      html += '</div>';
      html += '<h3>Activity</h3>' + activityList(activity);
      if (!txns.length && !activity.length && paid <= 0) {
        html += '<p class="lnkup-fin-empty">Payment activity will appear here after you apply and pay for events.</p>';
      }
      panel.innerHTML = html;
    }).catch(function () {
      failPanel(panel, 'Could not load financial overview.');
    });
  }

  function vendorTransactionCards(txns) {
    if (!txns || !txns.length) {
      return '<div class="lnkup-fin-txn-cards"><p class="lnkup-muted">No transactions yet.</p></div>';
    }
    var html = '<div class="lnkup-fin-txn-cards">';
    txns.forEach(function (t) {
      html += '<article class="lnkup-fin-txn-card">';
      html += '<div class="lnkup-fin-txn-card__head"><strong>' + escapeHtml(t.event_title || t.public_id) + '</strong>' + statusChip(t.status) + '</div>';
      html += '<p class="lnkup-fin-txn-card__amount">$' + Number(t.amount || 0).toFixed(2) + '</p>';
      html += '<p class="lnkup-muted">' + escapeHtml((t.created_at || '').slice(0, 10)) + '</p>';
      if (t.status === 'pending' && t.payment_post_id) {
        html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-pay-btn" data-payment-id="' + t.payment_post_id + '">Pay now</button>';
      }
      if ((t.status === 'paid' || t.status === 'completed') && t.payment_post_id) {
        html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-dispute-charge-btn" data-payment-id="' + t.payment_post_id + '">Dispute charge</button>';
      }
      html += '</article>';
    });
    html += '</div>';
    return html;
  }

  function loadBalanceHero(dash, report) {
    var hero = document.getElementById('lnkup-fin-balance-hero');
    if (!hero) {
      return;
    }
    if (document.querySelector('#payments .lnkup-executive-hero')) {
      hero.hidden = true;
      hero.setAttribute('aria-hidden', 'true');
      return;
    }
    dash = dash || {};
    report = report || {};
    var outstanding = Number(report.pending_payments || dash.outstanding || dash.pending_payments || 0);
    var paid = Number(report.lifetime_spend || dash.lifetime_spend || dash.revenue_paid || 0);
    var wallet = Number(report.credits_available || dash.credits_available || dash.wallet_balance || 0);
    finFetch('/installments').then(function (rows) {
      var nextDue = rows && rows.length ? rows[0].due_date : '—';
      hero.innerHTML = '<div class="lnkup-fin-balance-hero__grid lnkup-fin-balance-hero__grid--vendor">' +
        '<div class="lnkup-fin-balance-hero__primary"><p class="lnkup-fin-balance-hero__label">Amount due</p><p class="lnkup-fin-balance-hero__value">$' + outstanding.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Total spent</p><p class="lnkup-fin-balance-hero__value">$' + paid.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Credits</p><p class="lnkup-fin-balance-hero__value">$' + wallet.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Next installment</p><p class="lnkup-fin-balance-hero__value">' + escapeHtml(String(nextDue).slice(0, 10)) + '</p></div>' +
        '</div>';
    }).catch(function () {
      hero.innerHTML = '<div class="lnkup-fin-balance-hero__grid lnkup-fin-balance-hero__grid--vendor">' +
        '<div class="lnkup-fin-balance-hero__primary"><p class="lnkup-fin-balance-hero__label">Amount due</p><p class="lnkup-fin-balance-hero__value">$' + outstanding.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Total spent</p><p class="lnkup-fin-balance-hero__value">$' + paid.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Credits</p><p class="lnkup-fin-balance-hero__value">$' + wallet.toFixed(2) + '</p></div>' +
        '<div><p class="lnkup-fin-balance-hero__label">Next installment</p><p class="lnkup-fin-balance-hero__value">—</p></div>' +
        '</div>';
    });
  }

  function loadVendorSettings(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    Promise.all([
      finFetch('/payment-methods'),
      finFetch('/installments'),
      finFetch('/tax-profile')
    ]).then(function (results) {
      var methods = results[0] || [];
      var installments = results[1] || [];
      var tax = results[2] || {};
      panel.dataset.loaded = '1';
      var html = '<section id="lnkup-fin-settings-methods"><h3>Payment methods</h3>';
      html += '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-add-card">Add card</button>';
      html += tableFromRows(['Type', 'Brand', 'Last4', 'Expires', ''], methods.map(function (r) {
        return [escapeHtml(r.method_type), escapeHtml(r.brand), escapeHtml(r.last4), escapeHtml((r.exp_month || '') + '/' + (r.exp_year || '')), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-remove-card" data-id="' + r.id + '">Remove</button>'];
      }));
      html += '</section><section id="lnkup-fin-settings-installments"><h3>Installments</h3>';
      html += tableFromRows(['Due', 'Amount', 'Sequence'], installments.map(function (r) {
        return [escapeHtml(r.due_date), '$' + Number(r.amount).toFixed(2), String(r.sequence)];
      }));
      html += '</section><section id="lnkup-fin-settings-tax"><h3>Tax documents</h3>';
      html += '<p>W-9 on file: ' + (tax && tax.w9_on_file ? 'Yes' : 'No') + '</p>';
      html += '<p>1099 eligible: ' + (tax && tax.form_1099_eligible ? 'Yes' : 'No') + '</p>';
      html += taxPdfLinks(tax) + '</section>';
      panel.innerHTML = html;
      var addCard = panel.querySelector('#lnkup-add-card');
      if (addCard) {
        addCard.addEventListener('click', function () {
          finFetch('/payment-methods/setup', { method: 'POST', body: {} }).then(function (setup) {
            if (setup.client_secret && window.Stripe && ajax.stripePublicKey) {
              alert('Complete card setup in checkout — saved cards sync after setup.');
            }
          }).catch(function () {
            alert('Could not start card setup.');
          });
        });
      }
    }).catch(function () {
      failPanel(panel, 'Could not load payment settings.');
    });
  }

  function infoCard(title, body, modifier) {
    var cls = 'lnkup-fin-info-card' + (modifier ? ' lnkup-fin-info-card--' + modifier : '');
    return '<div class="' + cls + '"><h4 class="lnkup-fin-info-card__title">' + escapeHtml(title) + '</h4><div class="lnkup-fin-info-card__body">' + body + '</div></div>';
  }

  function loadList(panel, path, formatter) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    fetchFinancialJson(path).then(function (result) {
      panel.dataset.loaded = '1';
      if (!result.ok) {
        failPanel(panel, result.error);
        return;
      }
      try {
        panel.innerHTML = formatter(result.data || []);
      } catch (err) {
        console.error('Financial panel render error', path, err);
        failPanel(panel);
      }
    }).catch(function () {
      panel.dataset.loaded = '1';
      failPanel(panel);
    });
  }

  function loadOrganizerBalanceHero(dash) {
    var hero = document.getElementById('lnkup-fin-org-balance-hero');
    if (!hero) {
      return;
    }
    if (document.querySelector('#financial .lnkup-executive-hero')) {
      hero.hidden = true;
      return;
    }
    var balances = resolveOrganizerBalances(dash || {});
    var connect = (dash && dash.connect) || {};
    var awaitingCheckout = Number((dash && dash.pending_revenue) || (dash && dash.outstanding) || 0);
    var primaryLabel = balances.isDest && balances.stripeSynced ? t('available_balance', 'Available balance') : t('available_balance', 'Available');
    var pendingLabel = balances.isDest && balances.stripeSynced ? t('funds_processing', 'Funds processing') : t('funds_processing', 'Pending');

    var html = '<div class="lnkup-fin-balance-hero__grid">';
    html += '<div class="lnkup-fin-balance-hero__primary"><p class="lnkup-fin-balance-hero__label">' + escapeHtml(primaryLabel) + '</p>';
    var primaryAmt = balances.available;
    if (balances.isDest && !balances.stripeSynced && primaryAmt <= 0) {
      primaryAmt = balances.ledgerClaim || balances.ledgerSettled || 0;
    }
    html += '<p class="lnkup-fin-balance-hero__value">$' + primaryAmt.toFixed(2) + '</p>';
    if (balances.isDest && balances.stripeSynced) {
      html += '<p class="lnkup-fin-balance-hero__sub">Settled in LNKup: $' + balances.ledgerSettled.toFixed(2) + '</p>';
      if (balances.pending > 0 && balances.available <= 0) {
        html += '<p class="lnkup-fin-balance-hero__sub">Card payments can take a few days to become available for payout.</p>';
      }
    } else if (balances.isDest && !balances.stripeSynced && (primaryAmt > 0 || balances.ledgerSettled > 0)) {
      html += '<p class="lnkup-fin-balance-hero__sub">Tracked in LNKup</p>';
    } else if (awaitingCheckout > 0) {
      html += '<p class="lnkup-fin-balance-hero__sub">$' + awaitingCheckout.toFixed(2) + ' awaiting vendor checkout completion.</p>';
    }
    html += '</div>';
    html += '<div><p class="lnkup-fin-balance-hero__label">' + escapeHtml(pendingLabel) + '</p><p class="lnkup-fin-balance-hero__value">$' + balances.pending.toFixed(2) + '</p></div>';
    html += '<div><p class="lnkup-fin-balance-hero__label">Processing</p><p class="lnkup-fin-balance-hero__value">$' + balances.processing.toFixed(2) + '</p></div>';
    html += '<div><p class="lnkup-fin-balance-hero__label">Reserve hold</p><p class="lnkup-fin-balance-hero__value">$' + balances.reserve.toFixed(2) + '</p></div>';
    html += '<div><p class="lnkup-fin-balance-hero__label">Payouts</p><p class="lnkup-fin-balance-hero__value">' + payoutBadgeChip(connect) + '</p></div>';
    if (balances.isDest) {
      html += '<p class="lnkup-fin-balance-hero__note">Vendor payments settle to your payout account. LNKup tracks settlement for cashout and reporting.</p>';
    }
    html += '</div>';
    hero.innerHTML = html;
  }

  function paymentAuditBreakdown(audit) {
    if (!audit) {
      return '';
    }
    var rows = [
      ['Booth fee (stored)', '$' + Number(audit.booth_fee_stored || 0).toFixed(2)],
      ['Booth fee (opportunity)', '$' + Number(audit.booth_fee_opportunity || 0).toFixed(2)],
      ['Vendor checkout fee', '$' + Number(audit.vendor_checkout_fee || 0).toFixed(2)],
      ['Vendor checkout fee (current settings)', '$' + Number(audit.vendor_checkout_fee_current != null ? audit.vendor_checkout_fee_current : audit.vendor_checkout_fee || 0).toFixed(2)],
      ['Organizer commission', '$' + Number(audit.organizer_commission || 0).toFixed(2)],
      ['Platform fee total', '$' + Number(audit.platform_fee || 0).toFixed(2)],
      ['Processing fee', '$' + Number(audit.processing_fee || 0).toFixed(2)],
      ['Organizer net', '$' + Number(audit.organizer_net || 0).toFixed(2)],
      ['Total charged', '$' + Number(audit.total_charged || 0).toFixed(2)],
      ['Fee payer', escapeHtml(audit.fee_payer_mode || 'vendor')],
      ['Settlement mode', escapeHtml(audit.settlement_mode || '—')],
      ['Payment reference', escapeHtml(audit.stripe_payment_intent || '—')],
      ['Payment status', escapeHtml(audit.stripe_intent_status || '—')],
      ['Application fee (cents)', String(audit.application_fee_cents || 0)],
      ['Destination gross', '$' + Number(audit.destination_amount || 0).toFixed(2)],
      ['Ledger settled', audit.ledger_connect_settlement ? 'Yes' : 'No']
    ];
    if (audit.booth_fee_mismatch) {
      rows.unshift(['⚠ Booth mismatch', 'Payment meta differs from opportunity fee']);
    }
    if (audit.vendor_checkout_fee_mismatch) {
      rows.unshift(['⚠ Checkout fee mismatch', 'Stored fee differs from current settings (pending payment will refresh at checkout)']);
    }
    return tableFromRows(['Field', 'Value'], rows);
  }

  function vendorRevenueTable(rows) {
    var html = '<div class="lnkup-fin-table-wrap"><table class="lnkup-fin-table lnkup-fin-table--revenue"><thead><tr>';
    html += '<th></th><th>Event</th><th>Amount</th><th>Status</th><th>Date</th><th></th>';
    html += '</tr></thead><tbody>';
    if (!rows.length) {
      html += '<tr><td colspan="6">No records yet.</td></tr>';
    }
    rows.forEach(function (t, idx) {
      var audit = t.audit || {};
      var pid = t.payment_post_id || 0;
      html += '<tr class="lnkup-fin-revenue-row" data-revenue-idx="' + idx + '">';
      html += '<td><button type="button" class="lnkup-fin-expand" data-revenue-idx="' + idx + '" aria-expanded="false">+</button></td>';
      html += '<td>' + escapeHtml(t.event_title || t.public_id) + '</td>';
      html += '<td>$' + Number(t.amount || 0).toFixed(2) + '</td>';
      html += '<td>' + statusChip(t.status) + '</td>';
      html += '<td>' + escapeHtml((t.created_at || '').slice(0, 10)) + '</td>';
      html += '<td>' + (pid && audit.can_reconcile
        ? '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-fin-reconcile" data-payment-id="' + pid + '">' + (audit.payment_status === 'paid' ? 'Reconcile' : 'Sync payment') + '</button>'
        : '') + '</td>';
      html += '</tr>';
      html += '<tr class="lnkup-fin-revenue-detail" data-revenue-detail="' + idx + '" hidden><td colspan="6">' + paymentAuditBreakdown(audit) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function bindVendorRevenuePanel(panel) {
    if (!panel) {
      return;
    }
    panel.querySelectorAll('.lnkup-fin-expand').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = btn.getAttribute('data-revenue-idx');
        var detail = panel.querySelector('[data-revenue-detail="' + idx + '"]');
        if (!detail) {
          return;
        }
        var open = detail.hidden;
        detail.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? '−' : '+';
      });
    });
    panel.querySelectorAll('.lnkup-fin-reconcile').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var paymentId = btn.getAttribute('data-payment-id');
        btn.disabled = true;
        finFetch('/payments/' + paymentId + '/reconcile', { method: 'POST', body: {} }).then(function (res) {
          if (res && res.error) {
            btn.disabled = false;
            alert(res.error);
            return;
          }
          btn.textContent = (res && res.action) ? res.action.replace(/_/g, ' ') : 'Done';
          panel.dataset.loaded = '';
          var overview = document.getElementById('lnkup-fin-org-overview');
          if (overview) {
            overview.dataset.loaded = '';
          }
          refreshOrganizerBalanceHero();
          panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
        }).catch(function (err) {
          btn.disabled = false;
          alert((err && err.message) || 'Could not reconcile payment.');
        });
      });
    });
  }

  function payoutTimeline(status) {
    var steps = ['requested', 'processing', 'paid'];
    var idx = steps.indexOf(status);
    if (status === 'completed') {
      idx = 2;
    }
    if (status === 'cancelled' || status === 'failed') {
      return '<p class="lnkup-muted">Payout ' + escapeHtml(status) + '</p>';
    }
    var html = '<div class="lnkup-fin-payout-timeline">';
    steps.forEach(function (step, i) {
      var cls = i < idx ? 'is-done' : (i === idx ? 'is-current' : '');
      html += '<span class="lnkup-fin-payout-step ' + cls + '">' + escapeHtml(step) + '</span>';
      if (i < steps.length - 1) {
        html += '<span class="lnkup-fin-payout-step__line"></span>';
      }
    });
    html += '</div>';
    return html;
  }

  function loadOrganizerOverview(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    Promise.all([
      fetchOrganizerMoneyBalances(),
      fetchFinancialJson('/dashboard'),
      fetchFinancialJson('/activity?limit=8'),
      fetchFinancialJson('/connect/status')
    ]).then(function (results) {
      var balanceRes = results[0];
      var dashRes = results[1];
      var actRes = results[2];
      var connRes = results[3];
      if (!balanceRes.ok && !dashRes.ok && !actRes.ok && !connRes.ok) {
        failPanel(panel, 'Could not load financial overview.');
        loadOrganizerBalanceHero({});
        return;
      }
      var dash = dashRes.ok ? (dashRes.data || {}) : Object.assign({}, ORGANIZER_DASH_ZERO);
      if (balanceRes.ok) {
        dash = mergeOrganizerMoneyPayload(dash, balanceRes.data || {});
      }
      var activity = actRes.ok ? (actRes.data || []) : [];
      var connect = connRes.ok ? (connRes.data || {}) : (dash.connect || {});
      if (!dash.connect) {
        dash.connect = connect;
      }
      panel.dataset.loaded = '1';
      loadOrganizerBalanceHero(dash);
      renderConnectTopBanner(connect);
      var balances = resolveOrganizerBalances(dash);
      var collectedAmt = Number(dash.collected || dash.vendor_revenue || 0);
      var wallet = dash.wallet || {};
      var grossRaw = Number(dash.gross_revenue || 0);
      var gross = (grossRaw > 0 ? grossRaw : collectedAmt) || Number(dash.vendor_revenue || wallet.lifetime_paid || 0);
      var hasRevenue = gross > 0 || collectedAmt > 0;
      var html = '<div class="lnkup-fin-tab-panel">';
      if (!dashRes.ok && balanceRes.ok) {
        html += infoCard('Revenue summary', '<p class="lnkup-muted">Revenue charts could not load. Your tracked balance is still shown.</p><p><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-fin-retry-overview>Retry</button></p>', 'warn');
      }
      html += kpiGrid([
        { label: 'Gross Revenue', value: '$' + gross.toFixed(2) },
        { label: 'Available Balance', value: '$' + balances.available.toFixed(2) },
        { label: 'Pending Balance', value: '$' + balances.pending.toFixed(2) },
        { label: 'Platform Fees', value: '$' + Number(dash.platform_fees || 0).toFixed(2) },
        { label: 'Payouts Paid', value: '$' + Number(dash.payouts_paid || 0).toFixed(2) },
        { label: 'Refunds', value: '$' + Number(dash.refunds || 0).toFixed(2) }
      ]);
      if (balances.stripeSynced) {
        html += infoCard(t('account_balance', 'Account balance'), '<p>Available: <strong>$' + balances.available.toFixed(2) + '</strong> · Pending: <strong>$' + balances.pending.toFixed(2) + '</strong></p>', 'success');
      }
      html += '<div class="lnkup-fin-overview-cards">';
      html += payoutStatusCard(connect);
      var cashoutReady = connect.status === 'active' && connect.payouts_enabled;
      var cashoutBody = cashoutReady
        ? '<p class="lnkup-fin-cashout-ready">You can request a cashout from your available balance.</p>'
        : '<p class="lnkup-muted">Complete payout setup to cash out vendor revenue.</p>';
      html += infoCard('Cashout readiness', cashoutBody, cashoutReady ? 'success' : '');
      html += infoCard('Recent financial activity', activityList(activity));
      html += '</div>';
      if (!hasRevenue && (!activity || !activity.length)) {
        html += '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__desc">' + escapeHtml((window.lnkupUiCopy && window.lnkupUiCopy.empty_financial) || 'No financial activity yet. Payments will appear here once vendors start booking.') + '</p></div>';
      }
      html += '<div class="lnkup-fin-chart-shell" data-testid="lnkup-fin-chart-shell">';
      var monthRows = dash.charts && dash.charts.revenue_by_month ? dash.charts.revenue_by_month : [];
      if (monthRows.length) {
        html += '<h3>Revenue by month</h3>' + chartBars(monthRows, 'revenue', 'month');
      } else {
        var chartMsg = (balances.stripeSynced || balances.available > 0 || balances.pending > 0)
          ? 'No chart data yet'
          : 'Balances syncing with Stripe';
        html += infoCard(
          'Revenue by month',
          '<p class="lnkup-muted">' + escapeHtml(chartMsg) + '</p><p><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-fin-retry-overview>Retry</button></p>',
          'warn'
        );
      }
      html += '</div>';
      html += '</div>';
      panel.innerHTML = html;
      panel.querySelectorAll('[data-fin-retry-overview]').forEach(function (retryBtn) {
        retryBtn.addEventListener('click', function () {
          panel.dataset.loaded = '';
          loadOrganizerOverview(panel);
        });
      });
      if (window.LNKupUI && typeof LNKupUI.animateKpiCards === 'function') {
        LNKupUI.animateKpiCards(panel);
      }
    }).catch(function () {
      failPanel(panel, 'Could not load financial overview.');
      loadOrganizerBalanceHero({});
    });
  }

  function loadOrganizerForecast(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    fetchFinancialJson('/forecast?days=30').then(function (result) {
      panel.dataset.loaded = '1';
      if (!result.ok) {
        failPanel(panel, 'Could not load financial data.');
        return;
      }
      var data = result.data || {};
      var html = '<div class="lnkup-fin-tab-panel">';
      html += '<p class="lnkup-muted">Heuristic forecast based on outstanding invoices and recent payment velocity.</p>';
      html += kpiGrid([
        { label: 'Next 7 Days', value: '$' + Number(data.next_7_days || 0).toFixed(2) },
        { label: 'Next 30 Days', value: '$' + Number(data.next_30_days || 0).toFixed(2) },
        { label: 'Projected Available Cash', value: '$' + Number(data.projected_available_cash || data.projected_payout || 0).toFixed(2) },
        { label: 'Expected Payouts', value: '$' + Number(data.expected_payouts || 0).toFixed(2) }
      ]);
      if (data.outstanding_invoices != null) {
        html += '<p class="lnkup-muted">Outstanding invoices counted: <strong>' + Number(data.outstanding_invoices || 0) + '</strong></p>';
      }
      if (data.confidence === 'low' || (!data.next_7_days && !data.next_30_days)) {
        html += '<p class="lnkup-fin-empty">' + escapeHtml(data.message || 'Forecasts will appear after vendor invoices and payments are created.') + '</p>';
      } else if (data.message) {
        html += '<p class="lnkup-muted">' + escapeHtml(data.message) + '</p>';
      }
      if (data.high_risk_invoices && data.high_risk_invoices.length) {
        html += '<h3>High-risk invoices</h3>';
        html += tableFromRows(['Invoice', 'Amount', 'Due', 'Status'], data.high_risk_invoices.map(function (r) {
          return ['#' + r.id, '$' + Number(r.amount).toFixed(2), escapeHtml(r.due_date || ''), statusChip(r.status)];
        }));
      }
      html += '</div>';
      panel.innerHTML = html;
    });
  }

  function loadOrganizerSettings(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    fetchFinancialJson('/connect/status').then(function (result) {
      panel.dataset.loaded = '1';
      if (!result.ok) {
        failPanel(panel, 'Could not load payout settings.');
        return;
      }
      var connect = result.data || {};
      var settlement = connect.settlement_mode || 'platform_treasury';
      var html = '<div class="lnkup-fin-tab-panel lnkup-fin-settings">';
      html += '<p class="lnkup-fin-payout-badge lnkup-fin-payout-badge--pending">Settlement: ' + escapeHtml(settlement.replace(/_/g, ' ')) + '</p>';
      html += payoutStatusCard(connect);
      if (connect.available) {
        html += '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-connect-reconnect">' +
          (connect.status === 'active' ? 'Update payout account' : 'Finish payout setup') + '</button>';
      } else if (connect.message) {
        html += '<p class="lnkup-muted">' + escapeHtml(connect.message) + '</p>';
      }
      html += '</div>';
      panel.innerHTML = html;
      var reconnect = panel.querySelector('#lnkup-connect-reconnect');
      if (reconnect) {
        reconnect.addEventListener('click', function () {
          finFetch('/connect/onboarding-link', { method: 'POST', body: {} }).then(function (res) {
            if (res && res.success && res.url) {
              window.location.href = res.url;
              return;
            }
            alert(t('payout_status_setup', 'Finish payout setup'));
          }).catch(function () {
            alert(t('payout_status_setup', 'Finish payout setup'));
          });
        });
      }
    });
  }

  function loadOrganizerBudget(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    fetchFinancialJson('/reports/organizer').then(function (reportRes) {
      if (!reportRes.ok) {
        panel.dataset.loaded = '1';
        failPanel(panel, 'Could not load budget data.');
        return;
      }
      var events = (reportRes.data && (reportRes.data.revenue_by_event || reportRes.data.by_event)) || [];
      if (!events.length) {
        panel.dataset.loaded = '1';
        panel.innerHTML = '<div class="lnkup-fin-tab-panel"><p class="lnkup-fin-empty">Create an event to start budgeting.</p></div>';
        return;
      }
      var eventId = events[0].event_id;
      fetchFinancialJson('/events/' + eventId + '/budget').then(function (budgetRes) {
        panel.dataset.loaded = '1';
        if (!budgetRes.ok || !budgetRes.data) {
          panel.innerHTML = '<div class="lnkup-fin-tab-panel"><p class="lnkup-fin-empty">No budget set for this event yet.</p></div>';
          return;
        }
        var b = budgetRes.data;
        var html = '<div class="lnkup-fin-tab-panel">';
        html += '<p class="lnkup-muted">Event: <strong>' + escapeHtml(events[0].event_title || ('#' + eventId)) + '</strong></p>';
        html += kpiGrid([
          { label: 'Planned', value: '$' + Number(b.planned_total || 0).toFixed(2) },
          { label: 'Actual', value: '$' + Number(b.actual_total || 0).toFixed(2) },
          { label: 'Revenue', value: '$' + Number(b.revenue || 0).toFixed(2) },
          { label: 'Profit', value: '$' + Number(b.profit || 0).toFixed(2) }
        ]);
        if (b.variance != null) {
          html += '<p>Variance: <strong>$' + Number(b.variance).toFixed(2) + '</strong></p>';
        }
        html += '</div>';
        panel.innerHTML = html;
      });
    });
  }

  function payoutStatusCopy(connect) {
    connect = connect || {};
    var status = String(connect.status || 'not_started');
    if (status === 'active' && connect.payouts_enabled) {
      return {
        title: 'Payout Setup',
        message: 'Your payout account is active. Vendor payments can be routed to your organizer balance.',
        badge: t('payout_status_active', 'Payouts active'),
        badgeMod: 'success',
        secondary: 'Settlement method: Direct organizer payouts'
      };
    }
    if (status === 'pending' || status === 'pending_verification') {
      return {
        title: 'Payout Setup',
        message: 'We are reviewing your payout setup.',
        badge: 'Under Review',
        badgeMod: 'pending',
        secondary: ''
      };
    }
    if (status === 'restricted' || status === 'requirements_due') {
      return {
        title: 'Payout Setup',
        message: 'We need more information before payouts can be enabled.',
        badge: 'Verification Needed',
        badgeMod: 'warn',
        secondary: ''
      };
    }
    if (status === 'disabled') {
      return {
        title: 'Payout Setup',
        message: 'Payouts are temporarily disabled.',
        badge: 'Disabled',
        badgeMod: 'muted',
        secondary: ''
      };
    }
    return {
      title: 'Payout Setup',
      message: 'Finish payout setup to cash out vendor revenue.',
      badge: 'Action Required',
      badgeMod: 'warn',
      secondary: ''
    };
  }

  function payoutBadgeChip(connect) {
    var copy = payoutStatusCopy(connect || {});
    return '<span class="lnkup-fin-payout-badge lnkup-fin-payout-badge--' + escapeHtml(copy.badgeMod) + '">' + escapeHtml(copy.badge) + '</span>';
  }

  function payoutStatusCard(connect) {
    var copy = payoutStatusCopy(connect);
    var html = '<div class="lnkup-fin-payout-card lnkup-fin-payout-card--' + escapeHtml(copy.badgeMod) + '">';
    html += '<div class="lnkup-fin-payout-card__header"><h4 class="lnkup-fin-info-card__title">' + escapeHtml(copy.title) + '</h4>';
    html += payoutBadgeChip(connect) + '</div>';
    html += '<p>' + escapeHtml(copy.message) + '</p>';
    if (copy.secondary) {
      html += '<p class="lnkup-muted lnkup-fin-payout-card__secondary">' + escapeHtml(copy.secondary) + '</p>';
    }
    html += connectRequirementsList(connect);
    html += '</div>';
    return html;
  }

  function connectRequirementsList(connect) {
    var due = connect.requirements_past_due || connect.requirements_due || [];
    if (!due.length) {
      return '';
    }
    var html = '<ul class="lnkup-fin-connect-reqs">';
    due.forEach(function (item) {
      html += '<li>' + escapeHtml(String(item)) + '</li>';
    });
    html += '</ul>';
    return html;
  }

  function renderConnectTopBanner(connect) {
    var el = document.getElementById('lnkup-fin-connect-banner');
    if (!el) {
      return;
    }
    connect = connect || {};
    if (!connect.available && connect.message) {
      el.innerHTML = '<div class="lnkup-fin-connect-banner lnkup-fin-connect-banner--info">' + escapeHtml(connect.message) + '</div>';
      return;
    }
    if (connect.status === 'active' && connect.payouts_enabled) {
      el.innerHTML = '<div class="lnkup-fin-connect-banner lnkup-fin-connect-banner--success">' + payoutStatusCard(connect) + '</div>';
      return;
    }
    if (connect.status === 'active') {
      el.innerHTML = '<div class="lnkup-fin-connect-banner lnkup-fin-connect-banner--warn">' + payoutStatusCard(connect) + '</div>';
      return;
    }
    if (connect.available && connect.status !== 'active') {
      var html = '<div class="lnkup-fin-connect-banner lnkup-fin-connect-banner--warn">' +
        payoutStatusCard(connect) +
        '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-connect-onboard-top">Finish payout setup</button></div>';
      el.innerHTML = html;
      var topBtn = document.getElementById('lnkup-connect-onboard-top');
      if (topBtn) {
        topBtn.addEventListener('click', function () {
          finFetch('/connect/onboarding-link', { method: 'POST', body: {} }).then(function (res) {
            if (res && res.success && res.url) {
              window.location.href = res.url;
              return;
            }
            alert(t('payout_status_setup', 'Finish payout setup'));
          }).catch(function () {
            alert(t('payout_status_setup', 'Finish payout setup'));
          });
        });
      }
      return;
    }
    el.innerHTML = '';
  }

  function loadOrganizerConnectBanner() {
    finFetch('/connect/status').then(function (connect) {
      renderConnectTopBanner(connect || {});
    }).catch(function () {
      renderConnectTopBanner({ message: 'Could not load payout status.' });
    });
  }

  function handleConnectReturnQuery() {
    var params = new URLSearchParams(window.location.search);
    var connect = params.get('connect');
    if (!connect || (connect !== 'return' && connect !== 'refresh')) {
      return;
    }
    finFetch('/connect/refresh', { method: 'POST', body: {} }).then(function () {
      loadOrganizerConnectBanner();
      var shell = document.getElementById('lnkup-organizer-financial');
      if (shell) {
        var banner = document.getElementById('lnkup-fin-connect-banner');
        if (banner) {
          banner.insertAdjacentHTML('afterbegin', '<p class="lnkup-fin-connect-toast">Payout account synced.</p>');
        }
      }
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('connect');
        window.history.replaceState({}, '', url.pathname + url.hash);
      }
    }).catch(function () {});
  }

  function bindConnectOnboardButton(connect) {
    var btn = document.getElementById('lnkup-connect-onboard');
    if (!btn || btn.dataset.bound) {
      return;
    }
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      var notice = document.getElementById('lnkup-connect-notice');
      finFetch('/connect/onboarding-link', { method: 'POST', body: {} }).then(function (res) {
        if (res && res.success && res.url) {
          window.location.href = res.url;
          return;
        }
        var msg = (res && res.message) || 'Payout setup is not complete yet. Payments are still tracked in your dashboard.';
        if (notice) {
          notice.textContent = msg;
          notice.hidden = false;
        } else {
          alert(msg);
        }
      }).catch(function () {
        var fallback = 'Payout setup is not complete yet. Payments are still tracked in your dashboard.';
        if (notice) {
          notice.textContent = fallback;
          notice.hidden = false;
        } else {
          alert(fallback);
        }
      });
    });
  }

  function connectWalletBanner(connect) {
    connect = connect || {};
    if (connect.available !== false && connect.status === 'active') {
      return '';
    }
    if (connect.available) {
      return '';
    }
    var msg = connect.message || 'Payout setup is not complete yet. Payments are still tracked in your dashboard.';
    return '<p class="lnkup-fin-connect-banner lnkup-muted" id="lnkup-connect-notice">' + escapeHtml(msg) + '</p>';
  }

  function formatCashoutErrorHtml(err) {
    var payload = (err && err.payload) || {};
    var message = (err && err.message) || resolveFinancialErrorMessage(payload, err && err.status) || 'Cashout could not be completed.';
    var code = (err && err.code) || payload.code || '';
    var status = (err && err.status) || (payload.data && payload.data.status) || '';
    var meta = [];
    if (code) {
      meta.push(String(code));
    }
    if (status) {
      meta.push(String(status));
    }
    var html = renderErrorCard(message);
    if (meta.length) {
      html = html.replace(
        '</p></div>',
        '</p><p class="lnkup-muted">' + escapeHtml(meta.join(' · ')) + '</p></div>'
      );
    }
    return html;
  }

  function showCashoutInlineError(panel, err) {
    if (!panel) {
      return;
    }
    console.error('Cashout failed', err, err && err.payload);
    var existing = panel.querySelector('.lnkup-cashout-inline-error');
    if (existing) {
      existing.remove();
    }
    var wrap = document.createElement('div');
    wrap.className = 'lnkup-cashout-inline-error';
    wrap.innerHTML = formatCashoutErrorHtml(err);
    var form = panel.querySelector('#lnkup-cashout-form');
    if (form && form.parentNode) {
      form.parentNode.insertBefore(wrap, form);
    } else {
      panel.insertBefore(wrap, panel.firstChild);
    }
  }

  function loadCashoutPanel(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    finFetch('/organizer-wallet').then(function (data) {
      panel.dataset.loaded = '1';
      var balances = resolveOrganizerBalances(data || {});
      var connect = data.connect || {};
      var maxCashout = Math.max(0, Number(balances.available) || 0);
      var setupReady = connect.status === 'active' || balances.settlement === 'platform_treasury';
      var settling = balances.isDest && balances.stripeSynced && maxCashout <= 0 && Number(balances.pending) > 0;
      var balanceBlocked = balances.isDest && !balances.stripeSynced;
      var canCashout = setupReady && maxCashout > 0 && !balanceBlocked;
      var instantEnabled = !!(connect.instant_payouts_enabled || (ajax.settings && ajax.settings.connect_instant_payouts_enabled));
      var html = '';
      if (!setupReady && connect.available) {
        html += '<p class="lnkup-fin-connect-banner">Finish payout setup before cashing out.</p>';
      }
      if (balanceBlocked) {
        html += '<p class="lnkup-fin-connect-banner">Could not load your live Stripe payout balance. Refresh and try again.</p>';
      } else if (settling) {
        html += '<p class="lnkup-muted">Card payments can take a few days to become available for payout.</p>';
      } else if (balances.isDest && balances.stripeSynced && Number(balances.platformRecoverable) > 0) {
        html += '<p class="lnkup-muted">Includes $' + Number(balances.platformRecoverable).toFixed(2) + ' held on LNKup that will move to your payout account when you cash out.</p>';
      } else if (balances.isDest && balances.stripeSynced && maxCashout <= 0) {
        html += '<p class="lnkup-muted">Available is your Connect payout balance. Main Stripe may hold LNKup fees plus other organizers\' funds — not withdrawable here.</p>';
      } else if (balances.isDest && balances.stripeSynced) {
        html += '<p class="lnkup-muted">Max cashout uses your live available balance in destination mode.</p>';
      }
      var typeSelect = '<option value="standard">Standard</option>';
      if (instantEnabled) {
        typeSelect += '<option value="instant">Instant (1.5% fee)</option>';
      }
      html += '<form class="lnkup-fin-cashout-form" id="lnkup-cashout-form">' +
        '<p>Available: <strong>$' + maxCashout.toFixed(2) + '</strong></p>';
      var sourceBreakdown = formatSourceTypesBreakdown(balances.sourceTypes);
      if (sourceBreakdown) {
        html += '<p class="lnkup-muted">' + escapeHtml(sourceBreakdown) + '</p>';
      }
      if (instantEnabled) {
        var stripeBal = balances.stripeBal || (data && data.stripe_balance) || {};
        var instantAvail = stripeBal.instant_available;
        if (instantAvail == null && data && data.display_balances) {
          instantAvail = data.display_balances.instant_available;
        }
        if (instantAvail != null && instantAvail !== '') {
          html += '<p class="lnkup-muted">Instant available: $' + Number(instantAvail).toFixed(2) + '</p>';
        }
      }
      html += '<label>Amount <input type="number" step="0.01" name="amount" required min="0.01" max="' + maxCashout + '"' + (canCashout ? '' : ' disabled') + '></label>' +
        '<label>Type <select name="type"' + (canCashout ? '' : ' disabled') + '>' + typeSelect + '</select></label>' +
        '<button type="submit" class="lnkup-btn lnkup-btn--primary"' + (canCashout ? '' : ' disabled') + '>Request cashout</button></form>';
      if (!instantEnabled) {
        html += '<p class="lnkup-muted">Standard payouts only. Instant payouts are not enabled for this marketplace.</p>';
      }
      html += '<p class="lnkup-muted"><a href="#" class="lnkup-fin-goto-payouts">View payout history →</a></p>';
      panel.innerHTML = html;
      var payoutsLink = panel.querySelector('.lnkup-fin-goto-payouts');
      if (payoutsLink) {
        payoutsLink.addEventListener('click', function (e) {
          e.preventDefault();
          var shell = document.getElementById('lnkup-organizer-financial');
          var payoutsTab = shell && shell.querySelector('[data-tab="payouts"]');
          if (payoutsTab) {
            payoutsTab.click();
          }
        });
      }
      var form = panel.querySelector('#lnkup-cashout-form');
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!canCashout) {
          return;
        }
        var fd = new FormData(form);
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
        }
        finFetch('/organizer-wallet/cashout', { method: 'POST', body: { amount: Number(fd.get('amount')), type: fd.get('type') } }).then(function (res) {
          if (res && res.payout_id) {
            panel.dataset.loaded = '';
            panel.innerHTML = '<p>Cashout requested. Payout #' + res.payout_id + '</p>';
            return;
          }
          showCashoutInlineError(panel, {
            message: (res && (res.message || res.code)) || 'Cashout could not be completed.',
            code: res && res.code,
            status: res && res.status,
            payload: res || null
          });
          if (submitBtn) {
            submitBtn.disabled = false;
          }
        }).catch(function (err) {
          showCashoutInlineError(panel, err || { message: 'Cashout could not be completed.' });
          if (submitBtn) {
            submitBtn.disabled = false;
          }
        });
      });
    }).catch(function () {
      failPanel(panel, 'Could not load cashout options.');
    });
  }

  function loadEventFinancials(el) {
    if (!el || el.dataset.loaded) {
      return;
    }
    var eventId = el.getAttribute('data-event-id');
    if (!eventId) {
      return;
    }
    el.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    finFetch('/events/' + eventId + '/financials').then(function (data) {
      el.dataset.loaded = '1';
      var html = kpiGrid([
        { label: 'Collected', value: '$' + Number(data.collected_revenue || 0).toFixed(2) },
        { label: 'Pending', value: '$' + Number(data.pending_revenue || 0).toFixed(2) },
        { label: 'Refunds', value: '$' + Number(data.refunds || 0).toFixed(2) },
        { label: 'Completion', value: String(data.payment_completion || 0) + '%' }
      ]);
      html += '<h3>Financial timeline</h3><ul class="lnkup-fin-timeline">';
      (data.timeline || []).forEach(function (t) {
        html += '<li>' + escapeHtml(t.event_title || t.type) + ' — $' + Number(t.amount || 0).toFixed(2) + ' ' + statusChip(t.status) + '</li>';
      });
      html += '</ul>';
      el.innerHTML = html;
    }).catch(function () {
      el.innerHTML = '<p class="lnkup-muted">Could not load event financials.</p>';
    });
  }

  function initVendor() {
    var shell = document.getElementById('lnkup-vendor-financial');
    if (!shell) {
      return;
    }
    bindTabs(shell);
    loadVendorOverview(document.getElementById('lnkup-fin-vendor-overview'));
    shell.addEventListener('click', function (e) {
      var disputeBtn = e.target.closest('.lnkup-dispute-charge-btn');
      if (!disputeBtn) {
        return;
      }
      e.preventDefault();
      var pid = disputeBtn.getAttribute('data-payment-id') || '';
      try {
        sessionStorage.setItem('lnkup_dispute_payment', pid);
      } catch (err) { /* ignore */ }
      var walletSection = shell.querySelector('.lnkup-fin-section[data-section="wallet"]');
      if (walletSection) {
        walletSection.click();
      }
      var disputesTab = shell.querySelector('.lnkup-fin-tab[data-tab="disputes"]');
      if (disputesTab) {
        disputesTab.click();
      }
    });
    (function openDisputesFromQuery() {
      var params = new URLSearchParams(window.location.search);
      if (params.get('fin_tab') !== 'disputes' && !params.get('dispute_payment')) {
        return;
      }
      var walletSection = shell.querySelector('.lnkup-fin-section[data-section="wallet"]');
      if (walletSection) {
        walletSection.click();
      }
      var disputesTab = shell.querySelector('.lnkup-fin-tab[data-tab="disputes"]');
      if (disputesTab) {
        setTimeout(function () { disputesTab.click(); }, 0);
      }
    })();
    shell.addEventListener('lnkup-fin-panel-open', function (e) {
      var panel = e.target;
      if (!panel || !panel.id) {
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-refunds') {
        panel.dataset.loaded = '';
        panel.innerHTML = refundRequestForm() + '<div id="lnkup-refund-list"></div>';
        bindRefundForm(panel);
        finFetch('/refunds').then(function (rows) {
          document.getElementById('lnkup-refund-list').innerHTML = tableFromRows(['Amount', 'Type', 'Status', 'Date'], (rows || []).map(function (r) {
            return ['$' + Number(r.amount).toFixed(2), escapeHtml(r.refund_type), statusChip(r.status), escapeHtml((r.created_at || '').slice(0, 10))];
          }));
        }).catch(function () {
          document.getElementById('lnkup-refund-list').innerHTML = '<p class="lnkup-muted">Could not load refunds.</p>';
        });
        panel.dataset.loaded = '1';
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-disputes') {
        var params = new URLSearchParams(window.location.search);
        var prefill = params.get('dispute_payment') || '';
        if (!prefill) {
          try {
            prefill = sessionStorage.getItem('lnkup_dispute_payment') || '';
            sessionStorage.removeItem('lnkup_dispute_payment');
          } catch (err) { /* ignore */ }
        }
        panel.innerHTML = disputeRequestForm(prefill) + '<div id="lnkup-dispute-list"></div>';
        bindDisputeForm(panel);
        finFetch('/disputes').then(function (rows) {
          var list = document.getElementById('lnkup-dispute-list');
          if (!list) return;
          if (!rows || !rows.length) {
            list.innerHTML = '<p class="lnkup-muted">No disputes yet.</p>';
            return;
          }
          var html = '<div class="lnkup-fin-dispute-cards">';
          (rows || []).forEach(function (r) {
            html += '<article class="lnkup-fin-info-card">';
            html += '<h4 class="lnkup-fin-info-card__title">' + escapeHtml(r.event_title || ('Payment #' + r.payment_id)) + ' ' + statusChip(r.status) + '</h4>';
            html += '<p>$' + Number(r.amount || 0).toFixed(2) + ' · ' + escapeHtml((r.created_at || '').slice(0, 10)) + '</p>';
            html += '<p>' + escapeHtml(r.reason || '') + '</p>';
            if (r.resolution_note) {
              html += '<p class="lnkup-muted">Resolution: ' + escapeHtml(r.resolution_note) + '</p>';
            }
            html += renderDisputeEvidence(r.evidence);
            if (r.is_open) {
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-dispute-respond" data-id="' + r.id + '">Add note</button>';
            }
            html += '</article>';
          });
          html += '</div>';
          list.innerHTML = html;
          bindVendorDisputeRespond(panel);
        }).catch(function () {
          var list = document.getElementById('lnkup-dispute-list');
          if (list) list.innerHTML = '<p class="lnkup-muted">Could not load disputes.</p>';
        });
        panel.dataset.loaded = '1';
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-settings' || panel.id === 'lnkup-fin-vendor-payment-methods') {
        panel.dataset.loaded = '';
        loadVendorSettings(panel);
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-wallet') {
        loadList(panel, '/wallet', function (data) {
          var html = kpiGrid([
            { label: 'Balance', value: '$' + Number((data.balance || 0)).toFixed(2) },
            { label: 'Credits', value: String((data.credits || []).length) }
          ]);
          html += tableFromRows(['Type', 'Amount', 'Date'], (data.entries || []).map(function (r) {
            return [escapeHtml(r.entry_type), '$' + Number(r.amount).toFixed(2), escapeHtml((r.created_at || '').slice(0, 10))];
          }));
          return html;
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-referrals') {
        loadList(panel, '/referrals', function (data) {
          var code = (data.code && data.code.code) || '';
          var shareUrl = code && window.location.origin
            ? window.location.origin + '/?ref=' + encodeURIComponent(code)
            : '';
          var html = '<div class="lnkup-fin-referral-share">';
          html += '<p class="lnkup-muted">Peer vendor credits (invite vendors). To recruit organizers for 12-month fee share, open Referral Invites.</p>';
          html += '<p>Your referral code: <strong id="lnkup-ref-code">' + escapeHtml(code || '—') + '</strong></p>';
          if (shareUrl) {
            html += '<p class="lnkup-muted lnkup-fin-referral-link">' + escapeHtml(shareUrl) + '</p>';
            html += '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-copy-ref-link">Copy share link</button>';
          } else {
            html += '<p class="lnkup-muted">Referral codes will appear after your account is fully set up.</p>';
          }
          html += '</div>';
          html += '<h3>Referrals</h3>';
          html += tableFromRows(['Referred user', 'Status', 'Date'], (data.referrals || []).map(function (r) {
            var name = r.referred_name || ('User #' + (r.referred_user_id || ''));
            return [escapeHtml(name), statusChip(r.status), escapeHtml((r.created_at || '').slice(0, 10))];
          }));
          html += '<h3>Rewards</h3>';
          html += tableFromRows(['Type', 'Amount', 'Status', 'Date'], (data.rewards || []).map(function (r) {
            return [escapeHtml(r.event_type || ''), '$' + Number(r.amount || 0).toFixed(2), statusChip(r.status), escapeHtml((r.created_at || '').slice(0, 10))];
          }));
          setTimeout(function () {
            var copyBtn = panel.querySelector('#lnkup-copy-ref-link');
            if (copyBtn && shareUrl) {
              copyBtn.addEventListener('click', function () {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                  navigator.clipboard.writeText(shareUrl);
                }
                copyBtn.textContent = 'Copied!';
              });
            }
          }, 0);
          return html;
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-premium') {
        loadList(panel, '/premium-listings', function (rows) {
          return tableFromRows(['Type', 'Amount', 'Impressions', 'Clicks', 'Status'], (rows || []).map(function (r) {
            return [escapeHtml(r.placement_type), '$' + Number(r.amount).toFixed(2), String(r.impressions), String(r.clicks), statusChip(r.status)];
          }));
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-tax') {
        loadList(panel, '/tax-center', function (data) {
          return kpiGrid([
            { label: 'W-9 on file', value: data.w9_on_file ? 'Yes' : 'No' },
            { label: '1099 eligible', value: data.form_1099_eligible ? 'Yes' : 'No' },
            { label: 'Year gross', value: '$' + Number((data.yearly && data.yearly.gross) || (data.yearly && data.yearly.spent) || 0).toFixed(2) }
          ]) + taxPdfLinks(data);
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-reports') {
        loadList(panel, '/reports/vendor', function (data) {
          return kpiGrid([
            { label: 'Lifetime spend', value: '$' + Number(data.lifetime_spend || 0).toFixed(2) },
            { label: 'Refunds', value: '$' + Number(data.refunds || 0).toFixed(2) }
          ]);
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-invoices') {
        loadList(panel, '/invoices', function (rows) {
          return renderInvoiceList(rows);
        });
        return;
      }
      if (panel.id === 'lnkup-fin-vendor-receipts') {
        loadList(panel, '/receipts', function (rows) {
          return tableFromRows(['Receipt', 'Amount', 'Method', 'Date'], rows.map(function (r) {
            return [escapeHtml(r.receipt_number), '$' + Number(r.amount).toFixed(2), escapeHtml(r.payment_method), escapeHtml((r.created_at || '').slice(0, 10))];
          }), {
            title: window.LNKupUI ? LNKupUI.t('empty_receipts', 'No receipts yet') : 'No receipts yet',
            desc: 'Payment receipts will appear here after you pay for events.'
          });
        });
        return;
      }
    });
  }

  function initOrganizer() {
    var shell = document.getElementById('lnkup-organizer-financial');
    if (!shell) {
      return;
    }
    bindTabs(shell);
    loadOrganizerConnectBanner();
    handleConnectReturnQuery();
    refreshOrganizerBalanceHero();
    loadOrganizerOverview(document.getElementById('lnkup-fin-org-overview'));
    shell.addEventListener('lnkup-fin-panel-open', function (e) {
      var panel = e.target;
      if (!panel || !panel.id) {
        return;
      }
      if (panel.id === 'lnkup-fin-org-wallet') {
        loadList(panel, '/organizer-wallet', function (data) {
          var balances = resolveOrganizerBalances(data || {});
          var stripeBal = data.stripe_balance || balances.stripeBal || {};
          var html = '<div class="lnkup-fin-wallet-panels">';
          html += '<h3>Summary</h3>';
          html += kpiGrid([
            { label: 'Available', value: '$' + balances.available.toFixed(2) },
            { label: 'Pending', value: '$' + balances.pending.toFixed(2) },
            { label: 'Processing', value: '$' + balances.processing.toFixed(2) },
            { label: 'Lifetime paid', value: '$' + Number((data.balances && data.balances.lifetime_paid) || 0).toFixed(2) },
            { label: 'Reserve', value: '$' + balances.reserve.toFixed(2) },
            { label: 'LNKup settled', value: '$' + balances.ledgerSettled.toFixed(2) }
          ]);
          if (balances.stripeSynced) {
            html += infoCard(t('account_balance', 'Account balance'), '<p>Available: <strong>$' + balances.available.toFixed(2) + '</strong> · Pending: <strong>$' + Number(stripeBal.pending || balances.pending).toFixed(2) + '</strong></p>', 'success');
            if (Number(balances.platformRecoverable) > 0) {
              html += '<p class="lnkup-muted">Includes $' + Number(balances.platformRecoverable).toFixed(2) + ' held on LNKup (moves to your payout account on cash out).</p>';
            }
          }
          var connect = data.connect || {};
          html += '<h3>Connect status</h3>';
          html += payoutStatusCard(connect);
          html += connectWalletBanner(connect);
          if (connect.status !== 'active' && connect.available) {
            html += '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-connect-onboard">Finish payout setup</button>';
          }
          html += '<h3>Ledger</h3>';
          html += tableFromRows(['Type', 'Amount', 'Note', 'Date'], (data.entries || []).map(function (r) {
            return [escapeHtml(r.entry_type), '$' + Number(r.amount).toFixed(2), escapeHtml(r.note || ''), escapeHtml((r.created_at || '').slice(0, 10))];
          }));
          html += '</div>';
          setTimeout(function () {
            bindConnectOnboardButton(connect);
          }, 0);
          return html;
        });
        return;
      }
      if (panel.id === 'lnkup-fin-org-cashout') {
        panel.dataset.loaded = '';
        loadCashoutPanel(panel);
        return;
      }
      if (panel.id === 'lnkup-fin-org-vendor-revenue') {
        if (!panel.dataset.loaded) {
          panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
          fetchFinancialJson('/transactions?limit=25').then(function (result) {
            panel.dataset.loaded = '1';
            if (!result.ok) {
              failPanel(panel, result.error);
              return;
            }
            panel.innerHTML = vendorRevenueTable(result.data || []);
            bindVendorRevenuePanel(panel);
          }).catch(function () {
            panel.dataset.loaded = '1';
            failPanel(panel);
          });
        }
        return;
      }
      if (panel.id === 'lnkup-fin-org-sponsor') {
        loadList(panel, '/sponsor-invoices', function (rows) {
          return tableFromRows(['Sponsor', 'Amount', 'Status', 'Due'], (rows || []).map(function (r) {
            return [escapeHtml(r.sponsor_name), '$' + Number(r.amount).toFixed(2), statusChip(r.status), escapeHtml(r.due_date || '')];
          }));
        });
        return;
      }
      if (panel && panel.id === 'lnkup-fin-org-payouts') {
        loadList(panel, '/organizer-payouts', function (rows) {
          var html = '';
          (rows || []).forEach(function (r) {
            html += '<div class="lnkup-fin-payout-card">';
            html += '<div class="lnkup-fin-payout-card__header"><h4>$' + Number(r.net_amount).toFixed(2) + ' · ' + escapeHtml(r.payout_type) + '</h4>' + statusChip(r.status) + '</div>';
            html += payoutTimeline(r.status);
            html += '<p class="lnkup-fin-payout-card__secondary lnkup-muted">' + escapeHtml((r.created_at || '').slice(0, 10)) + '</p>';
            if (r.status === 'failed' && r.failure_reason) {
              html += '<p class="lnkup-fin-payout-card__error lnkup-alert lnkup-alert--error" role="alert">' + escapeHtml(r.failure_reason) + '</p>';
            }
            html += '</div>';
          });
          if (!rows || !rows.length) {
            html = '<p class="lnkup-fin-empty">No payouts yet.</p>';
          }
          return html;
        });
        return;
      }
      if (panel.id === 'lnkup-fin-org-reserves') {
        loadList(panel, '/organizer-wallet', function (data) {
          var html = '<h3>Escrow holds</h3>';
          html += tableFromRows(['Payment', 'Amount', 'Status'], (data.escrow || []).map(function (r) {
            return ['#' + r.payment_post_id, '$' + Number(r.amount).toFixed(2), statusChip(r.status)];
          }));
          html += '<h3>Reserve holds</h3>';
          html += tableFromRows(['Event', 'Amount', 'Status'], (data.reserves || []).map(function (r) {
            return ['#' + r.event_id, '$' + Number(r.amount).toFixed(2), statusChip(r.status)];
          }));
          return html;
        });
        return;
      }
      if (panel.id === 'lnkup-fin-org-budget') {
        loadOrganizerBudget(panel);
        return;
      }
      if (panel.id === 'lnkup-fin-org-forecast') {
        loadOrganizerForecast(panel);
        return;
      }
      if (panel.id === 'lnkup-fin-org-settings') {
        loadOrganizerSettings(panel);
        return;
      }
      if (panel.id === 'lnkup-fin-org-tax') {
        loadList(panel, '/tax-center', function (data) {
          return kpiGrid([
            { label: 'W-9 on file', value: data.w9_on_file ? 'Yes' : 'No' },
            { label: '1099 eligible', value: data.form_1099_eligible ? 'Yes' : 'No' },
            { label: 'Year net', value: '$' + Number((data.yearly && data.yearly.net) || 0).toFixed(2) }
          ]) + taxPdfLinks(data);
        });
        return;
      }
      if (panel && panel.id === 'lnkup-fin-org-refunds') {
        panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
        finFetch('/refunds').then(function (rows) {
          var html = tableFromRows(['Amount', 'Status', 'Reason', ''], (rows || []).map(function (r) {
            var actions = r.status === 'pending' ? '<button class="lnkup-btn lnkup-btn--sm lnkup-approve-refund" data-id="' + r.id + '">Approve</button> <button class="lnkup-btn lnkup-btn--sm lnkup-deny-refund" data-id="' + r.id + '">Deny</button>' : '';
            return ['$' + Number(r.amount).toFixed(2), statusChip(r.status), escapeHtml(r.reason || ''), actions];
          }));
          panel.innerHTML = html;
          panel.querySelectorAll('.lnkup-approve-refund').forEach(function (btn) {
            btn.addEventListener('click', function () {
              finFetch('/refunds/' + btn.getAttribute('data-id') + '/approve', { method: 'POST', body: {} }).then(function () { panel.dataset.loaded = ''; panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open')); });
            });
          });
          panel.querySelectorAll('.lnkup-deny-refund').forEach(function (btn) {
            btn.addEventListener('click', function () {
              finFetch('/refunds/' + btn.getAttribute('data-id') + '/deny', { method: 'POST', body: {} }).then(function () { panel.dataset.loaded = ''; panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open')); });
            });
          });
        });
        panel.dataset.loaded = '1';
        return;
      }
      if (panel && panel.id === 'lnkup-fin-org-disputes') {
        panel.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
        finFetch('/disputes').then(function (rows) {
          if (!rows || !rows.length) {
            panel.innerHTML = '<p class="lnkup-fin-empty">No disputes yet.</p>';
            return;
          }
          var html = '<div class="lnkup-fin-dispute-cards">';
          (rows || []).forEach(function (r) {
            html += '<article class="lnkup-fin-info-card">';
            html += '<h4 class="lnkup-fin-info-card__title">' + escapeHtml(r.event_title || ('Payment #' + r.payment_id)) + ' ' + statusChip(r.status) + '</h4>';
            html += '<p>$' + Number(r.amount || 0).toFixed(2) + ' · ' + escapeHtml(r.source || 'vendor') + ' · ' + escapeHtml((r.created_at || '').slice(0, 10)) + '</p>';
            html += '<p>' + escapeHtml(r.reason || '') + '</p>';
            html += renderDisputeEvidence(r.evidence);
            if (r.resolution_note) {
              html += '<p class="lnkup-muted">Resolution: ' + escapeHtml(r.resolution_note) + '</p>';
            }
            if (r.is_open) {
              html += '<div class="lnkup-fin-dispute-actions">';
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-dispute-respond" data-id="' + r.id + '">Add evidence</button> ';
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm" data-dispute-resolve="approved_refund" data-id="' + r.id + '">Approve refund</button> ';
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm" data-dispute-resolve="approved_credit" data-id="' + r.id + '">Approve credit</button> ';
              html += '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-dispute-resolve="denied" data-id="' + r.id + '">Deny</button>';
              html += '</div>';
            }
            html += '</article>';
          });
          html += '</div>';
          panel.innerHTML = html;
          bindOrganizerDisputeResolve(panel);
        }).catch(function () {
          panel.innerHTML = '<p class="lnkup-muted">Could not load disputes.</p>';
        });
        panel.dataset.loaded = '1';
        return;
      }
      if (panel && panel.id === 'lnkup-fin-org-reports') {
        loadList(panel, '/reports/organizer', function (data) {
          var html = kpiGrid([
            { label: 'Revenue', value: '$' + Number(data.revenue || 0).toFixed(2) },
            { label: 'Open disputes', value: String(data.open_disputes || 0) }
          ]);
          html += '<h3>Revenue by event</h3>';
          html += tableFromRows(['Event', 'Revenue', 'Bookings'], (data.revenue_by_event || []).map(function (r) {
            return [escapeHtml(r.event_title), '$' + Number(r.revenue).toFixed(2), String(r.bookings)];
          }));
          return html;
        });
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!base) {
      return;
    }
    initVendor();
    initOrganizer();
    loadEventFinancials(document.getElementById('lnkup-event-financial'));
    document.querySelectorAll('[data-ops-tab="financial"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTimeout(function () {
          loadEventFinancials(document.getElementById('lnkup-event-financial'));
        }, 50);
      });
    });
    document.addEventListener('lnkup-ops-panel-open', function (e) {
      if (!e.detail || e.detail.tab !== 'financial') {
        return;
      }
      loadEventFinancials(document.getElementById('lnkup-event-financial'));
    });
    document.addEventListener('lnkup-financial-expand', function () {
      var shell = document.getElementById('lnkup-organizer-financial');
      if (shell && typeof initOrganizer === 'function') {
        initOrganizer();
      }
    });
  });
})();
