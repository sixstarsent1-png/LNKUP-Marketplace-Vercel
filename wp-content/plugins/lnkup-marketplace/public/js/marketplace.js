(function () {
  var eventSubmitting = false;
  var oppSubmitting = false;

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
    }
    var oppId = form ? form.getAttribute('data-opportunity-id') : '';
    if (oppId) {
      document.querySelectorAll('.lnkup-event-card--v2[data-opportunity-id="' + oppId + '"] .lnkup-card-apply').forEach(function (btn) {
        btn.textContent = 'Applied';
        btn.classList.add('is-disabled');
        btn.setAttribute('aria-disabled', 'true');
      });
    }
    showToast(msg, 'success');
  }

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
    var payload = {
      opportunity_id: parseInt(form.getAttribute('data-opportunity-id') || (oppInput ? oppInput.value : '0'), 10),
      selected_category: categoryEl ? categoryEl.value : '',
      application_message: messageEl ? messageEl.value : '',
      setup_notes: setupEl ? setupEl.value : '',
      accepted_terms: !!(termsEl && termsEl.checked)
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
        return r.json().then(function (body) {
          return { ok: r.ok, body: body };
        });
      }).then(function (res) {
        var body = res.body || {};
        if (res.ok && body.success) {
          showApplySuccess(body.message || 'Application submitted.');
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
      if (form.classList.contains('lnkup-document-form')) {
        e.preventDefault();
        postForm(form).then(function (res) {
          var docMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Document saved.' : 'Upload failed.');
          showToast(docMsg, res.success ? 'success' : 'error');
          if (res.success) location.reload();
        });
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

      if (form.classList.contains('lnkup-opportunity-form')) {
        if (res.success) {
          var oppSuccess = document.getElementById('lnkup-create-opportunity-success');
          if (oppSuccess) {
            oppSuccess.innerHTML = '<p class="lnkup-alert lnkup-alert--success">' + msg + '</p>';
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
      var row = pricingSave.closest('tr');
      if (!oppId || !row) {
        return;
      }
      var feeInput = row.querySelector('.lnkup-opp-fee-input');
      var spotsInput = row.querySelector('.lnkup-opp-spots-input');
      var nonceInput = document.querySelector('#lnkup-create-opportunity-form input[name="lnkup_nonce"]');
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

    var reviewBtn = e.target.closest('.lnkup-review-btn');
    if (reviewBtn) {
      var appId = reviewBtn.getAttribute('data-app-id');
      var action = reviewBtn.getAttribute('data-action');
      var data = new FormData();
      data.append('action', 'lnkup_review_application');
      data.append('application_id', appId);
      data.append('review_action', action);
      data.append('lnkup_nonce', window.lnkupAjax.reviewNonce || window.lnkupAjax.nonce);
      if (action === 'reject') {
        var reason = prompt('Rejection reason (optional):');
        if (reason) data.append('rejection_reason', reason);
      }
      fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var reviewMsg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Application updated.' : 'Action failed.');
          showToast(reviewMsg, res.success ? 'success' : 'error');
          if (res.success) {
            var card = reviewBtn.closest('.lnkup-crm-app-card--v2, .lnkup-crm-app-card');
            var statusMap = { approve: 'approved', waitlist: 'waitlisted', reject: 'rejected' };
            var labelMap = { approve: 'Approved', waitlist: 'Waitlisted', reject: 'Rejected' };
            if (card && statusMap[action]) {
              var badge = card.querySelector('.lnkup-status');
              if (badge) {
                badge.className = 'lnkup-status lnkup-status--' + statusMap[action];
                badge.textContent = (res.data && res.data.status_label) ? res.data.status_label : (labelMap[action] || statusMap[action]);
              }
              card.querySelectorAll('.lnkup-review-btn').forEach(function (b) { b.disabled = true; });
            } else {
              location.reload();
            }
          }
        });
    }
  });

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
      var fee = row.querySelector('.lnkup-fee-row__fee');
      var spots = row.querySelector('.lnkup-fee-row__spots');
      var catLabel = cat && cat.value ? cat.value : 'Vendor';
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
      var fee = row.querySelector('.lnkup-fee-row__fee');
      var spots = row.querySelector('.lnkup-fee-row__spots');
      var category = cat && cat.value ? String(cat.value).trim() : '';
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

  function initFeeRows(form) {
    if (!form) {
      return;
    }
    var addBtn = form.querySelector('#lnkup-add-fee-row');
    var list = form.querySelector('#lnkup-fee-rows');
    var tpl = form.querySelector('#lnkup-fee-row-template');
    if (addBtn && list && tpl) {
      addBtn.addEventListener('click', function () {
        var node = tpl.content.cloneNode(true);
        list.appendChild(node);
        syncPublishSummary();
      });
    }
    form.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.lnkup-fee-row__remove');
      if (!removeBtn) {
        return;
      }
      var row = removeBtn.closest('[data-fee-row]');
      var rows = form.querySelectorAll('#lnkup-fee-rows [data-fee-row]');
      if (row && rows.length > 1) {
        row.remove();
        syncPublishSummary();
      }
    });
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
      createForm.addEventListener('input', syncPublishSummary);
      createForm.addEventListener('change', syncPublishSummary);
      syncPublishSummary();
    }
    document.addEventListener('click', function (e) {
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
        var data = new FormData(docForm);
        fetch(window.lnkupAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Document uploaded.' : 'Upload failed.');
            showToast(msg, res.success ? 'success' : 'error');
            if (res.success) {
              window.location.reload();
            }
          });
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

    syncPricingFromHash();
    window.addEventListener('hashchange', syncPricingFromHash);

    (function bindEventGalleryPicker() {
      var pickBtn = document.getElementById('lnkup-event-gallery-pick');
      var hidden = document.getElementById('lnkup_event_gallery_ids');
      var preview = document.getElementById('lnkup-event-gallery-preview');
      if (!pickBtn || !hidden) {
        return;
      }
      var selected = [];
      function renderPreview() {
        if (!preview) {
          return;
        }
        preview.innerHTML = '';
        selected.forEach(function (item) {
          var fig = document.createElement('figure');
          fig.className = 'lnkup-event-gallery-preview__item';
          if (item.type === 'video') {
            fig.innerHTML = '<span class="lnkup-event-gallery-preview__video">Video</span>';
          } else if (item.url) {
            fig.innerHTML = '<img src="' + escapeLaunchHtml(item.url) + '" alt="" />';
          }
          preview.appendChild(fig);
        });
        hidden.value = selected.map(function (s) { return s.id; }).join(',');
      }
      pickBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!window.wp || !wp.media) {
          showToast('Media library unavailable. Refresh and try again.', 'error');
          return;
        }
        var frame = wp.media({
          title: 'Event photos & videos',
          button: { text: 'Add to event' },
          multiple: true,
          library: { type: ['image', 'video'] }
        });
        frame.on('select', function () {
          var selection = frame.state().get('selection');
          selected = [];
          selection.each(function (attachment) {
            var json = attachment.toJSON();
            var mime = String(json.mime || '');
            selected.push({
              id: json.id,
              url: json.sizes && json.sizes.medium ? json.sizes.medium.url : (json.url || ''),
              type: mime.indexOf('video/') === 0 ? 'video' : 'image'
            });
          });
          renderPreview();
        });
        frame.open();
      });
    })();
  });
})();