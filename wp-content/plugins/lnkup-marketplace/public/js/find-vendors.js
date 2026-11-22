/**
 * Organizer dashboard: Find Vendors (matching), recruitment assistant, shortlist and batch invites,
 * plus the Tours & series manager. Data: lnkup/v1/match/organizer/* and lnkup/v1/tours.
 * Never sends anything without an explicit organizer click.
 */
(function () {
  var root = document.getElementById('lnkup-tours-manager');
  var form = document.getElementById('lnkup-tour-form');
  if (!root || !form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = form.querySelector('[data-tour-error]');
    var ids = Array.prototype.map.call(form.querySelectorAll('input[name="event_ids[]"]:checked'), function (el) {
      return Number(el.value);
    });
    if (ids.length < 2) {
      err.textContent = 'Choose at least two events.';
      err.hidden = false;
      return;
    }
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    err.hidden = true;
    fetch(root.getAttribute('data-tours-rest'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (window.lnkupAjax && window.lnkupAjax.restNonce) || '' },
      body: JSON.stringify({
        title: form.querySelector('[name="title"]').value,
        type: form.querySelector('[name="type"]').value,
        status: 'publish',
        event_ids: ids
      })
    }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('application/json') === -1) throw new Error('Something went wrong. Refresh and try again.');
      return r.json().then(function (body) {
        if (!r.ok) throw new Error((body && body.message) || 'Request failed');
        return body;
      });
    }).then(function () {
      window.location.reload();
    }).catch(function (e2) {
      console.error('[LNKup tours]', e2);
      err.textContent = e2.message || 'Could not create the tour.';
      err.hidden = false;
      btn.disabled = false;
    });
  });
})();

