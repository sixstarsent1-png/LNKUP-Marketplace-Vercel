(function () {
  var eventSubmitting = false;
  var oppSubmitting = false;

  document.addEventListener('click', function (e) {
    var passToggle = e.target.closest('[data-toggle-password]');
    if (!passToggle) {
      return;
    }
    var input = document.getElementById(passToggle.getAttribute('data-toggle-password') || '');
    if (!input) {
      return;
    }
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    passToggle.textContent = show ? 'Hide' : 'Show';
    passToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  function copyText(value, successMessage) {
    var text = String(value || '');
    if (!text) {
      showToast('Nothing to copy.', 'error');
      return;
    }
    var done = function () {
      showToast(successMessage || 'Copied.', 'success');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        fallbackCopy(text, done);
      });
      return;
    }
    fallbackCopy(text, done);
  }

  function fallbackCopy(text, done) {
    var input = document.createElement('textarea');
    input.value = text;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
      done();
    } catch (err) {
      showToast('Could not copy the link.', 'error');
    }
    input.remove();
  }

  function showToast(message, type) {
    var toast = document.createElement('div');
    toast.className = 'lnkup-toast lnkup-toast--' + (type || 'success');
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });
    setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  }

  window.lnkupShowToast = showToast;

  function escapeLaunchHtml(str) {
    if (!str) {
      return '';
    }
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function generateClientToken() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }
    return 'tok-' + Date.now() + '-' + Math.random().toString(36).slice(2);
  }

  /**
   * Event saved but vendor slots failed. Retry resubmits the same form with the same client_token,
   * so the server returns the existing event and only creates the missing slots.
   */
  function showPartialCreateWarning(form, errBox, data, msg) {
    if (!errBox) {
      showToast(msg, 'error');
      return;
    }
    var viewUrl = data.view_event_url ? String(data.view_event_url) : '';
    errBox.innerHTML =
      '<div class="lnkup-alert lnkup-alert--warning" data-testid="lnkup-create-partial">' +
      '<p><strong>Your event was saved.</strong> ' + escapeLaunchHtml(msg) + '</p>' +
      '<p class="lnkup-create-partial__actions">' +
      '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-retry-vendor-slots>Retry vendor slots</button> ' +
      (viewUrl ? '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' + escapeLaunchHtml(viewUrl) + '">Open event</a>' : '') +
      '</p></div>';
    errBox.hidden = false;
    var retry = errBox.querySelector('[data-retry-vendor-slots]');
    if (retry) {
      retry.addEventListener('click', function () {
        errBox.hidden = true;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
      }, { once: true });
    }
  }

  function bindSaveEventDraft() {
    var btn = document.querySelector('[data-save-event-draft]');
    var form = document.getElementById('lnkup-create-event-form');
    if (!btn || !form) {
      return;
    }
    btn.addEventListener('click', function () {
      var mode = document.getElementById('lnkup_event_save_mode');
      var openApps = document.getElementById('lnkup_open_vendor_applications');
      var name = form.querySelector('[name="event_name"]');
      if (name && !name.value.trim()) {
        name.reportValidity();
        return;
      }
      if (mode) {
        mode.value = 'draft';
      }
      if (openApps && openApps.checked) {
        openApps.checked = false;
        openApps.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      }
    });
  }

  /** Next Create Event submission is a new event; retries of the previous one keep the old token. */
  function renewEventClientToken() {
    var field = document.getElementById('lnkup_event_client_token');
    if (field) {
      field.value = generateClientToken();
      field.defaultValue = field.value;
    }
  }

  function setEventSubmitting(active) {
    eventSubmitting = active;
    document.querySelectorAll('#lnkup-create-event-submit, #lnkup-create-event-submit-footer').forEach(function (btn) {
      btn.disabled = active;
      btn.setAttribute('aria-busy', active ? 'true' : 'false');
    });
  }

  function setOppSubmitting(active) {
    oppSubmitting = active;
    var btn = document.getElementById('lnkup-publish-opportunity-btn');
    var loading = document.getElementById('lnkup-onboarding-opp-loading');
    if (btn) {
      btn.disabled = active;
      btn.setAttribute('aria-busy', active ? 'true' : 'false');
    }
    if (loading) {
      loading.hidden = !active;
    }
  }

  /**
   * Full-width launch success card after event publish or opportunity publish.
   */
  function showLaunchSuccess(data, msg) {
    var root = document.getElementById('lnkup-launch-success-root');
    var createWrap = document.getElementById('lnkup-create-panel-wrap');
    var createPanel = document.getElementById('lnkup-create-panel');
    if (!root || !data.event_id) {
      return false;
    }
    var title = data.event_title || 'Your event';
    var meta = [];
    if (data.event_date) {
      meta.push(data.event_date);
    }
    if (data.event_city) {
      meta.push(data.event_city);
    }
    var fee = data.vendor_fee ? '$' + parseFloat(data.vendor_fee).toFixed(2) : '';
    var spots = data.spots_total ? data.spots_total + ' spots' : '';
    var hasOpp = !!data.opportunity_id;
    var badge = hasOpp ? 'Event & opportunity live' : 'Event live';
    var html = '<div class="lnkup-launch-success__hero">';
    html += '<p class="lnkup-launch-success__badge">' + escapeLaunchHtml(badge) + '</p>';
    html += '<h3 class="lnkup-launch-success__title">' + escapeLaunchHtml(title) + '</h3>';
    if (meta.length) {
      html += '<p class="lnkup-muted">' + escapeLaunchHtml(meta.join(' · ')) + '</p>';
    }
    if (hasOpp && (fee || spots)) {
      html += '<p class="lnkup-launch-success__meta">' + escapeLaunchHtml([fee, spots].filter(Boolean).join(' · ')) + '</p>';
    }
    html += '<p class="lnkup-alert lnkup-alert--success">' + escapeLaunchHtml(data.message || msg) + '</p>';
    html += '<div class="lnkup-launch-success__actions">';
    if (hasOpp) {
      html += '<button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-launch-invite-vendors" data-event-id="' + data.event_id + '" data-opp-id="' + data.opportunity_id + '">Invite vendors</button>';
    }
    if (data.find_events_url && hasOpp) {
      html += '<a class="lnkup-btn lnkup-btn--outline" href="' + escapeLaunchHtml(data.find_events_url) + '">View in Find Events</a>';
    }
    if (data.view_event_url) {
      html += '<a class="lnkup-btn lnkup-btn--' + (hasOpp ? 'ghost' : 'primary') + '" href="' + escapeLaunchHtml(data.view_event_url) + '">View events</a>';
    }
    if (data.manage_applications_url && hasOpp) {
      html += '<a class="lnkup-btn lnkup-btn--ghost" href="' + escapeLaunchHtml(data.manage_applications_url) + '">Manage applications</a>';
    }
    if (data.operations_url && hasOpp) {
      html += '<a class="lnkup-btn lnkup-btn--ghost" href="' + escapeLaunchHtml(data.operations_url) + '">Open operations</a>';
    }
    html += '<button type="button" class="lnkup-btn lnkup-btn--ghost" id="lnkup-launch-another">Launch another event</button>';
    html += '</div></div>';
    root.innerHTML = html;
    root.hidden = false;
    if (createWrap) {
      createWrap.classList.add('is-published');
    }
    if (createPanel) {
      createPanel.classList.add('is-published');
    }
    var onboarding = document.getElementById('lnkup-vendor-onboarding-step');
    if (onboarding) {
      onboarding.hidden = true;
    }
    var eventPhase = document.getElementById('lnkup-event-create-phase');
    if (eventPhase) {
      eventPhase.hidden = true;
    }
    var launcher = document.getElementById('lnkup-create-launcher');
    if (launcher) {
      launcher.hidden = true;
    }
    var inviteBtn = document.getElementById('lnkup-launch-invite-vendors');
    if (inviteBtn) {
      inviteBtn.addEventListener('click', function () {
        if (window.lnkupOpenInviteModal) {
          window.lnkupOpenInviteModal({
            prefillEventId: parseInt(inviteBtn.getAttribute('data-event-id'), 10),
            prefillOpportunityId: parseInt(inviteBtn.getAttribute('data-opp-id'), 10)
          });
        } else if (data.invite_vendors_url) {
          window.location.href = data.invite_vendors_url;
        }
      });
    }
    var anotherBtn = document.getElementById('lnkup-launch-another');
    if (anotherBtn) {
      anotherBtn.addEventListener('click', function () {
        root.hidden = true;
        root.innerHTML = '';
        window.lnkupLastCreatedEvent = null;
        if (createWrap) {
          createWrap.classList.remove('is-published');
          createWrap.hidden = false;
        }
        if (createPanel) {
          createPanel.classList.remove('is-published');
        }
        if (eventPhase) {
          eventPhase.hidden = false;
        }
        if (onboarding) {
          onboarding.hidden = true;
        }
        if (launcher) {
          launcher.hidden = false;
        }
        var eventForm = document.getElementById('lnkup-create-event-form');
        if (eventForm) {
          eventForm.reset();
          renewEventClientToken();
        }
        var oppForm = document.getElementById('lnkup-create-first-opportunity-form');
        if (oppForm) {
          oppForm.reset();
          oppForm.hidden = true;
        }
        var choices = document.querySelector('.lnkup-onboarding-choices');
        if (choices) {
          choices.hidden = false;
        }
        syncPublishSummary();
        if (window.lnkupCrmInvalidateInviteOptions) {
          window.lnkupCrmInvalidateInviteOptions();
        }
      });
    }
    showToast(data.message || msg, 'success');
    if (window.lnkupRefreshLaunchChecklist) {
      window.lnkupRefreshLaunchChecklist();
    }
    return true;
  }

  /**
   * Show post-event vendor onboarding step.
   */
  function showVendorOnboardingStep(data) {
    var eventPhase = document.getElementById('lnkup-event-create-phase');
    var onboarding = document.getElementById('lnkup-vendor-onboarding-step');
    var oppForm = document.getElementById('lnkup-create-first-opportunity-form');
    if (!onboarding || !data.event_id) {
      return false;
    }
    window.lnkupLastCreatedEvent = data;
    if (eventPhase) {
      eventPhase.hidden = true;
    }
    onboarding.hidden = false;
    var titleEl = document.getElementById('lnkup-onboarding-event-title');
    if (titleEl) {
      titleEl.textContent = data.event_title || '';
    }
    var eventIdField = document.getElementById('lnkup_onboarding_event_id');
    if (eventIdField) {
      eventIdField.value = String(data.event_id);
    }
    var tokenField = document.getElementById('lnkup_opp_client_token');
    if (tokenField) {
      tokenField.value = generateClientToken();
    }
    var oppTitle = document.getElementById('opportunity_title');
    if (oppTitle && data.event_title) {
      oppTitle.placeholder = 'Vendors for ' + data.event_title;
    }
    var attendance = document.getElementById('onboarding_expected_attendance');
    var eventAttendance = document.getElementById('expected_attendance');
    if (attendance && eventAttendance && eventAttendance.value) {
      attendance.value = eventAttendance.value;
    }
    if (oppForm) {
      oppForm.hidden = true;
    }
    var choices = document.querySelector('.lnkup-onboarding-choices');
    if (choices) {
      choices.hidden = false;
    }
    var errEl = document.getElementById('lnkup-onboarding-opp-error');
    if (errEl) {
      errEl.hidden = true;
      errEl.innerHTML = '';
    }
    onboarding.scrollIntoView({ behavior: 'smooth', block: 'start' });
    showToast(data.message || 'Event created.', 'success');
    return true;
  }

  function postForm(form, extraHeaders) {
    var data = new FormData(form);
    // Prefer form nonce (action-specific, e.g. lnkup_event). Never overwrite with lnkupAjax.nonce (lnkup_action).
    var headers = extraHeaders || {};
    var ajaxUrl = (window.lnkupAjax && window.lnkupAjax.url) || '';
    if (!ajaxUrl) {
      return Promise.reject(Object.assign(new Error('missing_ajax_url'), { nonJson: true, status: 0 }));
    }
    return fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: headers })
      .then(function (r) {
        return r.text().then(function (text) {
          var trimmed = (text || '').replace(/^\uFEFF/, '').trim();
          // Strip leading PHP notices/warnings before JSON object/array.
          var jsonStart = trimmed.search(/[\{\[]/);
          var candidate = jsonStart > 0 ? trimmed.slice(jsonStart) : trimmed;
          try {
            return JSON.parse(candidate);
          } catch (parseErr) {
            var err = new Error('non_json');
            err.status = r.status;
            err.nonJson = true;
            err.bodyPreview = trimmed.replace(/\s+/g, ' ').slice(0, 160);
            throw err;
          }
        });
      });
  }

  /**
   * Validate create-event form; jump wizard to first invalid step.
   * @param {HTMLFormElement} form
   * @param {HTMLElement|null} errBox
   * @return {boolean}
   */
  function validateCreateEventForm(form, errBox) {
    var finder = window.lnkupOrganizerFirstInvalidCreateField;
    var hit = typeof finder === 'function' ? finder() : null;
    if (!hit) {
      // Fallback: scan form required fields (skip disabled).
      var fields = form.querySelectorAll('input, select, textarea');
      for (var i = 0; i < fields.length; i++) {
        var el = fields[i];
        if (el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') {
          continue;
        }
        if (typeof el.checkValidity === 'function' && !el.checkValidity()) {
          hit = { field: el, stepId: null };
          var section = el.closest('.lnkup-organizer-create-section');
          if (section && section.id) {
            hit.stepId = section.id;
          }
          break;
        }
      }
    }
    if (!hit || !hit.field) {
      return true;
    }
    if (hit.stepId && typeof window.lnkupOrganizerSetWizardStep === 'function') {
      window.lnkupOrganizerSetWizardStep(hit.stepId);
    }
    var msg = hit.field.validationMessage || 'Please complete the required fields before publishing.';
    if (errBox) {
      errBox.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeLaunchHtml(msg) + '</p>';
      errBox.hidden = false;
    }
    showToast(msg, 'error');
    try {
      if (typeof hit.field.reportValidity === 'function') {
        hit.field.reportValidity();
      }
      hit.field.focus();
    } catch (e) {
      // ignore
    }
    return false;
  }

  function ajaxFailureMessage(err) {
    if (!err) {
      return 'Something went wrong. Please try again.';
    }
    if (err.nonJson) {
      if (err.bodyPreview && typeof console !== 'undefined' && console.warn) {
        console.warn('LNKup AJAX non-JSON response', err.status, err.bodyPreview);
      }
      if (err.status === 403 || err.status === 401) {
        return 'Session expired or permission denied — refresh the page and try again.';
      }
      var preview = (err.bodyPreview || '').toLowerCase();
      if (preview.indexOf('security') !== -1 || preview.indexOf('permission') !== -1 || preview.indexOf('forbidden') !== -1) {
        return 'Session expired or permission denied — refresh the page and try again.';
      }
      var plain = (err.bodyPreview || '')
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 140);
      if (err.status === 500 || err.status === 0 || (plain && plain.length > 8)) {
        var statusBit = err.status ? ' (HTTP ' + err.status + ')' : '';
        if (plain && plain.length > 8) {
          return 'Server error while publishing' + statusBit + ': ' + plain;
        }
        return 'Server error while publishing' + statusBit + ' — refresh the page and try again.';
      }
      if (err.bodyPreview && err.bodyPreview.length > 8 && err.bodyPreview.indexOf('<') === -1) {
        return 'Could not publish: ' + err.bodyPreview;
      }
      return 'Could not reach the server with a valid response — refresh the page and try again.';
    }
    if (err.message && err.message !== 'non_json' && err.message !== 'missing_ajax_url') {
      return err.message;
    }
    return 'Something went wrong. Please try again.';
  }

  function showApplySuccess(msg) {
    var form = document.querySelector('.lnkup-apply-form');
    var success = document.getElementById('lnkup-apply-success');
    if (form) {
      form.hidden = true;
    }
    if (success) {
      success.hidden = false;
      var live = success.querySelector('[aria-live]');
      if (live) {
        var announced = live.innerHTML;
        live.innerHTML = '';
        live.innerHTML = announced;
      }
    }
    var oppId = form ? form.getAttribute('data-opportunity-id') : '';
    if (oppId) {
      var requestsUrl = (success && success.querySelector('a[href]')) ? success.querySelector('a[href]').getAttribute('href') : '';
      var applyPattern = new RegExp('[?&]apply=' + oppId + '(&|$)');
      document.querySelectorAll('a[href*="apply="]').forEach(function (link) {
        if (link.closest('#lnkup-apply-modal') || !applyPattern.test(link.getAttribute('href') || '')) {
          return;
        }
        var label = (window.LNKupUI && LNKupUI.applicationStatusLabel) ? LNKupUI.applicationStatusLabel('submitted') : 'Submitted';
        link.textContent = 'Application: ' + label;
        link.classList.remove('lnkup-btn--primary');
        link.classList.add('lnkup-btn--secondary', 'lnkup-event-card__status');
        if (requestsUrl) {
          link.setAttribute('href', requestsUrl);
        } else {
          link.setAttribute('aria-disabled', 'true');
        }
      });
    }
    showToast(msg, 'success');
  }

  var DOC_MAX_BYTES = 10 * 1024 * 1024;
  var DOC_EXTENSIONS = /\.(pdf|jpe?g|png)$/i;

  /**
   * Upload the vendor document form with progress feedback. Never leaves the form stuck:
   * every outcome re-enables the input and reports a friendly message.
   */
  function uploadDocumentForm(form) {
    if (!form || form.dataset.uploading === '1') {
      return;
    }
    var fileInput = form.querySelector('input[type="file"]');
    var file = fileInput && fileInput.files ? fileInput.files[0] : null;
    if (!file) {
      showToast('Choose a file to upload.', 'error');
      return;
    }
    if (!DOC_EXTENSIONS.test(file.name || '')) {
      showToast('Upload a PDF, JPG, or PNG file.', 'error');
      fileInput.value = '';
      return;
    }
    if (file.size > DOC_MAX_BYTES) {
      showToast('Documents must be under 10 MB.', 'error');
      fileInput.value = '';
      return;
    }
    var ajaxUrl = (window.lnkupAjax && window.lnkupAjax.url) || '';
    if (!ajaxUrl) {
      showToast('Upload is unavailable right now. Refresh and try again.', 'error');
      return;
    }

    var status = form.querySelector('.lnkup-doc-upload-status');
    if (!status) {
      status = document.createElement('div');
      status.className = 'lnkup-doc-upload-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      form.appendChild(status);
    }
    status.hidden = false;
    status.innerHTML = '<span class="lnkup-doc-upload-status__label"></span><progress max="100" value="0"></progress>';
    var label = status.querySelector('.lnkup-doc-upload-status__label');
    var bar = status.querySelector('progress');
    label.textContent = 'Uploading ' + file.name + '…';

    form.dataset.uploading = '1';
    fileInput.disabled = true;
    var data = new FormData(form);
    data.set(fileInput.name || 'lnkup_document', file);

    var done = function (ok, message) {
      form.dataset.uploading = '';
      fileInput.disabled = false;
      if (!ok) {
        fileInput.value = '';
        status.hidden = true;
      } else {
        label.textContent = message;
        bar.value = 100;
      }
      showToast(message, ok ? 'success' : 'error');
      if (ok) {
        window.setTimeout(function () { window.location.reload(); }, 600);
      }
    };

    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxUrl, true);
    xhr.withCredentials = true;
    xhr.upload.addEventListener('progress', function (evt) {
      if (evt.lengthComputable) {
        bar.value = Math.round((evt.loaded / evt.total) * 100);
      }
    });
    xhr.addEventListener('load', function () {
      var res = null;
      try {
        var text = (xhr.responseText || '').replace(/^\uFEFF/, '').trim();
        var start = text.search(/[\{\[]/);
        res = JSON.parse(start > 0 ? text.slice(start) : text);
      } catch (err) {
        res = null;
      }
      if (res && res.success) {
        done(true, (res.data && res.data.message) || 'Document uploaded. It will show as pending until reviewed.');
        return;
      }
      done(false, (res && res.data && res.data.message) || 'Upload failed. Try again.');
    });
    xhr.addEventListener('error', function () {
      done(false, 'Upload failed — check your connection and try again.');
    });
    xhr.addEventListener('abort', function () {
      done(false, 'Upload cancelled.');
    });
    xhr.send(data);
  }

  window.lnkupUploadDocumentForm = uploadDocumentForm;

  function submitApplyForm(form) {
    if (form.dataset.submitting === '1') {
      return Promise.resolve();
    }
    form.dataset.submitting = '1';
    var submitBtn = form.querySelector('[data-testid="lnkup-apply-submit"], button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-busy', 'true');
    }

    var finish = function () {
      form.dataset.submitting = '';
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
      }
    };

    var ajax = window.lnkupAjax || {};
    var restBase = ajax.applicationsRestUrl;
    var oppInput = form.querySelector('[name="opportunity_id"]');
    var categoryEl = form.querySelector('[name="selected_category"]');
    var messageEl = form.querySelector('[name="message"]');
    var setupEl = form.querySelector('[name="setup_notes"]');
    var termsEl = form.querySelector('[name="accepted_terms"]');
    var inviteEl = form.querySelector('[name="invite_token"]');
    var payload = {
      opportunity_id: parseInt(form.getAttribute('data-opportunity-id') || (oppInput ? oppInput.value : '0'), 10),
      selected_category: categoryEl ? categoryEl.value : '',
      application_message: messageEl ? messageEl.value : '',
      setup_notes: setupEl ? setupEl.value : '',
      accepted_terms: !!(termsEl && termsEl.checked),
      invite_token: inviteEl ? inviteEl.value : ''
    };

    if (restBase) {
      return fetch(restBase + '/apply-with-profile', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': ajax.restNonce || ''
        },
        body: JSON.stringify(payload)
      }).then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
          throw new Error('invalid_response');
        }
        return r.json().then(function (body) {
          return { ok: r.ok, status: r.status, body: body };
        });
      }).then(function (res) {
        var body = res.body || {};
        if (res.ok && body.success) {
          showApplySuccess(body.message || 'Application submitted.');
          return;
        }
        if (res.status === 409 && body.code === 'duplicate') {
          showApplySuccess(body.message || 'You already applied to this opportunity.');
          return;
        }
        var errMsg = body.message || (body.data && body.data.message) || 'Unable to submit application.';
        showToast(errMsg, 'error');
      }).catch(function () {
        showToast('Unable to submit application.', 'error');
      }).finally(finish);
    }

    return postForm(form).then(function (res) {
      var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Application submitted.' : 'Error');
      if (res.success) {
        showApplySuccess(msg);
      } else {
        showToast(msg, 'error');
      }
    }).catch(function () {
      showToast('Unable to submit application.', 'error');
    }).finally(finish);
  }

  function handleOnboardingSkip() {
    var data = window.lnkupLastCreatedEvent;
    if (!data) {
      return;
    }
    var skipData = Object.assign({}, data, {
      message: 'Event created. You can add vendor opportunities anytime.',
      opportunity_id: 0
    });
    showLaunchSuccess(skipData, skipData.message);
  }

  function handleOnboardingAccept() {
    var oppForm = document.getElementById('lnkup-create-first-opportunity-form');
    var choices = document.querySelector('.lnkup-onboarding-choices');
    if (!oppForm) {
      return;
    }
    if (choices) {
      choices.hidden = true;
    }
    oppForm.hidden = false;
    var data = window.lnkupLastCreatedEvent;
    var oppTitle = document.getElementById('opportunity_title');
    if (oppTitle && data && data.event_title && !oppTitle.value) {
      oppTitle.value = 'Vendors for ' + data.event_title;
    }
    var firstInput = oppForm.querySelector('input:not([type="hidden"]), select, textarea');
    if (firstInput) {
      firstInput.focus();
    }
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.classList.contains('lnkup-profile-section-form')) {
      return;
    }
    if (!form.classList.contains('lnkup-profile-form') &&
        !form.classList.contains('lnkup-apply-form') &&
        !form.classList.contains('lnkup-event-form') &&
        !form.classList.contains('lnkup-opportunity-form')) {
      if (form.classList.contains('lnkup-edit-event-form') || form.classList.contains('lnkup-organizer-profile-form')) {
        e.preventDefault();
        postForm(form).then(function (res) {
          var savedMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Saved.' : 'Could not save.');
          showToast(savedMsg, res.success ? 'success' : 'error');
          if (res.success && form.classList.contains('lnkup-edit-event-form')) {
            window.location.hash = 'events';
            window.location.reload();
          } else if (res.success) {
            form.querySelectorAll('[data-image-status]').forEach(function (status) {
              status.textContent = '';
            });
          }
        }).catch(function () {
          showToast('Could not save.', 'error');
        });
        return;
      }
      if (form.classList.contains('lnkup-document-form')) {
        e.preventDefault();
        uploadDocumentForm(form);
      }
      return;
    }
    e.preventDefault();

    var errBox = null;
    if (form.classList.contains('lnkup-event-form')) {
      if (eventSubmitting) {
        return;
      }
      errBox = document.getElementById('lnkup-create-event-error');
      if (errBox) {
        errBox.hidden = true;
      }
      if (form.id === 'lnkup-create-event-form' && !validateCreateEventForm(form, errBox)) {
        return;
      }
      if (form.id === 'lnkup-create-event-form') {
        collectFeeRowsToJson(form);
      }
      setEventSubmitting(true);
    }

    if (form.id === 'lnkup-create-first-opportunity-form') {
      if (oppSubmitting) {
        return;
      }
      setOppSubmitting(true);
      var oppErr = document.getElementById('lnkup-onboarding-opp-error');
      if (oppErr) {
        oppErr.hidden = true;
      }
    }

    var extraHeaders = {};
    if (form.getAttribute('data-test-block-opp') === '1') {
      extraHeaders['X-LNKup-Test-Block-Opp'] = '1';
    }

    if (form.classList.contains('lnkup-apply-form')) {
      submitApplyForm(form);
      return;
    }

    postForm(form, extraHeaders).then(function (res) {
      var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Saved.' : 'Error');

      if (form.id === 'lnkup-create-first-opportunity-form') {
        setOppSubmitting(false);
        if (res.success && res.data) {
          var merged = Object.assign({}, window.lnkupLastCreatedEvent || {}, res.data);
          showLaunchSuccess(merged, msg);
        } else {
          var errEl = document.getElementById('lnkup-onboarding-opp-error');
          if (errEl) {
            errEl.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeLaunchHtml(msg) + '</p>';
            errEl.hidden = false;
          }
          showToast(msg, 'error');
        }
        return;
      }

      if (form.classList.contains('lnkup-add-slot-form')) {
        if (res.success) {
          showToast(msg, 'success');
          window.location.hash = 'events';
          window.location.reload();
        } else {
          showToast(msg, 'error');
        }
        return;
      }

      if (form.classList.contains('lnkup-opportunity-form')) {
        if (res.success) {
          var oppSuccess = document.getElementById('lnkup-create-opportunity-success');
          if (oppSuccess) {
            oppSuccess.innerHTML = '<p class="lnkup-alert lnkup-alert--success">' + escapeLaunchHtml(msg) + '</p>';
            oppSuccess.hidden = false;
          }
          showToast(msg, 'success');
          if (form.id !== 'lnkup-create-first-opportunity-form') {
            form.reset();
          }
        } else {
          showToast(msg, 'error');
        }
        return;
      }

      if (form.classList.contains('lnkup-event-form')) {
        setEventSubmitting(false);
        if (!res.success) {
          if (errBox) {
            errBox.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeLaunchHtml(msg) + '</p>';
            errBox.hidden = false;
          }
          showToast(msg, 'error');
          return;
        }
        if (res.data && res.data.draft) {
          showToast(msg, 'success');
          if (res.data.view_event_url) {
            window.location.href = res.data.view_event_url;
          }
          return;
        }
        if (res.data && res.data.partial) {
          showPartialCreateWarning(form, errBox, res.data, msg);
          return;
        }
        // Opportunity already created with the event — skip Accept-vendors onboarding.
        if (res.data && (res.data.opportunity_id || res.data.opportunity_created)) {
          if (res.data && showLaunchSuccess(res.data, msg)) {
            return;
          }
          showToast(msg, 'success');
          return;
        }
        if (res.data && res.data.onboarding) {
          showVendorOnboardingStep(res.data);
          return;
        }
        if (res.data && showLaunchSuccess(res.data, msg)) {
          return;
        }
        showToast(msg, 'success');
      }
    }).catch(function (err) {
      setEventSubmitting(false);
      setOppSubmitting(false);
      var failMsg = ajaxFailureMessage(err);
      if (form.classList.contains('lnkup-event-form')) {
        var eventErr = document.getElementById('lnkup-create-event-error');
        if (eventErr) {
          eventErr.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + escapeLaunchHtml(failMsg) + '</p>';
          eventErr.hidden = false;
        }
      }
      showToast(failMsg, 'error');
    });
  });

  document.addEventListener('click', function (e) {
    var pricingSave = e.target.closest('.lnkup-opp-pricing-save');
    if (pricingSave) {
      e.preventDefault();
      var oppId = pricingSave.getAttribute('data-opportunity-id');
      var row = pricingSave.closest('[data-slot-row]') || pricingSave.closest('tr[data-opportunity-id]');
      if (!oppId || !row) {
        return;
      }
      var feeInput = row.querySelector('.lnkup-opp-fee-input');
      var spotsInput = row.querySelector('.lnkup-opp-spots-input');
      var nonceInput = row.parentElement ? row.parentElement.querySelector('input[name="lnkup_nonce"]') : null;
      if (!nonceInput) {
        nonceInput = document.querySelector('#lnkup-create-opportunity-form input[name="lnkup_nonce"], .lnkup-add-slot-form input[name="lnkup_nonce"]');
      }
      var nonce = (window.lnkupAjax && window.lnkupAjax.opportunityNonce) ? window.lnkupAjax.opportunityNonce : '';
      if (!nonce && nonceInput) {
        nonce = nonceInput.value;
      }
      if (!nonce) {
        showToast('Could not verify session. Refresh and try again.', 'error');
        return;
      }
      var spotsVal = spotsInput ? String(spotsInput.value || '').trim() : '';
      if (!spotsVal || Number(spotsVal) < 1) {
        showToast('At least one vendor spot is required.', 'error');
        return;
      }
      var data = new FormData();
      data.append('action', 'lnkup_update_opportunity_pricing');
      data.append('opportunity_id', oppId);
      data.append('vendor_fee', feeInput ? feeInput.value : '');
      data.append('spots_total', spotsVal);
      var titleInput = row.querySelector('.lnkup-slot-title-input');
      if (titleInput && String(titleInput.value || '').trim()) {
        data.append('slot_title', String(titleInput.value).trim());
      }
      data.append('lnkup_nonce', nonce);
      pricingSave.disabled = true;
      fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (text) {
          var res;
          try {
            res = JSON.parse((text || '').replace(/^\uFEFF/, '').trim());
          } catch (err) {
            throw new Error('non_json');
          }
          var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Updated.' : 'Could not update.');
          showToast(msg, res.success ? 'success' : 'error');
        })
        .catch(function () {
          showToast('Could not update fee and spots. Refresh and try again.', 'error');
        })
        .then(function () {
          pricingSave.disabled = false;
        });
      return;
    }

    var deleteSlot = e.target.closest('[data-delete-slot]');
    if (deleteSlot) {
      e.preventDefault();
      if (!window.confirm('Remove this vendor slot?')) {
        return;
      }
      var deleteId = deleteSlot.getAttribute('data-delete-slot');
      var panel = deleteSlot.closest('[data-slot-panel]');
      var deleteNonce = panel ? panel.querySelector('input[name="lnkup_nonce"]') : null;
      var nonceValue = deleteNonce ? deleteNonce.value : ((window.lnkupAjax && window.lnkupAjax.opportunityNonce) ? window.lnkupAjax.opportunityNonce : '');
      if (!deleteId || !nonceValue || !window.lnkupAjax || !window.lnkupAjax.url) {
        showToast('Could not verify session. Refresh and try again.', 'error');
        return;
      }
      var deleteData = new FormData();
      deleteData.append('action', 'lnkup_delete_opportunity');
      deleteData.append('opportunity_id', deleteId);
      deleteData.append('lnkup_nonce', nonceValue);
      deleteSlot.disabled = true;
      fetch(window.lnkupAjax.url, { method: 'POST', body: deleteData, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var deleteMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Vendor slot removed.' : 'Could not remove this vendor slot.');
          showToast(deleteMsg, res.success ? 'success' : 'error');
          if (res.success) {
            window.location.hash = 'events';
            window.location.reload();
          } else {
            deleteSlot.disabled = false;
          }
        })
        .catch(function () {
          deleteSlot.disabled = false;
          showToast('Could not remove this vendor slot.', 'error');
        });
      return;
    }

    var statusBtn = e.target.closest('[data-slot-status]');
    if (statusBtn) {
      e.preventDefault();
      var nextStatus = statusBtn.getAttribute('data-slot-status');
      var statusOpp = statusBtn.getAttribute('data-opportunity-id');
      var statusPanel = statusBtn.closest('[data-slot-panel]');
      var statusNonceEl = statusPanel ? statusPanel.querySelector('input[name="lnkup_nonce"]') : null;
      var statusNonce = statusNonceEl ? statusNonceEl.value : ((window.lnkupAjax && window.lnkupAjax.opportunityNonce) ? window.lnkupAjax.opportunityNonce : '');
      if (!nextStatus || !statusOpp || !statusNonce || !window.lnkupAjax || !window.lnkupAjax.url) {
        showToast('Could not verify session. Refresh and try again.', 'error');
        return;
      }
      var statusData = new FormData();
      statusData.append('action', 'lnkup_set_opportunity_status');
      statusData.append('opportunity_id', statusOpp);
      statusData.append('slot_status', nextStatus);
      statusData.append('lnkup_nonce', statusNonce);
      statusBtn.disabled = true;
      fetch(window.lnkupAjax.url, { method: 'POST', body: statusData, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var statusMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Updated.' : 'Could not update the vendor slot.');
          showToast(statusMsg, res.success ? 'success' : 'error');
          if (res.success) {
            window.location.hash = 'events';
            window.location.reload();
          } else {
            statusBtn.disabled = false;
          }
        })
        .catch(function () {
          statusBtn.disabled = false;
          showToast('Could not update the vendor slot.', 'error');
        });
      return;
    }

    var choiceBtn = e.target.closest('[data-onboarding-choice]');
    if (choiceBtn) {
      e.preventDefault();
      var choice = choiceBtn.getAttribute('data-onboarding-choice');
      if (choice === 'skip') {
        handleOnboardingSkip();
      } else if (choice === 'accept') {
        handleOnboardingAccept();
      }
      return;
    }

    var payBtn = e.target.closest('.lnkup-pay-btn');
    if (payBtn && window.lnkupCheckout && window.lnkupCheckout.open) {
      e.preventDefault();
      window.lnkupCheckout.open(payBtn.getAttribute('data-payment-id'));
      return;
    }
    if (payBtn) {
      var paymentId = payBtn.getAttribute('data-payment-id');
      var data = new FormData();
      data.append('action', 'lnkup_create_checkout');
      data.append('payment_id', paymentId);
      data.append('lnkup_nonce', window.lnkupAjax.nonce);
      data.append('mode', 'embedded');
      fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success && res.data.url) {
            window.location.href = res.data.url;
          } else if (!window.lnkupCheckout) {
            alert(res.data && res.data.message ? res.data.message : 'Payment error');
          }
        });
      return;
    }

    var docUploadBtn = e.target.closest('[data-doc-upload]');
    if (docUploadBtn && !document.getElementById('lnkup-vendor-profile-editor')) {
      e.preventDefault();
      var docType = docUploadBtn.getAttribute('data-doc-upload');
      var docTypeField = document.getElementById('document_type');
      var docFile = document.getElementById('lnkup_document');
      if (docTypeField && docFile) {
        docTypeField.value = docType;
        docFile.click();
      }
      return;
    }

    var rejectCancel = e.target.closest('[data-review-reject-cancel]');
    if (rejectCancel) {
      var cancelForm = rejectCancel.closest('.lnkup-review-reject');
      if (cancelForm) {
        var cancelCard = cancelForm.parentElement;
        cancelForm.remove();
        var declineBtn = cancelCard && cancelCard.querySelector('.lnkup-review-btn--decline');
        if (declineBtn) declineBtn.focus();
      }
      return;
    }

    var rejectConfirm = e.target.closest('[data-review-reject-confirm]');
    if (rejectConfirm) {
      var rejectForm = rejectConfirm.closest('.lnkup-review-reject');
      var reasonField = rejectForm ? rejectForm.querySelector('textarea') : null;
      var sourceBtn = rejectForm ? rejectForm.parentElement.querySelector('.lnkup-review-btn--decline') : null;
      if (sourceBtn) {
        rejectConfirm.disabled = true;
        submitReview(sourceBtn, 'reject', reasonField ? reasonField.value.trim() : '').then(function () {
          if (rejectForm) rejectForm.remove();
        });
      }
      return;
    }

    var infoBtn = e.target.closest('[data-request-info]');
    if (infoBtn) {
      openInfoRequestForm(infoBtn);
      return;
    }
    var infoCancel = e.target.closest('[data-info-request-cancel]');
    if (infoCancel) {
      var openForm = infoCancel.closest('.lnkup-info-request-compose');
      if (openForm) {
        openForm.remove();
      }
      return;
    }

    var reviewBtn = e.target.closest('.lnkup-review-btn');
    if (reviewBtn) {
      var action = reviewBtn.getAttribute('data-action');
      if (action === 'approve' && !reviewBtn.classList.contains('is-armed')) {
        armReviewButton(reviewBtn);
        return;
      }
      if (action === 'reject') {
        openRejectForm(reviewBtn);
        return;
      }
      submitReview(reviewBtn, action, '');
    }
  });

  document.addEventListener('change', function (e) {
    var field = e.target.closest && e.target.closest('form[data-auto-submit] [data-auto-submit-field]');
    if (field && field.form) {
      field.form.submit();
    }
  });

  /**
   * POST JSON to an lnkup/v1 URL; resolves with the parsed body or rejects with a user-facing message.
   *
   * @param {string} url  Absolute REST URL.
   * @param {Object} body JSON body.
   * @returns {Promise<Object>}
   */
  function restPostJson(url, body) {
    var nonce = (window.lnkupAjax && window.lnkupAjax.restNonce) || '';
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(body || {}),
    }).then(function (r) {
      var type = r.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        throw new Error('Something went wrong. Refresh and try again.');
      }
      return r.json().then(function (data) {
        if (!r.ok) {
          throw new Error((data && data.message) ? String(data.message) : 'Something went wrong. Try again.');
        }
        return data;
      });
    });
  }

  var INFO_DOC_TYPES = ['Insurance certificate', 'Business license', 'Health permit', 'Product photos'];

  /**
   * Inline "Ask for info" composer under an organizer request card.
   *
   * @param {HTMLButtonElement} btn Ask-for-info button.
   */
  function openInfoRequestForm(btn) {
    var card = btn.closest('.lnkup-crm-app-card--v2, .lnkup-crm-app-card, .lnkup-request-row');
    if (!card || card.querySelector('.lnkup-info-request-compose')) {
      return;
    }
    var appId = btn.getAttribute('data-app-id');
    var form = document.createElement('form');
    form.className = 'lnkup-info-request-compose lnkup-review-reject';
    form.setAttribute('data-testid', 'lnkup-org-info-compose');
    var fieldId = 'lnkup-info-msg-' + appId;
    var docs = INFO_DOC_TYPES.map(function (doc, i) {
      return '<label class="lnkup-info-request-compose__doc"><input type="checkbox" name="documents" value="' + escapeLaunchHtml(doc) + '" id="lnkup-info-doc-' + appId + '-' + i + '" /> ' + escapeLaunchHtml(doc) + '</label>';
    }).join('');
    form.innerHTML =
      '<label for="' + fieldId + '">What do you need from this vendor?</label>' +
      '<textarea id="' + fieldId + '" name="message" rows="3" required minlength="3" maxlength="2000"></textarea>' +
      '<fieldset class="lnkup-info-request-compose__docs"><legend>Ask for documents (optional)</legend>' + docs + '</fieldset>' +
      '<p class="lnkup-info-request__status" role="status" aria-live="polite" hidden></p>' +
      '<div class="lnkup-review-reject__actions">' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-info-request-cancel>Cancel</button>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-testid="lnkup-org-info-send">Send question</button>' +
      '</div>';
    card.appendChild(form);
    form.querySelector('textarea').focus();
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var message = form.querySelector('textarea').value.trim();
      var status = form.querySelector('.lnkup-info-request__status');
      var send = form.querySelector('[type="submit"]');
      var picked = Array.prototype.map.call(form.querySelectorAll('input[name="documents"]:checked'), function (el) { return el.value; });
      if (message.length < 3) {
        status.textContent = 'Tell the vendor what you need.';
        status.hidden = false;
        return;
      }
      send.disabled = true;
      restPostJson(btn.getAttribute('data-endpoint'), { message: message, documents: picked })
        .then(function (data) {
          var badge = card.querySelector('.lnkup-status');
          if (badge) {
            badge.className = 'lnkup-status lnkup-status--info_requested';
            badge.textContent = data.status_label || 'More info needed';
          }
          var fromStatus = card.getAttribute('data-org-app-status') || '';
          card.setAttribute('data-org-app-status', 'info_requested');
          btn.remove();
          form.remove();
          showToast('Question sent. The vendor has been notified.', 'success');
          document.dispatchEvent(new CustomEvent('lnkup-request-updated', { detail: { appId: appId, status: 'info_requested', from: fromStatus } }));
        })
        .catch(function (err) {
          status.textContent = err.message;
          status.hidden = false;
          send.disabled = false;
        });
    });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest && e.target.closest('form[data-info-respond]');
    if (!form) {
      return;
    }
    e.preventDefault();
    var field = form.querySelector('textarea');
    var status = form.querySelector('.lnkup-info-request__status');
    var send = form.querySelector('[type="submit"]');
    var message = field ? field.value.trim() : '';
    if (message.length < 2) {
      status.textContent = 'Add a reply for the organizer.';
      status.hidden = false;
      return;
    }
    send.disabled = true;
    restPostJson(form.getAttribute('data-endpoint'), { message: message })
      .then(function (data) {
        var block = form.closest('.lnkup-info-request');
        var card = form.closest('.lnkup-request-row');
        var badge = card ? card.querySelector('.lnkup-status') : null;
        if (badge) {
          badge.className = 'lnkup-status lnkup-status--' + (data.status || 'under_review');
          badge.textContent = data.status_label || 'Under review';
        }
        if (block) {
          block.innerHTML = '<p class="lnkup-alert lnkup-alert--success" data-testid="lnkup-vendor-info-sent">Reply sent. The organizer will review your request.</p>';
        }
      })
      .catch(function (err) {
        status.textContent = err.message;
        status.hidden = false;
        send.disabled = false;
      });
  });

  /**
   * First Accept tap turns the button into "Confirm accept" for a few seconds.
   *
   * @param {HTMLButtonElement} btn Accept button.
   */
  function armReviewButton(btn) {
    var original = btn.textContent;
    btn.classList.add('is-armed');
    btn.textContent = uiText('confirm_accept', 'Confirm accept');
    btn.setAttribute('aria-live', 'polite');
    window.setTimeout(function () {
      if (btn.classList.contains('is-armed') && !btn.disabled) {
        btn.classList.remove('is-armed');
        btn.textContent = original;
      }
    }, 4000);
  }

  /**
   * Show an inline decline form with an optional reason under the request card.
   *
   * @param {HTMLButtonElement} btn Decline button.
   */
  function openRejectForm(btn) {
    var host = btn.closest('.lnkup-crm-app-card--v2, .lnkup-crm-app-card') || btn.parentElement;
    if (!host) return;
    var existing = host.querySelector('.lnkup-review-reject');
    if (existing) {
      var existingField = existing.querySelector('textarea');
      if (existingField) existingField.focus();
      return;
    }
    var fieldId = 'lnkup-reject-reason-' + (btn.getAttribute('data-app-id') || Date.now());
    var form = document.createElement('div');
    form.className = 'lnkup-review-reject';
    form.setAttribute('role', 'group');
    form.setAttribute('aria-label', uiText('decline_request', 'Decline request'));
    var label = document.createElement('label');
    label.setAttribute('for', fieldId);
    label.textContent = uiText('decline_reason_label', 'Reason for the vendor (optional)');
    var field = document.createElement('textarea');
    field.id = fieldId;
    field.rows = 2;
    field.maxLength = 500;
    var actions = document.createElement('div');
    actions.className = 'lnkup-review-reject__actions';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost';
    cancel.setAttribute('data-review-reject-cancel', '');
    cancel.textContent = uiText('cancel', 'Cancel');
    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-review-btn--danger';
    confirm.setAttribute('data-review-reject-confirm', '');
    confirm.textContent = uiText('confirm_decline', 'Decline request');
    actions.appendChild(cancel);
    actions.appendChild(confirm);
    form.appendChild(label);
    form.appendChild(field);
    form.appendChild(actions);
    host.appendChild(form);
    field.focus();
  }

  /**
   * Translated UI copy from LNKupUI when available.
   *
   * @param {string} key      Copy key.
   * @param {string} fallback English fallback.
   * @returns {string}
   */
  function uiText(key, fallback) {
    return (window.LNKupUI && typeof window.LNKupUI.t === 'function') ? window.LNKupUI.t(key, fallback) : fallback;
  }

  /**
   * Send one review decision and update the request card in place.
   *
   * @param {HTMLButtonElement} reviewBtn Button inside the request card.
   * @param {string}            action    approve | waitlist | reject.
   * @param {string}            reason    Optional decline reason.
   * @returns {Promise<void>}
   */
  function submitReview(reviewBtn, action, reason) {
    var card = reviewBtn.closest('.lnkup-crm-app-card--v2, .lnkup-crm-app-card');
    var buttons = card ? card.querySelectorAll('.lnkup-review-btn') : [reviewBtn];
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    var data = new FormData();
    data.append('action', 'lnkup_review_application');
    data.append('application_id', reviewBtn.getAttribute('data-app-id'));
    data.append('review_action', action);
    data.append('lnkup_nonce', window.lnkupAjax.reviewNonce || window.lnkupAjax.nonce);
    if (action === 'reject' && reason) {
      data.append('rejection_reason', reason);
    }
    return fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) {
        var type = r.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          throw new Error('bad_response');
        }
        return r.json();
      })
      .then(function (res) {
        var reviewMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Application updated.' : 'Action failed.');
        showToast(reviewMsg, res.success ? 'success' : 'error');
        if (!res.success) {
          Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
          return;
        }
        var statusMap = { approve: 'approved', waitlist: 'waitlisted', reject: 'rejected' };
        var labelMap = { approve: 'Approved', waitlist: 'Waitlisted', reject: 'Rejected' };
        if (!card || !statusMap[action]) {
          location.reload();
          return;
        }
        var badge = card.querySelector('.lnkup-status');
        if (badge) {
          badge.className = 'lnkup-status lnkup-status--' + statusMap[action];
          badge.textContent = (res.data && res.data.status_label) ? res.data.status_label : (labelMap[action] || statusMap[action]);
        }
        var fromStatus = card.getAttribute('data-org-app-status') || '';
        card.setAttribute('data-org-app-status', statusMap[action]);
        Array.prototype.forEach.call(card.querySelectorAll('.lnkup-review-btn, [data-request-info]'), function (b) { b.remove(); });
        var select = card.querySelector('.lnkup-request-row__select');
        if (select) select.remove();
        document.dispatchEvent(new CustomEvent('lnkup-request-updated', { detail: { appId: reviewBtn.getAttribute('data-app-id'), status: statusMap[action], from: fromStatus } }));
      })
      .catch(function () {
        showToast('We could not update this request. Please try again.', 'error');
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
      });
  }

  /**
   * Sync event review summary on the create-event wizard.
   */
  function syncPublishSummary() {
    var nameEl = document.getElementById('event_name');
    var dateEl = document.getElementById('event_date');
    var cityEl = document.getElementById('event_city');
    var titleOut = document.getElementById('lnkup-publish-event-title');
    var dateOut = document.getElementById('lnkup-publish-event-date');
    var cityOut = document.getElementById('lnkup-publish-event-city');
    var feeOut = document.getElementById('lnkup-publish-vendor-fee');
    var spotsOut = document.getElementById('lnkup-publish-spots');
    var feeRow = document.getElementById('lnkup-publish-vendor-fee-row');
    var spotsRow = document.getElementById('lnkup-publish-spots-row');
    var openCb = document.getElementById('lnkup_open_vendor_applications');
    if (titleOut && nameEl) {
      titleOut.textContent = nameEl.value || '—';
    }
    if (dateOut && dateEl) {
      dateOut.textContent = dateEl.value || '—';
    }
    if (cityOut && cityEl) {
      cityOut.textContent = cityEl.value || '—';
    }
    var openApps = !openCb || openCb.checked;
    if (feeRow) {
      feeRow.hidden = !openApps;
    }
    if (spotsRow) {
      spotsRow.hidden = !openApps;
    }
    var rows = document.querySelectorAll('#lnkup-fee-rows [data-fee-row]');
    var feeParts = [];
    var spotsTotal = 0;
    rows.forEach(function (row) {
      var cat = row.querySelector('.lnkup-fee-row__category');
      var custom = row.querySelector('.lnkup-fee-row__custom');
      var fee = row.querySelector('.lnkup-fee-row__fee');
      var spots = row.querySelector('.lnkup-fee-row__spots');
      var customLabel = custom && custom.value ? String(custom.value).trim() : '';
      var catLabel = customLabel || (cat && cat.value ? cat.value : 'Vendor');
      var feeVal = fee && fee.value !== '' ? Number(fee.value) : NaN;
      var spotsVal = spots && spots.value !== '' ? parseInt(spots.value, 10) : 0;
      if (!isNaN(feeVal)) {
        feeParts.push(catLabel + ' $' + feeVal.toFixed(2));
      }
      if (spotsVal > 0) {
        spotsTotal += spotsVal;
      }
    });
    if (feeOut) {
      if (!openApps) {
        feeOut.textContent = 'Skipped';
      } else if (feeParts.length) {
        feeOut.textContent = feeParts.join(' · ');
      } else {
        feeOut.textContent = '—';
      }
    }
    if (spotsOut) {
      if (!openApps) {
        spotsOut.textContent = 'Skipped';
      } else if (spotsTotal > 0) {
        spotsOut.textContent = String(spotsTotal);
      } else {
        spotsOut.textContent = '—';
      }
    }
  }
  window.lnkupSyncPublishSummary = syncPublishSummary;

  function collectFeeRowsToJson(form) {
    var hidden = form.querySelector('#lnkup_opportunities_json');
    if (!hidden) {
      return;
    }
    var rows = form.querySelectorAll('#lnkup-fee-rows [data-fee-row]');
    var opportunities = [];
    rows.forEach(function (row) {
      var cat = row.querySelector('.lnkup-fee-row__category');
      var custom = row.querySelector('.lnkup-fee-row__custom');
      var fee = row.querySelector('.lnkup-fee-row__fee');
      var spots = row.querySelector('.lnkup-fee-row__spots');
      var customLabel = custom && custom.value ? String(custom.value).trim() : '';
      var category = customLabel || (cat && cat.value ? String(cat.value).trim() : '');
      if (!category) {
        return;
      }
      opportunities.push({
        categories: [category],
        vendor_category: category,
        vendor_fee: fee ? fee.value : 250,
        spots_total: spots ? spots.value : 10,
        opportunity_title: category
      });
    });
    hidden.value = JSON.stringify(opportunities);
  }

  function syncFeeRowRequirements(form) {
    if (!form) {
      return;
    }
    form.querySelectorAll('[data-fee-row]').forEach(function (row) {
      var select = row.querySelector('.lnkup-fee-row__category');
      var custom = row.querySelector('.lnkup-fee-row__custom');
      if (!select) {
        return;
      }
      var hasCustom = custom && String(custom.value || '').trim() !== '';
      select.required = !select.disabled && !hasCustom;
    });
  }
  window.lnkupSyncFeeRowRequirements = syncFeeRowRequirements;

  function initFeeRows(form) {
    if (!form || form.getAttribute('data-fee-rows-bound') === '1') {
      return;
    }
    form.setAttribute('data-fee-rows-bound', '1');
    var addBtn = form.querySelector('[data-add-fee-row]');
    var list = form.querySelector('[data-fee-rows-list]');
    var tpl = form.querySelector('[data-fee-row-template]');
    if (addBtn && list && tpl) {
      addBtn.addEventListener('click', function () {
        var node = tpl.content.cloneNode(true);
        list.appendChild(node);
        if (typeof window.lnkupSyncOpenVendorAppsFields === 'function') {
          window.lnkupSyncOpenVendorAppsFields();
        }
        syncFeeRowRequirements(form);
        syncPublishSummary();
      });
    }
    form.addEventListener('input', function () {
      syncFeeRowRequirements(form);
    });
    form.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.lnkup-fee-row__remove');
      if (!removeBtn || !form.contains(removeBtn)) {
        return;
      }
      var row = removeBtn.closest('[data-fee-row]');
      var rows = form.querySelectorAll('[data-fee-rows-list] [data-fee-row]');
      if (row && rows.length > 1) {
        row.remove();
        syncPublishSummary();
      }
    });
    syncFeeRowRequirements(form);
  }

  function detectImportPlatform(url) {
    var host = '';
    try {
      host = new URL(url).hostname.toLowerCase();
    } catch (err) {
      return 'Manual';
    }
    if (host.indexOf('eventbrite') !== -1) return 'Eventbrite';
    if (host.indexOf('posh') !== -1) return 'Posh';
    if (/\.(rss|xml|atom)(\?|$)/i.test(url) || /\/(feed|rss|atom)(\/|$)/i.test(url)) return 'RSS';
    if (host.indexOf('universe') !== -1) return 'Universe';
    return 'Manual';
  }

  function formatImportDateParts(datetime) {
    if (!datetime || typeof datetime !== 'string') {
      return { date: '', time: '' };
    }
    var normalized = datetime.replace(' ', 'T');
    var d = new Date(normalized + (normalized.indexOf('Z') === -1 && normalized.indexOf('+') === -1 ? 'Z' : ''));
    if (isNaN(d.getTime())) {
      var m = datetime.match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/);
      if (m) {
        return { date: m[1], time: m[2] || '' };
      }
      return { date: '', time: '' };
    }
    var yyyy = d.getUTCFullYear();
    var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    var dd = String(d.getUTCDate()).padStart(2, '0');
    var hh = String(d.getUTCHours()).padStart(2, '0');
    var mi = String(d.getUTCMinutes()).padStart(2, '0');
    return { date: yyyy + '-' + mm + '-' + dd, time: hh + ':' + mi };
  }

  function applyImportPreviewToForm(createPanel, data) {
    var form = createPanel ? createPanel.querySelector('#lnkup-create-event-form') : document.getElementById('lnkup-create-event-form');
    if (!form || !data) {
      return;
    }
    var platformSelect = form.querySelector('#ticketing_platform');
    var hiddenUrl = form.querySelector('#lnkup_create_event_ticketing_url');
    var nameEl = form.querySelector('#event_name');
    var descEl = form.querySelector('#description');
    var cityEl = form.querySelector('#event_city');
    var stateEl = form.querySelector('#event_state');
    var venueEl = form.querySelector('#venue_name');
    var dateEl = form.querySelector('#event_date');
    var startEl = form.querySelector('#start_time');
    var endEl = form.querySelector('#end_time');

    if (platformSelect && data.ticketing_platform) {
      platformSelect.value = data.ticketing_platform;
    }
    if (hiddenUrl && data.source_url) {
      hiddenUrl.value = data.source_url;
    }
    if (nameEl && data.title) {
      nameEl.value = data.title;
    }
    if (descEl && data.description) {
      descEl.value = data.description;
    }
    if (cityEl && data.city) {
      cityEl.value = data.city;
    }
    if (stateEl && data.state) {
      stateEl.value = String(data.state).slice(0, 2).toUpperCase();
    }
    if (venueEl && data.venue_name) {
      venueEl.value = data.venue_name;
    }
    var startParts = formatImportDateParts(data.start_datetime);
    var endParts = formatImportDateParts(data.end_datetime);
    if (dateEl && startParts.date) {
      dateEl.value = startParts.date;
    }
    if (startEl && startParts.time) {
      startEl.value = startParts.time;
    }
    if (endEl && endParts.time) {
      endEl.value = endParts.time;
    }
    if (typeof window.lnkupSyncPublishSummary === 'function') {
      window.lnkupSyncPublishSummary();
    }
  }

  function runImportPreview() {
    var createPanel = document.getElementById('lnkup-create-panel');
    var input = createPanel ? createPanel.querySelector('#ticketing_url_import') : document.getElementById('ticketing_url_import');
    var loading = createPanel ? createPanel.querySelector('#lnkup-import-loading') : document.getElementById('lnkup-import-loading');
    var preview = createPanel ? createPanel.querySelector('#lnkup-import-preview') : document.getElementById('lnkup-import-preview');
    var errorBox = createPanel ? createPanel.querySelector('#lnkup-import-error') : document.getElementById('lnkup-import-error');
    var importDetails = createPanel ? createPanel.querySelector('.lnkup-organizer-import-collapse') : null;
    var btn = createPanel ? createPanel.querySelector('#lnkup-import-preview-btn') : document.getElementById('lnkup-import-preview-btn');
    if (!input || !loading || !preview) {
      return;
    }
    var url = (input.value || '').trim();
    if (!url) {
      showToast('Enter a ticketing or feed URL first.', 'error');
      return;
    }

    var ajax = window.lnkupAjax || {};
    var restBase = (ajax.externalEventsRestUrl || ajax.connectorRestUrl || '').replace(/\/$/, '') || '/wp-json/lnkup/v1';
    preview.hidden = true;
    if (errorBox) {
      errorBox.hidden = true;
      errorBox.textContent = '';
    }
    loading.hidden = false;
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }

    fetch(restBase + '/external-events/organizer/import-url', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ajax.restNonce || ''
      },
      body: JSON.stringify({ url: url, preview: true })
    }).then(function (r) {
      return r.json().then(function (body) {
        return { ok: r.ok, status: r.status, body: body };
      });
    }).then(function (res) {
      loading.hidden = true;
      if (btn) {
        btn.disabled = false;
        btn.setAttribute('aria-busy', 'false');
      }
      var body = res.body || {};
      if (!res.ok) {
        var errMsg = body.message || (body.data && body.data.message) || 'Could not import this URL. Paste details manually.';
        if (errorBox) {
          errorBox.textContent = errMsg;
          errorBox.hidden = false;
        }
        showToast(errMsg, 'error');
        return;
      }

      applyImportPreviewToForm(createPanel, body);
      var platform = body.ticketing_platform || detectImportPlatform(url);
      var title = escapeLaunchHtml(body.title || 'Imported event');
      var metaDate = body.start_datetime ? escapeLaunchHtml(String(body.start_datetime)) : '';
      var metaLoc = [body.venue_name, body.city, body.state].filter(Boolean).map(escapeLaunchHtml).join(', ');
      preview.innerHTML = '<p class="lnkup-import-preview__title">' + title + '</p>' +
        '<p class="lnkup-import-preview__meta">Source: <strong>' + escapeLaunchHtml(platform) + '</strong></p>' +
        (metaDate ? '<p class="lnkup-import-preview__meta">' + metaDate + '</p>' : '') +
        (metaLoc ? '<p class="lnkup-import-preview__meta">' + metaLoc + '</p>' : '') +
        '<p class="lnkup-muted">Form fields were filled from the source. Complete Vendors, then review and publish.</p>';
      preview.hidden = false;
      if (importDetails && !importDetails.open) {
        importDetails.open = true;
      }
      setCreateMode('new');
      showToast('Event details imported.', 'success');
    }).catch(function () {
      loading.hidden = true;
      if (btn) {
        btn.disabled = false;
        btn.setAttribute('aria-busy', 'false');
      }
      var failMsg = 'Could not reach import service. Paste details manually.';
      if (errorBox) {
        errorBox.textContent = failMsg;
        errorBox.hidden = false;
      }
      showToast(failMsg, 'error');
    });
  }

  function setCreateMode(mode) {
    var newMode = document.getElementById('lnkup-create-mode-new');
    var existingMode = document.getElementById('lnkup-create-mode-existing');
    document.querySelectorAll('[data-create-mode]').forEach(function (btn) {
      var active = btn.getAttribute('data-create-mode') === mode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (newMode) {
      newMode.classList.toggle('is-active', mode === 'new');
      newMode.hidden = mode !== 'new';
    }
    if (existingMode) {
      existingMode.classList.toggle('is-active', mode === 'existing');
      existingMode.hidden = mode !== 'existing';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var createPanel = document.getElementById('lnkup-create-panel');
    var createForm = createPanel ? createPanel.querySelector('#lnkup-create-event-form') : document.getElementById('lnkup-create-event-form');
    if (createForm) {
      initFeeRows(createForm);
      bindSaveEventDraft();
      createForm.addEventListener('input', syncPublishSummary);
      createForm.addEventListener('change', syncPublishSummary);
      syncPublishSummary();
    }
    document.querySelectorAll('.lnkup-add-slot-form').forEach(function (slotForm) {
      initFeeRows(slotForm);
    });
    var duplicateDialog = document.getElementById('lnkup-duplicate-event-dialog');
    var duplicateForm = document.getElementById('lnkup-duplicate-event-form');
    function closeDuplicateDialog() {
      if (!duplicateDialog) {
        return;
      }
      if (typeof duplicateDialog.close === 'function' && duplicateDialog.open) {
        duplicateDialog.close();
      } else {
        duplicateDialog.hidden = true;
      }
    }
    if (duplicateForm) {
      duplicateForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = document.getElementById('lnkup-duplicate-event-error');
        if (errEl) {
          errEl.hidden = true;
          errEl.textContent = '';
        }
        postForm(duplicateForm).then(function (res) {
          var dupMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Event duplicated.' : 'Could not duplicate the event.');
          if (res.success) {
            showToast(dupMsg, 'success');
            window.location.hash = 'events';
            window.location.reload();
            return;
          }
          if (errEl) {
            errEl.textContent = dupMsg;
            errEl.hidden = false;
          }
          showToast(dupMsg, 'error');
        }).catch(function () {
          showToast('Could not duplicate the event.', 'error');
        });
      });
    }
    document.addEventListener('click', function (e) {
      var copyBtn = e.target.closest('[data-copy-url]');
      if (copyBtn) {
        copyText(copyBtn.getAttribute('data-copy-url') || '', 'Profile link copied.');
        return;
      }
      var shareBtn = e.target.closest('.lnkup-share-event');
      if (shareBtn) {
        copyText(shareBtn.getAttribute('data-share-url') || '', 'Event link copied.');
        return;
      }
      var saveBtn = e.target.closest('.lnkup-save-event');
      if (saveBtn) {
        e.preventDefault();
        var saveCfg = window.lnkupAjax || {};
        if (!saveCfg.restUrl || !saveCfg.restNonce || saveBtn.disabled) {
          return;
        }
        saveBtn.disabled = true;
        fetch(saveCfg.restUrl + '/favorites', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'X-WP-Nonce': saveCfg.restNonce,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            type: saveBtn.getAttribute('data-favorite-type') || 'events',
            id: parseInt(saveBtn.getAttribute('data-favorite-id'), 10)
          })
        }).then(function (response) {
          return response.json();
        }).then(function (data) {
          var saved = !!(data && data.favorited);
          saveBtn.classList.toggle('is-active', saved);
          saveBtn.setAttribute('aria-pressed', saved ? 'true' : 'false');
          saveBtn.textContent = saved ? 'Saved' : 'Save';
        }).catch(function () {
          showToast('Could not save this event.', 'error');
        }).finally(function () {
          saveBtn.disabled = false;
        });
        return;
      }
      var followBtn = e.target.closest('[data-follow-organizer]');
      if (followBtn) {
        e.preventDefault();
        if (followBtn.disabled) {
          return;
        }
        var followCfg = window.lnkupAjax || {};
        if (!followCfg.restUrl || !followCfg.restNonce) {
          return;
        }
        followBtn.disabled = true;
        fetch(followCfg.restUrl + '/favorites', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'X-WP-Nonce': followCfg.restNonce,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            type: 'organizers',
            id: parseInt(followBtn.getAttribute('data-follow-organizer'), 10)
          })
        }).then(function (response) {
          return response.json();
        }).then(function (data) {
          var following = !!(data && data.favorited);
          followBtn.classList.toggle('is-following', following);
          followBtn.setAttribute('aria-pressed', following ? 'true' : 'false');
          followBtn.textContent = following
            ? (followBtn.getAttribute('data-label-following') || 'Following')
            : (followBtn.getAttribute('data-label-follow') || 'Follow');
        }).catch(function () {
          showToast('Could not update follow.', 'error');
        }).finally(function () {
          followBtn.disabled = false;
        });
        return;
      }
      if (e.target.closest('[data-share-close]')) {
        var openShare = document.getElementById('lnkup-share-event-dialog');
        if (openShare && typeof openShare.close === 'function' && openShare.open) {
          openShare.close();
        }
        return;
      }
      var duplicateBtn = e.target.closest('[data-duplicate-event]');
      if (duplicateBtn && duplicateDialog) {
        var idField = document.getElementById('lnkup-duplicate-event-id');
        var nameField = document.getElementById('lnkup-duplicate-event-name');
        var dateField = document.getElementById('lnkup-duplicate-event-date');
        if (idField) {
          idField.value = duplicateBtn.getAttribute('data-duplicate-event') || '';
        }
        var dupToken = document.getElementById('lnkup-duplicate-client-token');
        if (dupToken) {
          dupToken.value = generateClientToken();
        }
        if (nameField) {
          nameField.value = duplicateBtn.getAttribute('data-event-name') || '';
        }
        if (dateField) {
          dateField.value = '';
        }
        var cityField = document.getElementById('lnkup-duplicate-event-city');
        var stateField = document.getElementById('lnkup-duplicate-event-state');
        var venueField = document.getElementById('lnkup-duplicate-event-venue');
        var addressField = document.getElementById('lnkup-duplicate-event-address');
        if (cityField) {
          cityField.value = duplicateBtn.getAttribute('data-city') || '';
        }
        if (stateField) {
          stateField.value = duplicateBtn.getAttribute('data-state') || '';
        }
        if (venueField) {
          venueField.value = duplicateBtn.getAttribute('data-venue') || '';
        }
        if (addressField) {
          addressField.value = duplicateBtn.getAttribute('data-address') || '';
        }
        if (typeof duplicateDialog.showModal === 'function') {
          if (!duplicateDialog.open) {
            duplicateDialog.showModal();
          }
        } else {
          duplicateDialog.hidden = false;
        }
        return;
      }
      if (e.target.closest('[data-duplicate-cancel]')) {
        closeDuplicateDialog();
        return;
      }
      var slotToggle = e.target.closest('.lnkup-add-slot-toggle');
      if (slotToggle) {
        var slotId = slotToggle.getAttribute('data-add-slot');
        var slotForm = document.querySelector('[data-slot-panel="' + slotId + '"]');
        if (slotForm) {
          var slotOpen = slotForm.hidden;
          slotForm.hidden = !slotOpen;
          slotToggle.setAttribute('aria-expanded', slotOpen ? 'true' : 'false');
          if (slotOpen && typeof slotForm.scrollIntoView === 'function') {
            slotForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
        return;
      }
      var toggle = e.target.closest('.lnkup-edit-event-toggle');
      if (!toggle) {
        return;
      }
      var eventId = toggle.getAttribute('data-edit-event');
      var form = document.querySelector('[data-edit-event-form="' + eventId + '"]');
      if (!form) {
        return;
      }
      var open = form.hidden;
      form.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open && typeof form.scrollIntoView === 'function') {
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
    document.querySelectorAll('[data-create-mode]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setCreateMode(btn.getAttribute('data-create-mode') || 'new');
      });
    });
    var initialMode = document.documentElement.getAttribute('data-lnkup-create-mode');
    var params = new URLSearchParams(window.location.search);
    if (params.get('mode') === 'existing') {
      initialMode = 'existing';
    }
    if (initialMode === 'existing') {
      setCreateMode('existing');
    }
    var previewBtn = createPanel ? createPanel.querySelector('#lnkup-import-preview-btn') : document.getElementById('lnkup-import-preview-btn');
    if (previewBtn) {
      previewBtn.addEventListener('click', runImportPreview);
    }
    var importInput = createPanel ? createPanel.querySelector('#ticketing_url_import') : document.getElementById('ticketing_url_import');
    if (importInput) {
      importInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          runImportPreview();
        }
      });
    }
    var importedUrl = params.get('import_url');
    var hiddenUrl = createPanel ? createPanel.querySelector('#lnkup_create_event_ticketing_url') : document.getElementById('lnkup_create_event_ticketing_url');
    if (importedUrl && hiddenUrl) {
      hiddenUrl.value = importedUrl;
      if (importInput) {
        importInput.value = importedUrl;
      }
      var importDetails = createPanel ? createPanel.querySelector('.lnkup-organizer-import-collapse') : null;
      if (importDetails) {
        importDetails.open = true;
      }
    }
    var docFile = document.getElementById('lnkup_document');
    var docForm = document.getElementById('lnkup-document-upload-form');
    if (docFile && docForm && !document.getElementById('lnkup-vendor-profile-editor')) {
      docFile.addEventListener('change', function () {
        if (!docFile.files || !docFile.files.length) {
          return;
        }
        uploadDocumentForm(docForm);
      });
    }

    function filterOpportunityPricingByEvent(eventId) {
      var panel = document.getElementById('lnkup-opportunities-pricing');
      if (!panel) {
        return;
      }
      var rows = panel.querySelectorAll('tbody tr[data-event-id]');
      var emptyEl = document.getElementById('lnkup-opp-pricing-empty');
      var hintEl = document.getElementById('lnkup-opp-pricing-hint');
      var visible = 0;
      rows.forEach(function (row) {
        var match = !eventId || String(row.getAttribute('data-event-id') || '') === String(eventId);
        row.hidden = !match;
        if (match) {
          visible += 1;
        }
      });
      if (emptyEl) {
        emptyEl.hidden = visible > 0 || !eventId;
      }
      var table = panel.querySelector('table');
      if (table) {
        table.hidden = visible === 0 && !!eventId;
      }
      if (hintEl && eventId) {
        hintEl.textContent = visible > 0
          ? 'Showing opportunities for this event. Clear the URL hash (#events) to see all.'
          : 'No opportunities for this event yet. Add one from Launch event.';
      }
      panel.classList.toggle('is-focused', !!eventId);
      if (eventId) {
        try {
          panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
          panel.scrollIntoView(true);
        }
      }
    }

    function syncPricingFromHash() {
      var hash = (window.location.hash || '').replace(/^#/, '');
      var match = hash.match(/^pricing-(\d+)$/);
      var eventId = match
        ? match[1]
        : (document.documentElement.getAttribute('data-lnkup-pricing-event') || '');
      if (match) {
        document.documentElement.setAttribute('data-lnkup-pricing-event', match[1]);
      }
      filterOpportunityPricingByEvent(eventId);
    }

    var portfolioFilter = document.getElementById('lnkup-portfolio-slot-filter');
    if (portfolioFilter) {
      portfolioFilter.addEventListener('input', function () {
        var q = String(portfolioFilter.value || '').trim().toLowerCase();
        var groups = document.querySelectorAll('[data-portfolio-slots] [data-slot-group]');
        var shown = 0;
        groups.forEach(function (group) {
          var groupRows = group.querySelectorAll('[data-slot-search]');
          var groupShown = 0;
          groupRows.forEach(function (row) {
            var match = !q || String(row.getAttribute('data-slot-search') || '').indexOf(q) !== -1;
            row.hidden = !match;
            if (match) {
              groupShown++;
            }
          });
          group.hidden = groupShown === 0;
          shown += groupShown;
        });
        var empty = document.getElementById('lnkup-portfolio-slot-empty');
        if (empty) {
          empty.hidden = shown !== 0;
        }
      });
    }

    document.querySelectorAll('[data-org-tabs]').forEach(function (tabs) {
      var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-org-tab]'));
      var panels = tabs.querySelectorAll('[data-org-panel]');
      function selectOrgTab(button, updateHash) {
        var name = button.getAttribute('data-org-tab');
        buttons.forEach(function (item) {
          var on = item === button;
          item.setAttribute('aria-selected', on ? 'true' : 'false');
          item.tabIndex = on ? 0 : -1;
        });
        panels.forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-org-panel') !== name;
        });
        if (updateHash && window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + name);
        }
      }
      buttons.forEach(function (button, index) {
        button.addEventListener('click', function () {
          selectOrgTab(button, true);
        });
        button.addEventListener('keydown', function (e) {
          var next = null;
          if (e.key === 'ArrowRight') {
            next = buttons[(index + 1) % buttons.length];
          } else if (e.key === 'ArrowLeft') {
            next = buttons[(index - 1 + buttons.length) % buttons.length];
          }
          if (next) {
            e.preventDefault();
            next.focus();
            selectOrgTab(next, true);
          }
        });
      });
      var hashTab = (window.location.hash || '').replace(/^#/, '');
      var fromHash = hashTab ? tabs.querySelector('[data-org-tab="' + hashTab.replace(/[^a-z-]/g, '') + '"]') : null;
      if (fromHash) {
        selectOrgTab(fromHash, false);
      }
    });

    var orgFilters = document.querySelector('[data-org-event-filters]');
    if (orgFilters) {
      var orgMode = 'all';
      var orgCity = '';
      var applyOrgFilters = function () {
        var shownCards = 0;
        document.querySelectorAll('[data-org-event]').forEach(function (card) {
          var openOk = orgMode !== 'open' || card.getAttribute('data-org-open') === '1';
          var cityOk = !orgCity || card.getAttribute('data-org-city') === orgCity;
          var match = openOk && cityOk;
          card.hidden = !match;
          if (match) {
            shownCards++;
          }
        });
        var orgEmpty = document.querySelector('[data-org-filter-empty]');
        if (orgEmpty) {
          orgEmpty.hidden = shownCards !== 0;
        }
      };
      orgFilters.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-org-filter]');
        if (!btn) {
          return;
        }
        orgMode = btn.getAttribute('data-org-filter') || 'all';
        orgFilters.querySelectorAll('[data-org-filter]').forEach(function (item) {
          item.classList.toggle('is-active', item === btn);
        });
        applyOrgFilters();
      });
      var orgCitySelect = orgFilters.querySelector('[data-org-city]');
      if (orgCitySelect) {
        orgCitySelect.addEventListener('change', function () {
          orgCity = String(orgCitySelect.value || '');
          applyOrgFilters();
        });
      }
    }

    document.querySelectorAll('[data-event-tabs]').forEach(function (tabs) {
      var root = tabs.parentElement;
      if (!root) {
        return;
      }
      function showBucket(bucket) {
        var shown = 0;
        root.querySelectorAll('[data-event-bucket]').forEach(function (card) {
          var match = card.getAttribute('data-event-bucket') === bucket;
          card.hidden = !match;
          if (match) {
            shown++;
          }
        });
        var empty = root.querySelector('[data-event-tab-empty]');
        if (empty) {
          empty.hidden = shown !== 0;
        }
      }
      showBucket('live');
      tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-event-tab]');
        if (!btn) {
          return;
        }
        tabs.querySelectorAll('[data-event-tab]').forEach(function (item) {
          item.classList.toggle('is-active', item === btn);
          item.classList.toggle('lnkup-btn--outline', item !== btn);
        });
        showBucket(btn.getAttribute('data-event-tab') || 'live');
      });
    });

    document.addEventListener('click', function (e) {
      document.querySelectorAll('.lnkup-more-menu[open]').forEach(function (menu) {
        if (!menu.contains(e.target)) {
          menu.removeAttribute('open');
        }
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') {
        return;
      }
      document.querySelectorAll('.lnkup-more-menu[open]').forEach(function (menu) {
        menu.removeAttribute('open');
        var summary = menu.querySelector('summary');
        if (summary) {
          summary.focus();
        }
      });
    });

    document.querySelectorAll('[data-event-search-input]').forEach(function (input) {
      var grid = document.getElementById(input.getAttribute('aria-controls') || '');
      var empty = document.getElementById('lnkup-event-search-empty');
      if (!grid) {
        return;
      }
      input.addEventListener('input', function () {
        var query = input.value.trim().toLowerCase();
        var shown = 0;
        grid.querySelectorAll('[data-event-search]').forEach(function (card) {
          var match = !query || (card.getAttribute('data-event-search') || '').indexOf(query) !== -1;
          card.hidden = !match;
          if (match) {
            shown++;
          }
        });
        if (empty) {
          empty.hidden = shown !== 0;
        }
      });
    });

    document.querySelectorAll('[data-edit-tabs]').forEach(function (tabs) {
      var root = tabs.parentElement;
      if (!root) {
        return;
      }
      tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-edit-tab]');
        if (!btn) {
          return;
        }
        var name = btn.getAttribute('data-edit-tab');
        tabs.querySelectorAll('[data-edit-tab]').forEach(function (item) {
          item.classList.toggle('is-active', item === btn);
          item.setAttribute('aria-pressed', item === btn ? 'true' : 'false');
          if (item.classList.contains('lnkup-btn')) {
            item.classList.toggle('lnkup-btn--outline', item !== btn);
          }
        });
        root.querySelectorAll('[data-edit-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-edit-panel') !== name;
        });
      });
    });

    syncPricingFromHash();
    window.addEventListener('hashchange', syncPricingFromHash);

  });

  /**
   * Image upload controls rendered by lnkup_image_upload_field(). Files go to
   * lnkup_upload_profile_image; the WordPress media library is never opened.
   */
  var IMAGE_MAX_BYTES = 5 * 1024 * 1024;
  var IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

  function imageRootItems(root) {
    return Array.prototype.slice.call(root.querySelectorAll('[data-image-preview] [data-image-id]'));
  }

  function syncImageValue(root) {
    var value = root.querySelector('[data-image-value]');
    var ids = imageRootItems(root).map(function (item) { return item.getAttribute('data-image-id'); });
    if (value) {
      value.value = root.getAttribute('data-image-upload') === 'gallery' ? ids.join(',') : (ids[0] || '0');
    }
    syncOrgProfilePreview();
  }

  /**
   * Mirror name, intro, logo, and cover into the organizer Profile preview card.
   */
  function syncOrgProfilePreview() {
    var preview = document.querySelector('[data-org-profile-preview]');
    if (!preview) {
      return;
    }
    var nameField = document.getElementById('lnkup-organizer-public-name');
    var taglineField = document.getElementById('lnkup-organizer-tagline');
    var nameEl = preview.querySelector('[data-preview-name]');
    var taglineEl = preview.querySelector('[data-preview-tagline]');
    if (nameEl && nameField && nameField.value.trim()) {
      nameEl.textContent = nameField.value.trim();
    }
    if (taglineEl && taglineField) {
      taglineEl.textContent = taglineField.value.trim();
    }
    var logoImg = document.querySelector('[data-testid="lnkup-organizer-logo-upload"] [data-image-preview] img');
    var logoEl = preview.querySelector('[data-preview-logo]');
    if (logoEl) {
      if (logoImg) {
        logoEl.innerHTML = '';
        var copy = document.createElement('img');
        copy.src = logoImg.src;
        copy.alt = '';
        logoEl.appendChild(copy);
      } else {
        logoEl.textContent = (nameEl ? nameEl.textContent : '').charAt(0).toUpperCase();
      }
    }
    var coverImg = document.querySelector('[data-testid="lnkup-organizer-cover-upload"] [data-image-preview] img');
    var coverEl = preview.querySelector('[data-preview-cover]');
    if (coverEl) {
      coverEl.style.backgroundImage = coverImg ? 'url("' + coverImg.src.replace(/"/g, '%22') + '")' : '';
    }
  }

  document.addEventListener('input', function (e) {
    if (e.target && (e.target.id === 'lnkup-organizer-public-name' || e.target.id === 'lnkup-organizer-tagline')) {
      syncOrgProfilePreview();
    }
  });

  function setImageStatus(root, message, isError) {
    var status = root.querySelector('[data-image-status]');
    if (status) {
      status.textContent = message || '';
      status.classList.toggle('is-error', !!isError);
    }
  }

  function buildImageItem(root, id, url) {
    var position = imageRootItems(root).length + 1;
    var altBase = root.getAttribute('data-image-alt') || 'Image';
    var label = root.getAttribute('data-image-upload') === 'gallery' ? altBase + ' ' + position : altBase;
    var fig = document.createElement('figure');
    fig.className = 'lnkup-image-upload__item';
    fig.setAttribute('data-image-id', String(id));
    var img = document.createElement('img');
    img.src = url;
    img.alt = label;
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'lnkup-image-upload__remove';
    remove.setAttribute('data-image-remove', '');
    remove.setAttribute('aria-label', 'Remove ' + label);
    remove.textContent = '\u00d7';
    fig.appendChild(img);
    fig.appendChild(remove);
    var itemName = root.getAttribute('data-image-item-name');
    if (itemName) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = itemName;
      hidden.value = String(id);
      fig.appendChild(hidden);
    }
    return fig;
  }

  function uploadOneImage(file) {
    if (IMAGE_TYPES.indexOf(file.type) === -1) {
      return Promise.reject(new Error('Use a JPG, PNG, WebP, or GIF image.'));
    }
    if (file.size > IMAGE_MAX_BYTES) {
      return Promise.reject(new Error('Images must be under 5 MB.'));
    }
    var ajax = window.lnkupAjax || {};
    var data = new FormData();
    data.append('action', 'lnkup_upload_profile_image');
    data.append('lnkup_nonce', ajax.nonce || '');
    data.append('image', file);
    return fetch(ajax.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) {
        var type = r.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          throw new Error('Upload failed. Please try again.');
        }
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success || !res.data || !res.data.id) {
          throw new Error((res && res.data && res.data.message) || 'Upload failed. Please try again.');
        }
        return res.data;
      });
  }

  function handleImageFiles(root, button, files) {
    var isGallery = root.getAttribute('data-image-upload') === 'gallery';
    var max = parseInt(root.getAttribute('data-image-max') || '12', 10);
    var room = isGallery ? Math.max(0, max - imageRootItems(root).length) : 1;
    var queue = Array.prototype.slice.call(files || [], 0, room);
    if (!queue.length) {
      setImageStatus(root, isGallery ? 'You can add up to ' + max + ' images.' : '', true);
      return;
    }
    var label = button.textContent;
    button.disabled = true;
    button.textContent = 'Uploading\u2026';
    setImageStatus(root, '', false);
    var preview = root.querySelector('[data-image-preview]');
    var failed = '';
    var chain = Promise.resolve();
    queue.forEach(function (file) {
      chain = chain.then(function () {
        return uploadOneImage(file).then(function (data) {
          if (!isGallery) {
            preview.innerHTML = '';
          }
          preview.appendChild(buildImageItem(root, data.id, data.thumb || data.url));
          syncImageValue(root);
        }).catch(function (err) {
          failed = err && err.message ? err.message : 'Upload failed. Please try again.';
        });
      });
    });
    chain.then(function () {
      button.disabled = false;
      button.textContent = label;
      setImageStatus(root, failed || 'Uploaded. Save to keep your changes.', !!failed);
    });
  }

  document.addEventListener('click', function (e) {
    var remove = e.target.closest('[data-image-remove]');
    if (remove) {
      var removeRoot = remove.closest('[data-image-upload]');
      var item = remove.closest('[data-image-id]');
      if (removeRoot && item) {
        e.preventDefault();
        item.remove();
        syncImageValue(removeRoot);
        setImageStatus(removeRoot, 'Removed. Save to keep your changes.', false);
        var nextButton = removeRoot.querySelector('[data-image-upload-button]');
        if (nextButton) {
          nextButton.focus();
        }
      }
      return;
    }
    var button = e.target.closest('[data-image-upload-button]');
    if (!button) {
      return;
    }
    var root = button.closest('[data-image-upload]');
    if (!root) {
      return;
    }
    e.preventDefault();
    var picker = root.querySelector('input[type="file"][data-image-file]');
    if (!picker) {
      picker = document.createElement('input');
      picker.type = 'file';
      picker.accept = IMAGE_TYPES.join(',');
      picker.hidden = true;
      picker.setAttribute('data-image-file', '');
      picker.multiple = root.getAttribute('data-image-upload') === 'gallery';
      picker.addEventListener('change', function () {
        handleImageFiles(root, button, picker.files);
        picker.value = '';
      });
      root.appendChild(picker);
    }
    picker.click();
  });
})();