/**
 * Organizer Requests: filter auto-submit and bulk Accept / Waitlist / Decline.
 */
(function () {
  'use strict';

  var STATUS = { approve: 'approved', waitlist: 'waitlisted', reject: 'rejected' };
  var LABEL = { approve: 'Approved', waitlist: 'Waitlisted', reject: 'Rejected' };

  function t(key, fallback) {
    return (window.LNKupUI && typeof window.LNKupUI.t === 'function') ? window.LNKupUI.t(key, fallback) : fallback;
  }

  function toast(message, type) {
    if (typeof window.lnkupShowToast === 'function') {
      window.lnkupShowToast(message, type);
      return;
    }
    var region = document.querySelector('[data-request-bulk-count]');
    if (region) {
      region.textContent = message;
    }
  }

  function selectedBoxes() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-request-select]:checked'));
  }

  function syncBar() {
    var bar = document.querySelector('[data-request-bulk]');
    if (!bar) {
      return;
    }
    var count = selectedBoxes().length;
    bar.hidden = count === 0;
    var label = bar.querySelector('[data-request-bulk-count]');
    if (label) {
      label.textContent = count === 1 ? t('one_selected', '1 selected') : count + ' ' + t('selected', 'selected');
    }
    Array.prototype.forEach.call(bar.querySelectorAll('[data-request-bulk-action]'), function (btn) {
      btn.disabled = count === 0;
      disarm(btn);
    });
  }

  function disarm(btn) {
    if (btn.classList.contains('is-armed')) {
      btn.classList.remove('is-armed');
      btn.textContent = btn.getAttribute('data-label') || btn.textContent;
    }
  }

  function arm(btn, count) {
    if (!btn.getAttribute('data-label')) {
      btn.setAttribute('data-label', btn.textContent);
    }
    btn.classList.add('is-armed');
    btn.textContent = t('confirm', 'Confirm') + ' (' + count + ')';
    window.setTimeout(function () { disarm(btn); }, 4000);
  }

  function markCard(appId, action) {
    var card = document.querySelector('.lnkup-request-row[data-app-id="' + appId + '"]');
    if (!card) {
      return;
    }
    var badge = card.querySelector('.lnkup-status');
    if (badge) {
      badge.className = 'lnkup-status lnkup-status--' + STATUS[action];
      badge.textContent = LABEL[action];
    }
    var fromStatus = card.getAttribute('data-org-app-status') || '';
    card.setAttribute('data-org-app-status', STATUS[action]);
    Array.prototype.forEach.call(card.querySelectorAll('.lnkup-review-btn, .lnkup-review-reject, .lnkup-request-row__select, [data-request-info]'), function (el) {
      el.remove();
    });
    document.dispatchEvent(new CustomEvent('lnkup-request-updated', { detail: { appId: appId, status: STATUS[action], from: fromStatus } }));
  }

  /** Filter tabs a status counts toward (mirrors LNKup_Organizer_Dashboard_Views::status_map). */
  var TAB_KEYS = {
    submitted: ['pending'],
    under_review: ['pending'],
    info_requested: ['needs_info'],
    approved: ['approved', 'unpaid'],
    payment_required: ['approved', 'unpaid'],
    paid: ['paid'],
    completed: ['paid'],
    waitlisted: ['waitlisted'],
    rejected: ['rejected']
  };

  function bumpTab(key, delta) {
    var tab = document.querySelector('.lnkup-organizer-app-filters a[href*="filter=' + key + '"]');
    if (!tab) {
      return;
    }
    var badge = tab.querySelector('.lnkup-dash-tabs__badge');
    var next = Math.max(0, (badge ? parseInt(badge.textContent, 10) || 0 : 0) + delta);
    if (!badge && next > 0) {
      badge = document.createElement('span');
      badge.className = 'lnkup-dash-tabs__badge';
      tab.appendChild(document.createTextNode(' '));
      tab.appendChild(badge);
    }
    if (badge) {
      badge.textContent = String(next);
      badge.hidden = next === 0;
    }
  }

  function syncTabCounts(e) {
    var detail = (e && e.detail) || {};
    if (!detail.status || detail.status === detail.from) {
      return;
    }
    (TAB_KEYS[detail.from] || []).forEach(function (key) { bumpTab(key, -1); });
    (TAB_KEYS[detail.status] || []).forEach(function (key) { bumpTab(key, 1); });
  }

  function runBulk(action, bar) {
    var boxes = selectedBoxes();
    if (!boxes.length || !window.lnkupAjax || !window.lnkupAjax.crmRestUrl || !window.LNKupUI) {
      return;
    }
    var groups = {};
    boxes.forEach(function (box) {
      var eventId = box.getAttribute('data-event-id');
      (groups[eventId] = groups[eventId] || []).push(parseInt(box.value, 10));
    });
    var buttons = bar.querySelectorAll('button');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    bar.setAttribute('aria-busy', 'true');

    var requests = Object.keys(groups).map(function (eventId) {
      var ids = groups[eventId];
      return window.LNKupUI.fetchJson(window.lnkupAjax.crmRestUrl + '/events/' + encodeURIComponent(eventId) + '/roster/bulk', {
        method: 'POST',
        body: { application_ids: ids, action: action }
      }).then(function (res) {
        return { ids: ids, failed: (res && res.failed) ? res.failed.length : 0 };
      }).catch(function () {
        return { ids: ids, failed: ids.length };
      });
    });

    Promise.all(requests).then(function (results) {
      var total = 0;
      var failed = 0;
      results.forEach(function (r) {
        total += r.ids.length;
        failed += r.failed;
        if (!r.failed) {
          r.ids.forEach(function (id) { markCard(id, action); });
        }
      });
      bar.removeAttribute('aria-busy');
      if (failed) {
        toast(failed + ' ' + t('bulk_failed', 'requests could not be updated. Refreshing…'), 'error');
        window.setTimeout(function () { window.location.reload(); }, 1500);
        return;
      }
      toast(total + ' ' + t('bulk_updated', 'requests updated.'), 'success');
      syncBar();
    });
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-request-select]')) {
      syncBar();
    }
  });

  function sendDocReminder(btn) {
    var appId = btn.getAttribute('data-remind-docs');
    var status = btn.parentElement ? btn.parentElement.querySelector('[data-remind-status]') : null;
    if (!appId || !window.lnkupAjax) {
      return;
    }
    btn.disabled = true;
    if (status) {
      status.textContent = t('sending', 'Sending…');
    }
    var data = new FormData();
    data.append('action', 'lnkup_remind_vendor_documents');
    data.append('application_id', appId);
    data.append('lnkup_nonce', window.lnkupAjax.reviewNonce || '');
    fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) {
        var type = r.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          throw new Error('bad_response');
        }
        return r.json();
      })
      .then(function (res) {
        var message = (res && res.data && res.data.message) ? res.data.message : '';
        if (res && res.success) {
          btn.textContent = message || t('reminder_sent', 'Reminder sent');
          if (status) {
            status.textContent = '';
          }
          return;
        }
        if (status) {
          status.textContent = message || t('reminder_failed', 'Could not send the reminder.');
        }
        btn.disabled = !!(res && res.data && res.data.code === 'rate_limited');
        if (!btn.disabled) {
          return;
        }
        btn.textContent = t('reminder_sent_today', 'Sent today');
      })
      .catch(function () {
        btn.disabled = false;
        if (status) {
          status.textContent = t('reminder_failed', 'Could not send the reminder. Try again.');
        }
      });
  }

  document.addEventListener('click', function (e) {
    var remind = e.target.closest('[data-remind-docs]');
    if (remind && !remind.disabled) {
      sendDocReminder(remind);
      return;
    }
    var clear = e.target.closest('[data-request-bulk-clear]');
    if (clear) {
      selectedBoxes().forEach(function (box) { box.checked = false; });
      syncBar();
      return;
    }
    var btn = e.target.closest('[data-request-bulk-action]');
    if (!btn || btn.disabled) {
      return;
    }
    var action = btn.getAttribute('data-request-bulk-action');
    if (!STATUS[action]) {
      return;
    }
    if (action !== 'waitlist' && !btn.classList.contains('is-armed')) {
      arm(btn, selectedBoxes().length);
      return;
    }
    disarm(btn);
    runBulk(action, btn.closest('[data-request-bulk]'));
  });

  document.addEventListener('lnkup-request-updated', syncBar);
  document.addEventListener('lnkup-request-updated', syncTabCounts);
  document.addEventListener('DOMContentLoaded', syncBar);
})();
