(function () {
  'use strict';

  var ajax = window.lnkupAjax || {};
  var nonce = ajax.restNonce || '';

  function restBase() {
    var base = (ajax.connectorRestUrl || ajax.restUrl || '').replace(/\/marketplace\/?$/, '');
    if (!base) base = '/wp-json/lnkup/v1';
    return String(base).replace(/\/?$/, '/');
  }

  function money(n) {
    var v = Number(n) || 0;
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(v);
    } catch (e) {
      return '$' + v.toFixed(2);
    }
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatDate(iso) {
    if (!iso) return '—';
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return escapeHtml(iso);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function referralEarningsLabel(r) {
    var earned = Number(r.earned_total) || 0;
    var lifecycle =
      r.lifecycle ||
      (r.status !== 'active' ? 'ended' : earned > 0 ? 'earning' : 'awaiting_payment');
    if (lifecycle === 'awaiting_payment') {
      return 'Signed up · awaiting first booth payment';
    }
    if (lifecycle === 'ended' || r.status !== 'active') {
      return 'Ended ' + formatDate(r.expires_at) + ' · ' + money(earned);
    }
    return 'Earns until ' + formatDate(r.expires_at) + ' · ' + money(earned);
  }

  function fetchDashboard() {
    return fetch(restBase() + 'referrals/organizer-recruit', {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-WP-Nonce': nonce,
      },
    }).then(function (res) {
      if (!res.ok) throw new Error('Could not load referrals');
      return res.json();
    });
  }

  function ensureCode() {
    return fetch(restBase() + 'referrals/organizer-recruit/code', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      body: '{}',
    }).then(function (res) {
      if (!res.ok) throw new Error('Could not create code');
      return res.json();
    });
  }

  function render(mount, data) {
    var shareOrg = data.share_url_organizer || data.share_url || '';
    var shareVendor = data.share_url_vendor || '';
    var code = data.code || '';
    var html = '';
    html += '<div class="lnkup-org-recruit__share">';
    html += '<p class="lnkup-org-recruit__headline">' + escapeHtml(data.headline || '') + '</p>';
    html += '<p class="lnkup-muted">' + escapeHtml(data.message || '') + '</p>';
    html += '<div class="lnkup-org-recruit__code-row">';
    html += '<code class="lnkup-org-recruit__code" data-testid="lnkup-org-recruit-code">' + escapeHtml(code || '—') + '</code>';
    if (!shareOrg && !shareVendor) {
      html +=
        '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-generate="1" data-testid="lnkup-org-recruit-generate">Get invite links</button>';
    }
    html += '</div>';
    if (shareOrg || shareVendor) {
      html += '<div class="lnkup-org-recruit__dual">';
      if (shareOrg) {
        html += '<div class="lnkup-org-recruit__link-block">';
        html += '<p class="lnkup-org-recruit__link-label"><strong>Invite organizer</strong></p>';
        html +=
          '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-copy="' +
          escapeHtml(shareOrg) +
          '" data-testid="lnkup-org-recruit-copy-organizer">Copy organizer link</button>';
        html +=
          '<p class="lnkup-org-recruit__url lnkup-muted" data-testid="lnkup-org-recruit-url-organizer">' +
          escapeHtml(shareOrg) +
          '</p>';
        html += '</div>';
      }
      if (shareVendor) {
        html += '<div class="lnkup-org-recruit__link-block">';
        html += '<p class="lnkup-org-recruit__link-label"><strong>Invite vendor</strong></p>';
        html +=
          '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-copy="' +
          escapeHtml(shareVendor) +
          '" data-testid="lnkup-org-recruit-copy-vendor">Copy vendor link</button>';
        html +=
          '<p class="lnkup-org-recruit__url lnkup-muted" data-testid="lnkup-org-recruit-url-vendor">' +
          escapeHtml(shareVendor) +
          '</p>';
        html += '</div>';
      }
      html += '</div>';
    }
    html +=
      '<p class="lnkup-org-recruit__earnings"><strong>' +
      money(data.earnings_total) +
      '</strong> earned · ' +
      escapeHtml(String(data.active_count || 0)) +
      ' active invites</p>';
    html += '</div>';

    var refs = data.referrals || [];
    html += '<div class="lnkup-org-recruit__list">';
    html += '<h3 class="lnkup-org-recruit__list-title">Your referrals</h3>';
    if (!refs.length) {
      html +=
        '<p class="lnkup-muted" data-testid="lnkup-org-recruit-empty">No referrals yet. Share an organizer or vendor link — recruits appear here as soon as they sign up, before their first booth payment.</p>';
    } else {
      html += '<ul class="lnkup-org-recruit__items">';
      refs.forEach(function (r) {
        var status = r.status === 'active' ? 'Active' : 'Ended';
        var role = r.target_role === 'vendor' ? 'Vendor' : 'Organizer';
        html += '<li class="lnkup-org-recruit__item">';
        html +=
          '<div><strong>' +
          escapeHtml(r.referred_name || 'Member') +
          '</strong><span class="lnkup-org-recruit__badge lnkup-org-recruit__badge--' +
          escapeHtml(r.status || '') +
          '">' +
          status +
          '</span> <span class="lnkup-muted">' +
          role +
          '</span></div>';
        html +=
          '<div class="lnkup-muted">' + escapeHtml(referralEarningsLabel(r)) + '</div>';
        html += '</li>';
      });
      html += '</ul>';
    }
    html += '</div>';

    mount.innerHTML = html;

    mount.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || '';
        if (!text || !navigator.clipboard) return;
        var original = btn.textContent;
        navigator.clipboard.writeText(text).then(function () {
          btn.textContent = 'Copied';
          setTimeout(function () {
            btn.textContent = original;
          }, 1600);
        });
      });
    });

    mount.querySelectorAll('[data-generate]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        ensureCode()
          .then(function () {
            return fetchDashboard();
          })
          .then(function (fresh) {
            render(mount, fresh);
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Try again';
          });
      });
    });
  }

  function showLoadError(mount, root) {
    if (window.LNKupUI && window.LNKupUI.panelError) {
      window.LNKupUI.panelError(mount, {
        title: 'We couldn\u2019t load your invite link.',
        desc: 'Check your connection and try again.',
        onRetry: function () {
          boot(root);
        }
      });
      return;
    }
    mount.innerHTML =
      '<p class="lnkup-alert lnkup-alert--error">We couldn\u2019t load your invite link. Refresh and try again.</p>';
  }

  function boot(root) {
    var mount = root.querySelector('[data-org-recruit-mount]');
    if (!mount) return;
    if (window.LNKupUI && window.LNKupUI.panelSkeleton && mount.dataset.loaded === '1') {
      window.LNKupUI.panelSkeleton(mount, 1);
    }
    fetchDashboard()
      .then(function (data) {
        if (!data.code) {
          return ensureCode().then(function () {
            return fetchDashboard();
          });
        }
        return data;
      })
      .then(function (data) {
        render(mount, data);
      })
      .catch(function (error) {
        console.error('LNKup referrals: could not load dashboard', error);
        showLoadError(mount, root);
      });
  }

  function init() {
    document.querySelectorAll('.lnkup-org-recruit').forEach(boot);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
