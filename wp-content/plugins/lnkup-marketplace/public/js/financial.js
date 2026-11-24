(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.financialRestUrl || '';
  var nonce = ajax.restNonce || '';

  var API_ERROR_MSG = 'Could not load financial data.';
  var CONNECT_ONBOARDING_ERROR_MSG = 'We couldn\'t open payout setup. Try again in a minute.';

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

    var money = payload.money || null;
    if (money && money.metrics) {
      settlement = money.settlement_mode || settlement;
      isDest = settlement === 'destination';
      available = metricDollars(money.metrics.available, available);
      pending = metricDollars(money.metrics.pending, pending);
      processing = metricDollars(money.metrics.processing, processing);
      reserve = metricDollars(money.metrics.reserve, reserve);
      ledgerSettled = metricDollars(money.metrics.lnkup_settled, ledgerSettled);
      stripeSynced = !isDest || isMetricOk(money.metrics.available);
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
      sourceTypes: sourceTypes,
      money: money,
      payouts: (money && money.payouts) || null
    };
  }

  /** Whether a money-contract metric could be read (status ok with integer cents). */
  function isMetricOk(metric) {
    return !!metric && metric.status === 'ok' && metric.cents != null;
  }

  /** Dollars from a contract metric, or the legacy fallback when the contract is absent. */
  function metricDollars(metric, fallback) {
    if (!metric) {
      return Number(fallback || 0);
    }
    return isMetricOk(metric) ? Number(metric.cents) / 100 : 0;
  }

  /** "$12.34", or "Unavailable" when the source could not be read — never a fake $0. */
  function metricLabel(metric, fallbackDollars) {
    if (metric) {
      return isMetricOk(metric) ? '$' + (Number(metric.cents) / 100).toFixed(2) : t('money_unavailable', 'Unavailable');
    }
    return '$' + Number(fallbackDollars || 0).toFixed(2);
  }

  function contractMetric(payload, key) {
    var money = payload && payload.money;
    return money && money.metrics ? (money.metrics[key] || null) : null;
  }

  var CASH_OUT_REASON_COPY = {
    payouts_not_active: 'Finish payout setup to cash out.',
    balance_unavailable: 'We could not read your payout balance right now. Refresh in a moment.',
    no_available_balance: 'Nothing is available to cash out yet. New card payments usually become available within a few days.'
  };

  function lastSyncedLine(money) {
    if (!money) {
      return '';
    }
    var when = money.last_synced_at ? String(money.last_synced_at).slice(0, 16).replace('T', ' ') : '';
    return '<p class="lnkup-muted lnkup-fin-synced" data-testid="lnkup-fin-last-synced">' +
      (when ? 'Last synced with Stripe ' + escapeHtml(when) : 'Not synced with Stripe yet') +
      ' · <button type="button" class="lnkup-link-btn" data-fin-sync>Sync now</button></p>';
  }

  /** POST /financial/sync, then reload money panels. */
  function bindSyncButtons(root) {
    (root || document).querySelectorAll('[data-fin-sync]').forEach(function (btn) {
      if (btn.getAttribute('data-fin-sync-bound') === '1') {
        return;
      }
      btn.setAttribute('data-fin-sync-bound', '1');
      btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Syncing…';
        fetchFinancialJson('/sync', { method: 'POST', body: {} }).then(function (res) {
          btn.disabled = false;
          btn.textContent = 'Sync now';
          if (!res.ok) {
            finToast(res.error || 'Could not sync with Stripe.', 'error');
            return;
          }
          if (res.data && res.data.throttled) {
            finToast('Synced moments ago. Try again in a few seconds.', 'info');
            return;
          }
          finToast('Synced with Stripe.', 'success');
          document.querySelectorAll('#lnkup-organizer-financial .lnkup-fin-panel, #lnkup-vendor-financial .lnkup-fin-panel').forEach(function (panel) {
            panel.dataset.loaded = '';
          });
          refreshOrganizerBalanceHero();
          var active = document.querySelector('#lnkup-organizer-financial .lnkup-fin-panel:not([hidden])');
          if (active) {
            active.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
          }
        });
      });
    });
  }

  /** Pending explanation from the server (release date when Stripe reports one). */
  function pendingHint(money, stripeBalance) {
    var release = (money && money.pending_release) || {};
    if (release.hint) {
      return String(release.hint);
    }
    var date = shortDate(release.next_available_on || (stripeBalance && stripeBalance.next_available_on));
    if (date) {
      return 'Vendor payments received. Stripe releases this to Available on ' + date + '.';
    }
    return 'Vendor payments received and settling at Stripe. This usually takes a few business days.';
  }

  /** "Nov 25" from a Y-m-d date (no timezone shift). */
  function shortDate(ymd) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
    if (!m) {
      return '';
    }
    var names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return names[Number(m[2]) - 1] + ' ' + Number(m[3]);
  }

  /** Organizer money in flow order from the server contract. */
  function moneyContractGrid(money) {
    var m = money.metrics || {};
    var rows = [
      ['net_earnings', 'Earnings', 'What you\'ve earned from paid booths, after refunds.'],
      ['collected', 'Collected', 'Booth payments you\'ve received, before refunds.'],
      ['refunds', 'Refunds', 'Money returned to vendors.'],
      ['pending', 'Pending', pendingHint(money)],
      ['available', 'Available to cash out', 'Ready to send to your bank.'],
      ['in_transit', 'In transit', 'On the way to your bank.'],
      ['paid_out', 'Paid out', money.settlement_mode === 'destination' ? 'Payouts that reached your bank, including automatic payouts.' : 'Cashouts that reached your bank.']
    ];
    var html = '<div class="lnkup-fin-kpi-grid lnkup-fin-money-grid" data-testid="lnkup-fin-money-categories">';
    rows.forEach(function (row) {
      var metric = m[row[0]] || null;
      var unavailable = metric && !isMetricOk(metric);
      html += '<div class="lnkup-fin-kpi lnkup-fin-kpi--' + escapeHtml(row[0]) + (unavailable ? ' is-unavailable' : '') + '" data-metric="' + escapeHtml(row[0]) + '">' +
        '<p class="lnkup-fin-kpi__label">' + escapeHtml(row[1]) + '</p>' +
        '<p class="lnkup-fin-kpi__value">' + escapeHtml(metricLabel(metric, 0)) + '</p>' +
        '<p class="lnkup-fin-kpi__hint">' + escapeHtml(row[2]) + '</p></div>';
    });
    html += '</div>';
    return html;
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
    if (walletData.money) {
      dash.money = Object.assign({}, walletData.money, {
        metrics: Object.assign({}, (dash.money && dash.money.metrics) || {}, walletData.money.metrics || {}, dash.money && dash.money.metrics ? {
          collected: dash.money.metrics.collected,
          refunds: dash.money.metrics.refunds,
          net_earnings: dash.money.metrics.net_earnings
        } : {})
      });
    }
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

  /**
   * Organizer money in flow order with one-line explanations. Pending and available come from the
   * live balance resolver so they match the balance hero.
   */
  function moneyCategoryGrid(categories, balances) {
    var live = { pending: balances.pending, available: balances.available };
    var html = '<div class="lnkup-fin-kpi-grid lnkup-fin-money-grid" data-testid="lnkup-fin-money-categories">';
    categories.forEach(function (cat) {
      var amount = Object.prototype.hasOwnProperty.call(live, cat.key) ? live[cat.key] : Number(cat.amount || 0);
      html += '<div class="lnkup-fin-kpi lnkup-fin-kpi--' + escapeHtml(cat.key) + '">' +
        '<p class="lnkup-fin-kpi__label">' + escapeHtml(cat.label) + '</p>' +
        '<p class="lnkup-fin-kpi__value">$' + Number(amount || 0).toFixed(2) + '</p>' +
        '<p class="lnkup-fin-kpi__hint">' + escapeHtml(cat.hint || '') + '</p></div>';
    });
    html += '</div>';
    return html;
  }

  function eventBreakdownTable(rows) {
    var html = '<section class="lnkup-fin-event-breakdown" data-testid="lnkup-fin-event-breakdown"><h3>By event</h3>' +
      '<div class="lnkup-table-wrap"><table class="lnkup-table"><thead><tr><th scope="col">Event</th><th scope="col">Paid bookings</th><th scope="col">Collected</th></tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr><td>' + escapeHtml(row.event_title || 'Event') + '</td><td>' + escapeHtml(String(row.bookings || 0)) + '</td><td>$' + Number(row.revenue || 0).toFixed(2) + '</td></tr>';
    });
    html += '</tbody></table></div></section>';
    return html;
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
        ? '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' + escapeHtml(pdf) + '" target="_blank" rel="noopener" data-testid="lnkup-invoice-view">View</a>'
        : '';
      html += '<article class="lnkup-fin-invoice-card">' +
        '<div class="lnkup-fin-invoice-card__main">' +
        '<p class="lnkup-type-label">' + escapeHtml(r.invoice_number || 'Invoice') + '</p>' +
        '<h3>' + escapeHtml(r.event_title || 'Invoice') + '</h3>' +
        '<p class="lnkup-type-meta">' + (due ? ('Due ' + escapeHtml(due)) : '') + '</p>' +
        '</div>' +
        '<div class="lnkup-fin-invoice-card__side">' +
        '<p class="lnkup-type-display">$' + Number(r.total_amount || 0).toFixed(2) + '</p>' +
        statusChip(r.status) +
        viewLink +
        '</div></article>';
    });
    html += '</div>';
    return html;
  }

  function moneyFlowCell(row) {
    var type = String(row.entry_type || '');
    var source = String(row.source_type || '');
    var moneyOut = type === 'debit' || source === 'payout';
    if (source === 'payout_failed' || source === 'payout_cancel') {
      moneyOut = false;
    }
    var cls = moneyOut ? 'lnkup-money-out' : 'lnkup-money-in';
    var sign = moneyOut ? '\u2212' : '+';
    return '<span class="' + cls + '">' + sign + '$' + Number(row.amount || 0).toFixed(2) + '</span>';
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
      var submit = form.querySelector('[type="submit"]');
      if (submit && submit.disabled) {
        return;
      }
      if (submit) {
        submit.disabled = true;
      }
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
        if (res && res.id) {
          panel.dataset.loaded = '';
          panel.innerHTML = '<div class="lnkup-alert lnkup-alert--success" role="status"><p>Refund request submitted. The organizer will review it.</p></div>';
          return;
        }
        formMessage(form, (res && res.message) || 'Could not submit the refund request.');
      }).catch(function (err) {
        formMessage(form, (err && err.message) || 'Could not submit the refund request.');
      }).finally(function () {
        if (submit) {
          submit.disabled = false;
        }
      });
    });
  }

  /**
   * Inline error under a financial form instead of a blocking alert().
   */
  function formMessage(form, message) {
    var box = form.querySelector('.lnkup-fin-form-error');
    if (!box) {
      box = document.createElement('p');
      box.className = 'lnkup-fin-form-error lnkup-alert lnkup-alert--error';
      box.setAttribute('role', 'alert');
      form.appendChild(box);
    }
    box.textContent = message;
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
          formMessage(form, res.message || 'Could not open dispute.');
          return;
        }
        panel.dataset.loaded = '';
        panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
      }).catch(function (err) {
        formMessage(form, (err && err.message) || 'Could not open dispute.');
      });
    });
  }

  function finToast(message, type) {
    if (typeof window.lnkupShowToast === 'function') {
      window.lnkupShowToast(message, type || 'success');
    }
  }

  /** Inline dialog in place of window.prompt(); resolves null when cancelled. */
  function finAsk(title, label, required) {
    if (window.LNKupUI && typeof window.LNKupUI.askText === 'function') {
      return window.LNKupUI.askText({ title: title, label: label, required: !!required, multiline: true, confirmLabel: 'Send' });
    }
    return Promise.resolve(null);
  }

  function reloadPanel(panel) {
    panel.dataset.loaded = '';
    panel.dispatchEvent(new CustomEvent('lnkup-fin-panel-open', { bubbles: true }));
  }

  /**
   * Post a dispute reply/evidence note, with inline feedback.
   */
  function sendDisputeNote(panel, btn, id, note) {
    btn.disabled = true;
    return finFetch('/disputes/' + id + '/respond', { method: 'POST', body: { evidence: note } }).then(function (res) {
      if (res && res.code) {
        finToast(res.message || 'Could not add your note.', 'error');
        return;
      }
      finToast('Note added.');
      reloadPanel(panel);
    }).catch(function () {
      finToast('Could not add your note. Try again.', 'error');
    }).finally(function () {
      btn.disabled = false;
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
        finAsk('Reply to the organizer', 'Your note', true).then(function (note) {
          if (note) {
            sendDisputeNote(panel, btn, id, note);
          }
        });
      });
    });
  }

  function bindOrganizerDisputeResolve(panel) {
    panel.querySelectorAll('[data-dispute-resolve]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        var outcome = btn.getAttribute('data-dispute-resolve');
        var denied = outcome === 'denied';
        finAsk(denied ? 'Deny dispute' : 'Resolve dispute', denied ? 'Reason for the vendor (required)' : 'Note for the vendor (optional)', denied).then(function (note) {
          if (note === null) {
            return;
          }
          btn.disabled = true;
          finFetch('/disputes/' + id + '/resolve', {
            method: 'POST',
            body: { outcome: outcome, note: note }
          }).then(function (res) {
            if (res && res.code) {
              finToast(res.message || 'Could not resolve dispute.', 'error');
              return;
            }
            finToast('Dispute updated.');
            reloadPanel(panel);
          }).catch(function () {
            finToast('Could not resolve dispute. Try again.', 'error');
          }).finally(function () {
            btn.disabled = false;
          });
        });
      });
    });
    panel.querySelectorAll('.lnkup-dispute-respond').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        finAsk('Add evidence or a reply', 'Your note', true).then(function (note) {
          if (note) {
            sendDisputeNote(panel, btn, id, note);
          }
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
        balanceHeroUnavailable();
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
      balanceHeroUnavailable();
      failPanel(panel, 'Could not load financial overview.');
    });
  }

  /**
   * Replace the balance hero skeleton with a quiet unavailable state.
   */
  function balanceHeroUnavailable() {
    var hero = document.getElementById('lnkup-fin-balance-hero');
    if (!hero || hero.dataset.loaded) {
      return;
    }
    hero.dataset.loaded = '1';
    hero.innerHTML = '<p class="lnkup-muted lnkup-fin-balance-hero__unavailable">Balances are unavailable right now. Your payments are safe — refresh to try again.</p>';
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
    hero.dataset.loaded = '1';
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
    Promise.allSettled([
      finFetch('/payment-methods'),
      finFetch('/installments'),
      finFetch('/tax-profile')
    ]).then(function (settled) {
      if (settled.every(function (r) { return r.status !== 'fulfilled'; })) {
        failPanel(panel, 'Could not load payment settings.');
        return;
      }
      var methods = settledValue(settled[0], []) || [];
      var installments = settledValue(settled[1], []) || [];
      var tax = settledValue(settled[2], {}) || {};
      panel.dataset.loaded = '1';
      var html = '<section id="lnkup-fin-settings-methods"><h3>Payment methods</h3>';
      html += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-add-card">Add card</button>';
      html += '<div class="lnkup-pay-methods">';
      if (!methods.length) {
        html += '<p class="lnkup-muted">No saved cards yet.</p>';
      }
      methods.forEach(function (r) {
        var brand = r.brand || r.method_type || 'Card';
        html += '<article class="lnkup-pay-method">' +
          '<div><p class="lnkup-pay-method__brand">' + escapeHtml(brand) + ' ···· ' + escapeHtml(r.last4 || '') + '</p>' +
          '<p class="lnkup-pay-method__meta">Expires ' + escapeHtml((r.exp_month || '') + '/' + (r.exp_year || '')) + '</p></div>' +
          '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm lnkup-remove-card" data-id="' + r.id + '">Remove</button></article>';
      });
      html += '</div>';
      html += '</section><section id="lnkup-fin-settings-installments"><h3>Installments</h3>';
      html += tableFromRows(['Due', 'Amount', 'Sequence'], installments.map(function (r) {
        return [escapeHtml(r.due_date), '$' + Number(r.amount).toFixed(2), String(r.sequence)];
      }));
      html += '</section><section id="lnkup-fin-settings-tax"><h3>Tax documents</h3>';
      html += '<p>W-9 on file: ' + (tax && tax.w9_on_file ? 'Yes' : 'No') + '</p>';
      html += '<p>1099 eligible: ' + (tax && tax.form_1099_eligible ? 'Yes' : 'No') + '</p>';
      html += taxPdfLinks(tax) + '</section>';
      panel.innerHTML = html;
      panel.querySelectorAll('.lnkup-remove-card').forEach(function (button) {
        button.addEventListener('click', function () {
          var id = button.getAttribute('data-id');
          if (!id) {
            return;
          }
          button.disabled = true;
          finFetch('/payment-methods/' + id, { method: 'DELETE' }).then(function () {
            panel.dataset.loaded = '';
            loadVendorSettings(panel);
          }).catch(function () {
            button.disabled = false;
          });
        });
      });
      var addCard = panel.querySelector('#lnkup-add-card');
      if (addCard) {
        addCard.addEventListener('click', function () {
          finFetch('/payment-methods/setup', { method: 'POST', body: {} }).then(function (setup) {
            if (setup.client_secret && window.Stripe && ajax.stripePublicKey) {
              finToast('Finish card setup in checkout. Saved cards appear after setup.');
            }
          }).catch(function () {
            finToast('Could not start card setup.', 'error');
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
    dash = dash || {};
    var balances = resolveOrganizerBalances(dash);
    var connect = dash.connect || {};
    var collected = Number(dash.collected || dash.gross_revenue || 0);
    var paidOut = Number(dash.payouts_paid || 0);
    function moneyCell(label, key, fallback) {
      return '<div class="lnkup-home-stat" data-metric="' + escapeHtml(key) + '"><dt>' + escapeHtml(label) + '</dt><dd>' +
        escapeHtml(metricLabel(contractMetric(dash, key), fallback)) + '</dd></div>';
    }
    var html = '<dl class="lnkup-home-stats">';
    html += moneyCell('Available', 'available', balances.available);
    html += moneyCell('Pending', 'pending', balances.pending);
    html += moneyCell('Collected', 'collected', collected);
    html += moneyCell('Paid out', 'paid_out', paidOut);
    html += '</dl>';
    var instantPayouts = balances.payouts || {};
    if (instantPayouts.can_instant_cash_out && Number(instantPayouts.instant_available_cents) >= 100) {
      html += '<p class="lnkup-fin-instant-note" data-testid="lnkup-fin-instant-note"><strong>Instant payout available now: $' +
        (Number(instantPayouts.instant_available_cents) / 100).toFixed(2) + '</strong></p>';
    }
    if (Number(balances.pending || 0) > 0) {
      html += '<p class="lnkup-muted lnkup-fin-pending-note" data-testid="lnkup-fin-pending-note">' + escapeHtml(pendingHint(balances.money || dash.money, dash.stripe_balance)) + '</p>';
    }
    html += '<p class="lnkup-money-payout">' + payoutBadgeChip(connect, balances.payouts) + '</p>';
    html += lastSyncedLine(balances.money);
    hero.innerHTML = html;
    bindSyncButtons(hero);
  }

  function paymentAuditBreakdown(audit) {
    if (!audit) {
      return '';
    }
    var rows = [
      ['Booth fee (stored)', '$' + Number(audit.booth_fee_stored || 0).toFixed(2)],
      ['Booth fee (opportunity)', '$' + Number(audit.booth_fee_opportunity || 0).toFixed(2)]
    ];
    // Fee breakdown rows only render when the server sends them (admins); organizers never receive these fields.
    [
      ['vendor_checkout_fee', 'Vendor checkout fee'],
      ['organizer_commission', 'Organizer commission'],
      ['platform_fee', 'Platform fee total'],
      ['processing_fee', 'Processing fee'],
      ['organizer_net', 'Organizer net'],
      ['total_charged', 'Total charged']
    ].forEach(function (field) {
      if (audit[field[0]] != null) {
        rows.push([field[1], '$' + Number(audit[field[0]] || 0).toFixed(2)]);
      }
    });
    if (audit.fee_payer_mode) {
      rows.push(['Fee payer', escapeHtml(audit.fee_payer_mode)]);
    }
    rows = rows.concat([
      ['Settlement mode', escapeHtml(audit.settlement_mode || '—')],
      ['Payment reference', escapeHtml(audit.stripe_payment_intent || '—')],
      ['Payment status', escapeHtml(audit.stripe_intent_status || '—')]
    ]);
    if (audit.application_fee_cents != null) {
      rows.push(['Application fee (cents)', String(audit.application_fee_cents || 0)]);
    }
    if (audit.destination_amount != null) {
      rows.push(['Destination gross', '$' + Number(audit.destination_amount || 0).toFixed(2)]);
    }
    rows.push(['Ledger settled', audit.ledger_connect_settlement ? 'Yes' : 'No']);
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
      html += '<td>' + statusChip(t.status) + (t.funds_available_on && shortDate(t.funds_available_on)
        ? '<br><span class="lnkup-muted lnkup-fin-funds-on" data-testid="lnkup-fin-funds-available">Funds available ' + escapeHtml(shortDate(t.funds_available_on)) + '</span>'
        : '') + '</td>';
      html += '<td>' + escapeHtml((t.paid_at || t.created_at || '').slice(0, 10)) + '</td>';
      html += '<td>' + (pid && audit.can_reconcile
        ? '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-fin-reconcile" data-payment-id="' + pid + '">' + (audit.payment_status === 'paid' ? 'Reconcile' : 'Sync payment') + '</button>'
        : '') + '</td>';
      html += '</tr>';
      html += '<tr class="lnkup-fin-revenue-detail" data-revenue-detail="' + idx + '" hidden><td colspan="6">' + paymentAuditBreakdown(audit) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  var REVENUE_PAGE_SIZE = 25;

  /** "Load more" for vendor revenue: re-renders the accumulated rows, newest paid first. */
  function bindRevenuePaging(panel, rows) {
    var btn = panel.querySelector('[data-fin-revenue-more]');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      var page = Number(btn.getAttribute('data-page')) || 2;
      btn.disabled = true;
      fetchFinancialJson('/transactions?limit=' + REVENUE_PAGE_SIZE + '&page=' + page).then(function (result) {
        if (!result.ok) {
          btn.disabled = false;
          finToast(result.error || 'Could not load more payments.', 'error');
          return;
        }
        var next = result.data || [];
        var all = rows.concat(next);
        panel.innerHTML = vendorRevenueTable(all) +
          (next.length === REVENUE_PAGE_SIZE ? '<p><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-fin-revenue-more data-page="' + (page + 1) + '">Load more</button></p>' : '');
        bindVendorRevenuePanel(panel);
        bindRevenuePaging(panel, all);
      });
    });
  }

  /** Ledger table cells: type, signed amount, payment/payout reference, note, date. */
  function ledgerRowCells(r) {
    var ref = r.reference || {};
    var parts = [];
    if (ref.payment_id) {
      parts.push(escapeHtml(ref.payment_title || ('Payment #' + ref.payment_id)) + (ref.paid_at ? ' <span class="lnkup-muted">paid ' + escapeHtml(String(ref.paid_at).slice(0, 10)) + '</span>' : ''));
    }
    if (ref.payout_id) {
      parts.push('Payout #' + escapeHtml(String(ref.payout_id)));
    }
    if (ref.charge_id || ref.intent_id) {
      parts.push('<code class="lnkup-fin-ref">' + escapeHtml(ref.charge_id || ref.intent_id) + '</code>');
    }
    return [
      escapeHtml(String(r.entry_type || '').replace(/_/g, ' ')),
      moneyFlowCell(r),
      parts.join('<br>') || '<span class="lnkup-muted">—</span>',
      escapeHtml(r.note || ''),
      escapeHtml((r.created_at || '').slice(0, 10))
    ];
  }

  /** "Load more" for the wallet ledger (GET /organizer-wallet/entries?page=N). */
  function bindLedgerPaging(panel) {
    var btn = panel.querySelector('[data-fin-ledger-more]');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      var page = Number(btn.getAttribute('data-page')) || 2;
      btn.disabled = true;
      fetchFinancialJson('/organizer-wallet/entries?per_page=50&page=' + page).then(function (result) {
        if (!result.ok) {
          btn.disabled = false;
          finToast(result.error || 'Could not load more ledger rows.', 'error');
          return;
        }
        var next = result.data || [];
        var body = panel.querySelector('[data-testid="lnkup-fin-ledger"] tbody');
        if (body) {
          next.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.innerHTML = ledgerRowCells(r).map(function (cell) { return '<td>' + cell + '</td>'; }).join('');
            body.appendChild(tr);
          });
        }
        if (next.length < 50) {
          btn.remove();
          return;
        }
        btn.disabled = false;
        btn.setAttribute('data-page', String(page + 1));
      });
    });
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
            finToast(String(res.error), 'error');
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
          finToast((err && err.message) || 'Could not reconcile payment.', 'error');
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
      if (dash.money && dash.money.metrics) {
        html += moneyContractGrid(dash.money);
      } else if (Array.isArray(dash.money_categories) && dash.money_categories.length) {
        html += moneyCategoryGrid(dash.money_categories, balances);
      } else {
        html += kpiGrid([
          { label: 'Collected', value: '$' + gross.toFixed(2) },
          { label: 'Available to cash out', value: '$' + balances.available.toFixed(2) },
          { label: 'Pending', value: '$' + balances.pending.toFixed(2) },
          { label: 'Paid out', value: '$' + Number(dash.payouts_paid || 0).toFixed(2) },
          { label: 'Refunds', value: '$' + Number(dash.refunds || 0).toFixed(2) }
        ]);
      }
      var byEvent = dash.charts && dash.charts.revenue_by_event ? dash.charts.revenue_by_event : [];
      if (byEvent.length) {
        html += eventBreakdownTable(byEvent);
      }
      if (balances.isDest && !balances.stripeSynced) {
        html += infoCard(t('account_balance', 'Account balance'), '<p>We could not read your payout balance from Stripe right now, so Available and Pending show as unavailable instead of $0.</p>', 'warn');
      } else if (balances.stripeSynced && !dash.money) {
        html += infoCard(t('account_balance', 'Account balance'), '<p>Available: <strong>$' + balances.available.toFixed(2) + '</strong> · Pending: <strong>$' + balances.pending.toFixed(2) + '</strong></p>', 'success');
      }
      html += '<div class="lnkup-fin-overview-cards">';
      html += payoutStatusCard(connect, balances.payouts);
      var cashoutReady = balances.payouts
        ? !!balances.payouts.can_cash_out
        : connect.status === 'active' && connect.payouts_enabled;
      var notReadyCopy = (balances.payouts && CASH_OUT_REASON_COPY[balances.payouts.cash_out_reason]) || 'Complete payout setup to cash out vendor revenue.';
      var cashoutBody = cashoutReady
        ? '<p class="lnkup-fin-cashout-ready">You can request a cashout from your available balance.</p>'
        : '<p class="lnkup-muted">' + escapeHtml(notReadyCopy) + '</p>';
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
          : 'Balances are still updating';
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
          startConnectOnboarding(reconnect, null);
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
        secondary: 'Vendor payments go straight to your payout account.'
      };
    }
    if (status === 'pending' || status === 'pending_verification') {
      return {
        title: 'Payout Setup',
        message: 'Stripe is reviewing your payout setup.',
        badge: 'Under Review',
        badgeMod: 'pending',
        secondary: ''
      };
    }
    if (status === 'rejected') {
      return {
        title: 'Payout Setup',
        message: 'Stripe could not approve this payout account. Contact support for help.',
        badge: 'Not Approved',
        badgeMod: 'muted',
        secondary: ''
      };
    }
    if (status === 'restricted' || status === 'requirements_due' || status === 'verification_required') {
      return {
        title: 'Payout Setup',
        message: 'Stripe needs more information before payouts can be enabled.',
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

  var PAYOUT_STATE_MOD = {
    active: 'success',
    setup_incomplete: 'warn',
    verifying: 'pending',
    action_required: 'warn',
    paused: 'muted',
    not_connected: 'warn',
    manual: 'muted'
  };

  /** Contract payout state wins over raw Connect status (it also checks settlement mode). */
  function payoutCopyFor(connect, payouts) {
    var copy = payoutStatusCopy(connect || {});
    if (payouts && payouts.state) {
      copy.badge = payouts.label || copy.badge;
      copy.badgeMod = PAYOUT_STATE_MOD[payouts.state] || copy.badgeMod;
      copy.secondary = payouts.detail || '';
      if (payouts.state !== 'active' && copy.badgeMod === 'success') {
        copy.badgeMod = 'warn';
      }
    }
    return copy;
  }

  function payoutBadgeChip(connect, payouts) {
    var copy = payoutCopyFor(connect, payouts);
    return '<span class="lnkup-fin-payout-badge lnkup-fin-payout-badge--' + escapeHtml(copy.badgeMod) + '" data-testid="lnkup-fin-payout-badge">' + escapeHtml(copy.badge) + '</span>';
  }

  function payoutStatusCard(connect, payouts) {
    var copy = payoutCopyFor(connect, payouts);
    var html = '<div class="lnkup-fin-payout-card lnkup-fin-payout-card--' + escapeHtml(copy.badgeMod) + '">';
    html += '<div class="lnkup-fin-payout-card__header"><h4 class="lnkup-fin-info-card__title">' + escapeHtml(copy.title) + '</h4>';
    html += payoutBadgeChip(connect, payouts) + '</div>';
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
          startConnectOnboarding(topBtn, null);
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
    // Strip ?connect= first so a reload never re-triggers the sync; keep ?view= and other params.
    if (window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.delete('connect');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
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
    }).catch(function (err) {
      console.error('LNKup connect refresh error:', err);
      loadOrganizerConnectBanner();
    });
  }

  /**
   * Show an inline payout-setup message next to the button (reuses an existing notice element).
   */
  function showConnectNotice(anchor, notice, message) {
    if (!notice && anchor) {
      var sibling = anchor.nextElementSibling;
      if (sibling && sibling.classList.contains('lnkup-fin-connect-notice')) {
        notice = sibling;
      } else {
        notice = document.createElement('p');
        notice.className = 'lnkup-fin-connect-notice';
        notice.setAttribute('role', 'alert');
        anchor.insertAdjacentElement('afterend', notice);
      }
    }
    if (!notice) {
      return;
    }
    notice.textContent = message;
    notice.hidden = false;
  }

  /**
   * Request a Connect onboarding link and redirect; disables the button while pending and
   * shows the server's friendly message inline on failure.
   */
  function startConnectOnboarding(btn, notice) {
    if (!btn || btn.disabled) {
      return;
    }
    var label = btn.textContent;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.textContent = t('payout_setup_opening', 'Opening payout setup…');
    finFetch('/connect/onboarding-link', { method: 'POST', body: {} }).then(function (res) {
      if (res && res.success && res.url) {
        window.location.href = res.url;
        return;
      }
      throw new Error((res && res.message) || CONNECT_ONBOARDING_ERROR_MSG);
    }).catch(function (err) {
      console.error('LNKup connect onboarding error:', err);
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.textContent = label;
      showConnectNotice(btn, notice, (err && err.message) || CONNECT_ONBOARDING_ERROR_MSG);
    });
  }

  function bindConnectOnboardButton() {
    var btn = document.getElementById('lnkup-connect-onboard');
    if (!btn || btn.dataset.bound) {
      return;
    }
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      startConnectOnboarding(btn, document.getElementById('lnkup-connect-notice'));
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
      var payouts = balances.payouts;
      var setupReady = payouts ? payouts.cash_out_reason !== 'payouts_not_active' : (connect.status === 'active' || balances.settlement === 'platform_treasury');
      var settling = balances.isDest && balances.stripeSynced && maxCashout <= 0 && Number(balances.pending) > 0;
      var balanceBlocked = balances.isDest && !balances.stripeSynced;
      var canStandard = payouts ? !!payouts.can_cash_out && maxCashout > 0 : (setupReady && maxCashout > 0 && !balanceBlocked);
      var instantEnabled = !!(connect.instant_payouts_enabled || (ajax.settings && ajax.settings.connect_instant_payouts_enabled));
      var stripeBal = (data && data.stripe_balance) || {};
      var instantMax = payouts && payouts.instant_available_cents !== undefined
        ? Math.max(0, Number(payouts.instant_available_cents) || 0) / 100
        : Math.max(0, Number(stripeBal.instant_available) || 0);
      var canInstant = instantEnabled && balances.isDest && (payouts ? !!payouts.can_instant_cash_out : (setupReady && !balanceBlocked && instantMax >= 1));
      var canCashout = canStandard || canInstant;
      var html = '';
      var reasonCopy = payouts && !payouts.can_cash_out && !canInstant ? CASH_OUT_REASON_COPY[payouts.cash_out_reason] : '';
      if (reasonCopy) {
        html += '<p class="lnkup-fin-connect-banner" data-testid="lnkup-cashout-reason">' + escapeHtml(reasonCopy) + '</p>';
      } else if (!setupReady && connect.available) {
        html += '<p class="lnkup-fin-connect-banner">Finish payout setup before cashing out.</p>';
      }
      if (!reasonCopy && balanceBlocked) {
        html += '<p class="lnkup-fin-connect-banner">Could not load your live Stripe payout balance. Refresh and try again.</p>';
      } else if (reasonCopy) {
        html += lastSyncedLine(balances.money);
      } else if (settling && canInstant) {
        html += '<p class="lnkup-muted">Standard payouts open once card payments finish processing. You can cash out up to $' + instantMax.toFixed(2) + ' now with Instant.</p>';
      } else if (settling) {
        html += '<p class="lnkup-muted">Card payments can take a few days to become available for payout.</p>';
      } else if (balances.isDest && balances.stripeSynced && Number(balances.platformRecoverable) > 0) {
        html += '<p class="lnkup-muted">Includes $' + Number(balances.platformRecoverable).toFixed(2) + ' held on LNKup that will move to your payout account when you cash out.</p>';
      } else if (balances.isDest && balances.stripeSynced && maxCashout <= 0) {
        html += '<p class="lnkup-muted">Available is your payout account balance. New card payments usually become available within a few days.</p>';
      } else if (balances.isDest && balances.stripeSynced) {
        html += '<p class="lnkup-muted">Max cashout uses your live available balance in destination mode.</p>';
      }
      html += '<form class="lnkup-fin-cashout-card" id="lnkup-cashout-form">' +
        '<p>Available <strong>' + escapeHtml(metricLabel(contractMetric(data, 'available'), maxCashout)) + '</strong></p>';
      var sourceBreakdown = formatSourceTypesBreakdown(balances.sourceTypes);
      if (sourceBreakdown) {
        html += '<p class="lnkup-muted">' + escapeHtml(sourceBreakdown) + '</p>';
      }
      if (instantEnabled && balances.isDest && balances.stripeSynced) {
        html += '<p data-testid="lnkup-cashout-instant-available">Instant available <strong>$' + instantMax.toFixed(2) + '</strong></p>';
      }
      var defaultInstant = !canStandard && canInstant;
      html += '<div class="lnkup-fin-cashout-card__choices">' +
        '<label><input type="radio" name="type" value="standard"' + (defaultInstant ? '' : ' checked') + (canStandard ? '' : ' disabled') + '> <span>Standard</span></label>';
      if (instantEnabled) {
        html += '<label><input type="radio" name="type" value="instant"' + (defaultInstant ? ' checked' : '') + (canInstant ? '' : ' disabled') + '> <span>Instant (1.5% fee)</span></label>';
      }
      html += '</div>' +
        '<label>Amount <input type="number" step="0.01" name="amount" required min="0.01" max="' + (defaultInstant ? instantMax : maxCashout) + '"' + (canCashout ? '' : ' disabled') + '></label>' +
        '<p class="lnkup-muted" id="lnkup-cashout-fee" hidden></p>' +
        '<button type="submit" class="lnkup-btn lnkup-btn--primary"' + (canCashout ? '' : ' disabled') + '>Cash out</button></form>';
      if (!instantEnabled) {
        html += '<p class="lnkup-muted">Standard payouts only. Instant payouts are not enabled for this marketplace.</p>';
      }
      html += '<p class="lnkup-muted"><a href="#" class="lnkup-fin-goto-payouts">View payout history →</a></p>';
      panel.innerHTML = html;
      bindSyncButtons(panel);
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
      var feeLine = form.querySelector('#lnkup-cashout-fee');
      var amountInput = form.querySelector('input[name="amount"]');
      function refreshFeeLine() {
        if (!feeLine || !amountInput) {
          return;
        }
        var chosen = form.querySelector('input[name="type"]:checked');
        var isInstant = chosen && chosen.value === 'instant';
        if (!isInstant) {
          feeLine.hidden = true;
          return;
        }
        var fee = Math.round(Number(amountInput.value || 0) * 1.5) / 100;
        feeLine.hidden = false;
        feeLine.textContent = 'Instant fee 1.5% · $' + fee.toFixed(2);
      }
      function selectedIsInstant() {
        var chosen = form.querySelector('input[name="type"]:checked');
        return !!(chosen && chosen.value === 'instant');
      }
      function handleTypeChange() {
        var isInstant = selectedIsInstant();
        if (amountInput) {
          amountInput.max = String(isInstant ? instantMax : maxCashout);
        }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
          btn.disabled = !(isInstant ? canInstant : canStandard);
        }
        refreshFeeLine();
      }
      form.querySelectorAll('input[name="type"]').forEach(function (input) {
        input.addEventListener('change', handleTypeChange);
      });
      if (amountInput) {
        amountInput.addEventListener('input', refreshFeeLine);
      }
      handleTypeChange();
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!(selectedIsInstant() ? canInstant : canStandard)) {
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
                if (!navigator.clipboard || !navigator.clipboard.writeText) {
                  copyBtn.textContent = 'Copy the link above';
                  return;
                }
                navigator.clipboard.writeText(shareUrl).then(function () {
                  copyBtn.textContent = 'Copied!';
                }).catch(function () {
                  copyBtn.textContent = 'Copy the link above';
                });
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
          if (!rows || !rows.length) {
            return '<p class="lnkup-empty-state__title">No receipts yet</p><p class="lnkup-muted">Payment receipts will appear here after you pay for events.</p>';
          }
          var html = '<div class="lnkup-fin-invoice-cards">';
          rows.forEach(function (r) {
            var title = r.event_title || r.receipt_number || 'Receipt';
            var view = r.view_url
              ? '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' + escapeHtml(r.view_url) + '" target="_blank" rel="noopener">View</a>'
              : '';
            html += '<article class="lnkup-fin-invoice-card"><div><p class="lnkup-type-label">' + escapeHtml(r.receipt_number || '') + '</p><h3>' + escapeHtml(title) + '</h3><p class="lnkup-type-meta">' + escapeHtml(r.payment_method || '') + ' · ' + escapeHtml((r.created_at || '').slice(0, 10)) + '</p></div><div class="lnkup-fin-invoice-card__side"><p>$' + Number(r.amount || 0).toFixed(2) + '</p>' + view + '</div></article>';
          });
          html += '</div>';
          return html;
        });
        return;
      }
    });
  }

  function initOrganizer() {
    var shell = document.getElementById('lnkup-organizer-financial');
    if (!shell || shell.getAttribute('data-lnkup-fin-ready') === '1') {
      return;
    }
    shell.setAttribute('data-lnkup-fin-ready', '1');
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
          html += lastSyncedLine(balances.money);
          html += kpiGrid([
            { label: 'Available', value: metricLabel(contractMetric(data, 'available'), balances.available) },
            { label: 'Pending', value: metricLabel(contractMetric(data, 'pending'), balances.pending) },
            { label: 'In transit', value: metricLabel(contractMetric(data, 'in_transit'), balances.processing) },
            { label: 'Paid out', value: metricLabel(contractMetric(data, 'paid_out'), (data.balances && data.balances.lifetime_paid) || 0) },
            { label: 'Reserve', value: metricLabel(contractMetric(data, 'reserve'), balances.reserve) },
            { label: 'LNKup settled', value: metricLabel(contractMetric(data, 'lnkup_settled'), balances.ledgerSettled) }
          ]);
          var held = contractMetric(data, 'platform_held');
          if (isMetricOk(held) && Number(held.cents) > 0) {
            var heldNext = balances.payouts && balances.payouts.state === 'active'
              ? 'moves to your payout account when you cash out.'
              : 'is paid out once you finish payout setup.';
            html += '<p class="lnkup-muted" data-testid="lnkup-fin-platform-held">$' + (Number(held.cents) / 100).toFixed(2) + ' from your bookings is held by LNKup and ' + heldNext + '</p>';
          }
          if (balances.stripeSynced && !balances.money) {
            html += infoCard(t('account_balance', 'Account balance'), '<p>Available: <strong>$' + balances.available.toFixed(2) + '</strong> · Pending: <strong>$' + Number(stripeBal.pending || balances.pending).toFixed(2) + '</strong></p>', 'success');
            if (Number(balances.platformRecoverable) > 0) {
              html += '<p class="lnkup-muted">Includes $' + Number(balances.platformRecoverable).toFixed(2) + ' held on LNKup (moves to your payout account on cash out).</p>';
            }
          }
          var connect = data.connect || {};
          html += '<h3>Connect status</h3>';
          html += payoutStatusCard(connect, balances.payouts);
          html += connectWalletBanner(connect);
          if (connect.status !== 'active' && connect.available) {
            html += '<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-connect-onboard">Finish payout setup</button>';
          }
          html += '<h3>Ledger</h3>';
          var entries = data.entries || [];
          html += '<div data-testid="lnkup-fin-ledger">' + tableFromRows(['Type', 'Amount', 'Reference', 'Note', 'Date'], entries.map(ledgerRowCells)) + '</div>';
          if (Number(data.entries_total || 0) > entries.length) {
            html += '<p><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-fin-ledger-more data-page="2">Load more</button></p>';
          }
          html += '</div>';
          setTimeout(function () {
            bindConnectOnboardButton();
            bindSyncButtons(panel);
            bindLedgerPaging(panel);
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
          fetchFinancialJson('/transactions?limit=' + REVENUE_PAGE_SIZE + '&page=1').then(function (result) {
            panel.dataset.loaded = '1';
            if (!result.ok) {
              failPanel(panel, result.error);
              return;
            }
            var rows = result.data || [];
            panel.innerHTML = vendorRevenueTable(rows) +
              (rows.length === REVENUE_PAGE_SIZE ? '<p><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-fin-revenue-more data-page="2">Load more</button></p>' : '');
            bindVendorRevenuePanel(panel);
            bindRevenuePaging(panel, rows);
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

  /**
   * Open the financial tab named by ?fin_tab= (disputes keeps its own handler).
   */
  function openFinTabFromQuery() {
    var key = new URLSearchParams(window.location.search).get('fin_tab') || '';
    if (!key || key === 'disputes' || !/^[a-z-]+$/.test(key)) {
      return;
    }
    var tab = document.querySelector('.lnkup-fin-tab[data-tab="' + key + '"]');
    if (!tab) {
      return;
    }
    var shell = tab.closest('.lnkup-fin-shell, #lnkup-organizer-financial, #lnkup-vendor-financial') || document;
    var section = shell.querySelector('.lnkup-fin-section[data-section="' + (tab.getAttribute('data-section') || '') + '"]');
    if (section) {
      section.click();
    }
    setTimeout(function () { tab.click(); }, 0);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!base) {
      return;
    }
    initVendor();
    initOrganizer();
    openFinTabFromQuery();
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