(function () {
  var root = document.getElementById('lnkup-find-vendors');
  if (!root) return;
  var ajax = window.lnkupAjax || {};
  var base = root.getAttribute('data-match-rest') || ajax.matchRestUrl || '';
  var nonce = ajax.restNonce || '';
  var eventSelect = document.getElementById('lnkup-find-vendors-event');
  var categorySelect = document.getElementById('lnkup-find-vendors-category');
  var verifiedBox = document.getElementById('lnkup-find-vendors-verified');
  var results = document.getElementById('lnkup-find-vendors-results');
  var assistant = document.getElementById('lnkup-find-vendors-assistant');
  var composer = document.getElementById('lnkup-find-vendors-composer');
  var previewBox = document.getElementById('lnkup-find-vendors-preview');
  var statusEl = document.getElementById('lnkup-find-vendors-status');
  var messageEl = document.getElementById('lnkup-find-vendors-message');
  if (!eventSelect || !results || !base) return;

  var selected = {};
  var preview = null;
  var STAGES = {
    shortlisted: 'Shortlisted', invited: 'Invited', opened: 'Opened invite', applied: 'Applied',
    approved: 'Approved', paid: 'Booked', declined: 'Declined'
  };

  function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function toast(message, type) {
    if (window.lnkupShowToast) window.lnkupShowToast(message, type || 'success');
  }

  function api(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options)).then(function (r) {
      var type = r.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        throw new Error('Something went wrong. Refresh and try again.');
      }
      return r.json().then(function (body) {
        if (!r.ok) {
          var err = new Error((body && body.message) || 'Request failed');
          err.status = r.status;
          throw err;
        }
        return body;
      });
    });
  }

  function panel(type, title, desc) {
    var cls = type === 'error' ? 'lnkup-alert lnkup-alert--error' : 'lnkup-empty-state lnkup-empty-state--compact';
    return '<div class="' + cls + '"><p class="lnkup-empty-state__title">' + escapeHtml(title) + '</p>' +
      (desc ? '<p class="lnkup-empty-state__desc">' + escapeHtml(desc) + '</p>' : '') + '</div>';
  }

  function selectedIds() {
    return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number);
  }

  function syncComposer() {
    var count = selectedIds().length;
    composer.hidden = count === 0;
    var counter = composer.querySelector('[data-selected-count]');
    if (counter) counter.textContent = String(count);
    preview = null;
    previewBox.hidden = true;
    composer.querySelector('[data-fv-send]').hidden = true;
    composer.querySelector('[data-fv-preview]').hidden = false;
  }

  function renderCard(v) {
    var location = [v.city, v.state].filter(Boolean).join(', ');
    var reasons = (v.match && v.match.reasons ? v.match.reasons : []).slice(0, 3).map(function (r) {
      return '<li>' + escapeHtml(r) + '</li>';
    }).join('');
    var stage = STAGES[v.pipeline_stage] ? '<span class="lnkup-badge lnkup-badge--outline">' + escapeHtml(STAGES[v.pipeline_stage]) + '</span>' : '';
    var invites = v.accept_invites === false ? '<span class="lnkup-badge lnkup-badge--outline">Not accepting new invites</span>' : '';
    var events = v.completed_events > 0 ? '<span class="lnkup-muted">' + escapeHtml(v.completed_events + ' event' + (v.completed_events === 1 ? '' : 's') + ' on LNKup') + '</span>' : '';
    var logo = v.logo
      ? '<img src="' + escapeHtml(v.logo) + '" alt="" class="lnkup-vendor-card__avatar" />'
      : '<span class="lnkup-vendor-card__avatar lnkup-crm-card__avatar--placeholder">' + escapeHtml(String(v.business_name || 'V').slice(0, 2).toUpperCase()) + '</span>';
    var checked = selected[v.profile_id] ? ' checked' : '';
    return '<article class="lnkup-vendor-card lnkup-crm-card" data-testid="lnkup-find-vendor-card" data-profile-id="' + escapeHtml(v.profile_id) + '">' +
      '<div class="lnkup-vendor-card__header">' + logo + '<div><h3 class="lnkup-vendor-card__name">' + escapeHtml(v.business_name) +
      (v.verified ? ' <span class="lnkup-badge lnkup-badge--success">Verified</span>' : '') + '</h3>' +
      '<p class="lnkup-muted">' + escapeHtml([v.primary_category, location].filter(Boolean).join(' · ')) + '</p></div></div>' +
      (v.description ? '<p class="lnkup-muted">' + escapeHtml(v.description) + '</p>' : '') +
      (reasons ? '<ul class="lnkup-find-vendors__reasons">' + reasons + '</ul>' : '') +
      '<div class="lnkup-vendor-card__chips">' + stage + invites + events + '</div>' +
      '<label class="lnkup-form-row--checkbox"><input type="checkbox" data-fv-select="' + escapeHtml(v.profile_id) + '"' + checked + ' /> Select</label>' +
      '</article>';
  }

  function loadAssistant(eventId) {
    if (!assistant) return;
    assistant.innerHTML = '';
    api('/organizer/events/' + eventId + '/recruitment').then(function (data) {
      var tips = (data && data.suggestions) || [];
      if (!tips.length) return;
      assistant.innerHTML = '<ul class="lnkup-find-vendors__tips" data-testid="lnkup-recruitment-assistant">' + tips.slice(0, 4).map(function (s) {
        return '<li><strong>' + escapeHtml(s.title) + '</strong><br><span class="lnkup-muted">' + escapeHtml(s.message) + '</span></li>';
      }).join('') + '</ul>';
    }).catch(function (err) {
      console.error('[LNKup Find Vendors] assistant', err);
    });
  }

  function load() {
    var eventId = eventSelect.value;
    if (!eventId) return;
    selected = {};
    syncComposer();
    results.innerHTML = '<div class="lnkup-crm-skeleton" aria-hidden="true"></div>';
    var qs = '?exclude_applied=1';
    if (categorySelect && categorySelect.value) qs += '&category=' + encodeURIComponent(categorySelect.value);
    if (verifiedBox && verifiedBox.checked) qs += '&verified=1';
    loadAssistant(eventId);
    api('/organizer/events/' + eventId + '/vendors' + qs).then(function (data) {
      if (!data || data.enabled === false) {
        results.innerHTML = panel('empty', 'Vendor recommendations are not available yet.', '');
        return;
      }
      if (data.needs_open_opportunity) {
        results.innerHTML = panel('empty', 'Open a vendor slot', 'Add an open vendor slot with available spaces to see matching vendors.');
        return;
      }
      var items = data.items || [];
      if (!items.length) {
        results.innerHTML = panel('empty', 'No matching vendors yet', 'Add vendor categories and an address to your event, or share your application link.');
        return;
      }
      results.innerHTML = items.map(renderCard).join('');
      if (statusEl) statusEl.textContent = data.total + ' matching vendor' + (data.total === 1 ? '' : 's');
    }).catch(function (err) {
      console.error('[LNKup Find Vendors]', err);
      results.innerHTML = panel('error', 'We couldn\u2019t load matching vendors.', 'Try refreshing the page.');
    });
  }

  results.addEventListener('change', function (e) {
    var box = e.target.closest('[data-fv-select]');
    if (!box) return;
    selected[box.getAttribute('data-fv-select')] = box.checked;
    syncComposer();
  });

  composer.addEventListener('click', function (e) {
    var eventId = Number(eventSelect.value);
    var ids = selectedIds();
    if (!ids.length) return;
    var button = e.target.closest('button');
    if (!button) return;
    if (button.hasAttribute('data-fv-shortlist')) {
      button.disabled = true;
      api('/organizer/recruitment/shortlist', { method: 'POST', body: { event_id: eventId, profile_ids: ids, action: 'add' } })
        .then(function () { toast('Added to your shortlist.'); load(); })
        .catch(function (err) { toast(err.message || 'Could not update your shortlist.', 'error'); })
        .then(function () { button.disabled = false; });
    } else if (button.hasAttribute('data-fv-preview')) {
      button.disabled = true;
      api('/organizer/invites/preview', { method: 'POST', body: { event_id: eventId, profile_ids: ids, message: messageEl ? messageEl.value : '' } })
        .then(function (data) {
          preview = data;
          var suppressed = (data.suppressed || []).map(function (s) {
            return '<li>' + escapeHtml(s.business_name + ': ' + s.reason_label) + '</li>';
          }).join('');
          previewBox.innerHTML = '<p><strong>' + escapeHtml(data.template ? data.template.subject : '') + '</strong></p>' +
            '<p class="lnkup-muted" style="white-space:pre-line">' + escapeHtml(data.template ? data.template.body : '') + '</p>' +
            '<p>Will invite ' + (data.recipients || []).length + ' vendor' + ((data.recipients || []).length === 1 ? '' : 's') + '.</p>' +
            (suppressed ? '<p class="lnkup-muted">Not sent:</p><ul>' + suppressed + '</ul>' : '');
          previewBox.hidden = false;
          composer.querySelector('[data-fv-send]').hidden = !(data.recipients || []).length;
          button.hidden = true;
        })
        .catch(function (err) { toast(err.message || 'Could not preview invites.', 'error'); })
        .then(function () { button.disabled = false; });
    } else if (button.hasAttribute('data-fv-send') && preview) {
      var recipients = (preview.recipients || []).map(function (r) { return r.profile_id; });
      if (!recipients.length) return;
      button.disabled = true;
      api('/organizer/invites/batch', { method: 'POST', body: { event_id: eventId, profile_ids: recipients, message: messageEl ? messageEl.value : '' } })
        .then(function (job) {
          toast('Sending ' + job.total + ' invite' + (job.total === 1 ? '' : 's') + '.');
          if (statusEl) statusEl.textContent = 'Invites are being sent. Vendors you invite still need to apply.';
          load();
        })
        .catch(function (err) { toast(err.message || 'Could not send invites.', 'error'); })
        .then(function () { button.disabled = false; });
    }
  });

  eventSelect.addEventListener('change', load);
  if (categorySelect) categorySelect.addEventListener('change', load);
  if (verifiedBox) verifiedBox.addEventListener('change', load);
  load();
})();
