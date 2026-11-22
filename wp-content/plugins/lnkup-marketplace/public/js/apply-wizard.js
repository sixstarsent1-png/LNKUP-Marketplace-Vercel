/**
 * LNKup application wizard: step navigation, server-side autosave (drafts), resume,
 * price estimate, and idempotent submit. WordPress owns the draft; nothing here is
 * the source of truth for prices or application state.
 */
(function () {
  'use strict';

  var ajax = window.lnkupAjax || {};
  var base = (ajax.applicationsRestUrl || '').replace(/\/$/, '');
  var SAVE_DELAY = 1500;

  var STATUS = {
    saving: 'Saving…',
    saved: 'Saved',
    failed: 'Save failed — your latest changes are not saved yet.',
    dirty: 'Unsaved changes'
  };

  /**
   * Fetch JSON from the LNKup REST API; resolves { ok, status, body } and never rejects on HTTP errors.
   */
  function restJson(url, options) {
    var opts = options || {};
    var headers = { 'X-WP-Nonce': ajax.restNonce || '' };
    if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    Object.keys(opts.headers || {}).forEach(function (key) {
      headers[key] = opts.headers[key];
    });
    return fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      keepalive: !!opts.keepalive,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (ct.indexOf('application/json') === -1) {
        return { ok: false, status: res.status, body: { code: 'invalid_response', message: 'Unexpected server response.' } };
      }
      return res.json().then(function (body) {
        return { ok: res.ok, status: res.status, body: body || {} };
      });
    }).catch(function (err) {
      if (window.console) {
        console.error('LNKup wizard request failed:', err);
      }
      return { ok: false, status: 0, body: { code: 'network_error', message: 'Check your connection and try again.' } };
    });
  }

  /** Random request key for Idempotency-Key headers. */
  function requestKey() {
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function (b) {
        return ('0' + b.toString(16)).slice(-2);
      }).join('');
    }
    return String(Date.now()) + Math.random().toString(16).slice(2);
  }

  function clientKind() {
    return window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 'mobile_web' : 'web';
  }

  function money(n) {
    var num = Number(n) || 0;
    try {
      return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' });
    } catch (e) {
      return '$' + num.toFixed(2);
    }
  }

  function textNode(tag, text, className) {
    var el = document.createElement(tag);
    el.textContent = text;
    if (className) {
      el.className = className;
    }
    return el;
  }

  function Wizard(form) {
    this.form = form;
    var cfg = {};
    try {
      cfg = JSON.parse(form.getAttribute('data-wizard-config') || '{}');
    } catch (e) {
      cfg = {};
    }
    this.oppId = cfg.opportunityId || parseInt(form.getAttribute('data-opportunity-id') || '0', 10);
    this.steps = cfg.steps || ['opportunity'];
    this.step = cfg.start || this.steps[0];
    this.revision = typeof cfg.revision === 'number' ? cfg.revision : null;
    this.inviteToken = cfg.inviteToken || '';
    this.single = form.getAttribute('data-single-step') === '1';
    this.submitKey = requestKey();
    this.timer = null;
    this.saving = null;
    this.dirty = false;
    this.acceptPrice = false;
    this.statusEl = form.querySelector('[data-wizard-save-status]');
    this.bind();
    this.showStep(this.step, false);
    this.loadQuote();
    if (cfg.priceChanged) {
      this.setError('The booth price changed since you started. Check the estimate before submitting.');
    }
  }

  Wizard.prototype.bind = function () {
    var self = this;
    this.form.addEventListener('input', function (e) {
      if (e.target && e.target.name) {
        self.markDirty();
      }
    });
    this.form.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'selected_category') {
        self.loadQuote();
      }
      if (e.target && e.target.name) {
        self.markDirty();
      }
    });
    var next = this.form.querySelector('[data-wizard-next]');
    var back = this.form.querySelector('[data-wizard-back]');
    if (next) {
      next.addEventListener('click', function () {
        self.go(1);
      });
    }
    if (back) {
      back.addEventListener('click', function () {
        self.go(-1);
      });
    }
    this.form.addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.submit();
    });
    window.addEventListener('pagehide', function () {
      if (self.dirty) {
        self.save(true);
      }
    });
  };

  Wizard.prototype.setStatus = function (key) {
    if (!this.statusEl) {
      return;
    }
    this.statusEl.textContent = STATUS[key] || '';
    this.statusEl.setAttribute('data-state', key || '');
  };

  Wizard.prototype.markDirty = function () {
    var self = this;
    this.dirty = true;
    this.setStatus('dirty');
    window.clearTimeout(this.timer);
    this.timer = window.setTimeout(function () {
      self.save(false);
    }, SAVE_DELAY);
  };

  Wizard.prototype.collect = function () {
    var form = this.form;
    var val = function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      return el ? el.value : '';
    };
    var answers = {};
    var overrides = {};
    var updateProfile = {};
    var docIds = [];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) {
        return;
      }
      var m = el.name.match(/^answers\[([a-z0-9_\-]+)\]$/);
      if (m) {
        answers[m[1]] = el.type === 'checkbox' ? el.checked : el.value;
        return;
      }
      m = el.name.match(/^overrides\[([a-z_]+)\]$/);
      if (m) {
        if (el.value !== (el.getAttribute('data-profile-value') || '')) {
          overrides[m[1]] = el.value;
        }
        return;
      }
      m = el.name.match(/^update_profile\[([a-z_]+)\]$/);
      if (m) {
        updateProfile[m[1]] = el.checked;
        return;
      }
      if (el.name === 'document_ids[]' && el.checked) {
        docIds.push(parseInt(el.value, 10));
      }
    });
    return {
      step: this.step,
      fields: {
        selected_category: val('selected_category'),
        application_message: val('application_message'),
        setup_notes: val('setup_notes')
      },
      answers: answers,
      profile_overrides: overrides,
      document_ids: docIds,
      invite_token: this.inviteToken,
      client: clientKind(),
      revision: this.revision,
      _updateProfile: updateProfile
    };
  };

  /**
   * PUT the draft. Resolves true when saved. A revision conflict loads the server copy.
   */
  Wizard.prototype.save = function (beacon) {
    var self = this;
    window.clearTimeout(this.timer);
    if (!base || !this.oppId) {
      return Promise.resolve(false);
    }
    if (this.saving) {
      return this.saving.then(function () {
        return self.dirty ? self.save(beacon) : true;
      });
    }
    var payload = this.collect();
    delete payload._updateProfile;
    this.dirty = false;
    this.setStatus('saving');
    this.saving = restJson(base + '/drafts/' + this.oppId, { method: 'PUT', body: payload, keepalive: !!beacon }).then(function (res) {
      self.saving = null;
      if (res.ok && res.body.draft) {
        self.revision = res.body.draft.revision;
        if (!self.dirty) {
          self.setStatus('saved');
        }
        return true;
      }
      if (res.status === 409 && res.body.code === 'lnkup_draft_conflict' && res.body.data && res.body.data.draft) {
        self.applyDraft(res.body.data.draft);
        self.setError(res.body.message);
        self.setStatus('saved');
        return true;
      }
      self.dirty = true;
      self.setStatus('failed');
      if (res.body && res.body.message && res.status !== 0) {
        self.setError(res.body.message);
      }
      return false;
    });
    return this.saving;
  };

  /** Load a server draft into the form (other-device edits win on conflict). */
  Wizard.prototype.applyDraft = function (draft) {
    var form = this.form;
    this.revision = draft.revision;
    var set = function (name, value) {
      var el = form.querySelector('[name="' + name + '"]');
      if (!el) {
        return;
      }
      if (el.type === 'checkbox') {
        el.checked = !!value;
      } else {
        el.value = value == null ? '' : value;
      }
    };
    var fields = draft.fields || {};
    set('selected_category', fields.selected_category);
    set('application_message', fields.application_message);
    set('setup_notes', fields.setup_notes);
    Object.keys(draft.answers || {}).forEach(function (qid) {
      set('answers[' + qid + ']', draft.answers[qid]);
    });
    Object.keys(draft.profile_overrides || {}).forEach(function (key) {
      set('overrides[' + key + ']', draft.profile_overrides[key]);
    });
    var ids = (draft.document_ids || []).map(String);
    Array.prototype.forEach.call(form.querySelectorAll('[name="document_ids[]"]'), function (el) {
      el.checked = ids.indexOf(el.value) !== -1;
    });
  };

  Wizard.prototype.setError = function (message, selector) {
    var el = this.form.querySelector(selector || '[data-wizard-step-error]') || this.form.querySelector('[data-wizard-error]');
    if (!el) {
      return;
    }
    el.textContent = message || '';
    el.hidden = !message;
  };

  Wizard.prototype.clearErrors = function () {
    Array.prototype.forEach.call(this.form.querySelectorAll('[data-wizard-step-error], [data-wizard-error], [data-field-error]'), function (el) {
      el.hidden = true;
      el.textContent = '';
    });
    Array.prototype.forEach.call(this.form.querySelectorAll('[aria-invalid="true"]'), function (el) {
      el.removeAttribute('aria-invalid');
    });
  };

  /** Inline validation for the controls inside one step; focuses the first invalid control. */
  Wizard.prototype.validateStep = function (step) {
    var section = this.form.querySelector('[data-wizard-step="' + step + '"]') || this.form;
    var first = null;
    Array.prototype.forEach.call(section.querySelectorAll('input, select, textarea'), function (el) {
      if (!el.willValidate || el.checkValidity()) {
        return;
      }
      el.setAttribute('aria-invalid', 'true');
      var row = el.closest('.lnkup-form-row');
      var msg = row ? row.querySelector('[data-field-error]') : null;
      if (msg) {
        msg.textContent = el.validationMessage || 'This field is required.';
        msg.hidden = false;
      }
      if (!first) {
        first = el;
      }
    });
    if (first) {
      this.setError('Please complete the highlighted fields.');
      first.focus();
      return false;
    }
    return true;
  };

  Wizard.prototype.showStep = function (step, focus) {
    var self = this;
    this.step = step;
    var index = this.steps.indexOf(step);
    if (!this.single) {
      Array.prototype.forEach.call(this.form.querySelectorAll('[data-wizard-step]'), function (section) {
        section.hidden = section.getAttribute('data-wizard-step') !== step;
      });
      Array.prototype.forEach.call(this.form.querySelectorAll('[data-wizard-progress-step]'), function (li) {
        var i = self.steps.indexOf(li.getAttribute('data-wizard-progress-step'));
        li.classList.toggle('is-done', i < index);
        li.classList.toggle('is-current', i === index);
        if (i === index) {
          li.setAttribute('aria-current', 'step');
        } else {
          li.removeAttribute('aria-current');
        }
      });
      var back = this.form.querySelector('[data-wizard-back]');
      var next = this.form.querySelector('[data-wizard-next]');
      var final = this.form.querySelector('[data-wizard-final]');
      var last = index === this.steps.length - 1;
      if (back) {
        back.hidden = index <= 0;
      }
      if (next) {
        next.hidden = last;
      }
      if (final) {
        final.hidden = !last;
      }
      if (step === 'review') {
        this.renderReview();
      }
    }
    if (focus) {
      var section = this.form.querySelector('[data-wizard-step="' + step + '"]');
      var target = section ? section.querySelector('input:not([type="hidden"]), select, textarea, h3') : null;
      if (target) {
        if (!target.hasAttribute('tabindex') && /^H\d$/.test(target.tagName)) {
          target.setAttribute('tabindex', '-1');
        }
        target.focus();
      }
    }
  };

  Wizard.prototype.go = function (delta) {
    this.clearErrors();
    var index = this.steps.indexOf(this.step);
    if (delta > 0 && !this.validateStep(this.step)) {
      return;
    }
    var nextIndex = Math.max(0, Math.min(this.steps.length - 1, index + delta));
    this.showStep(this.steps[nextIndex], true);
    this.save(false);
  };

  Wizard.prototype.loadQuote = function () {
    var box = this.form.querySelector('[data-wizard-quote]');
    if (!box || !base || !this.oppId) {
      return;
    }
    var cat = this.form.querySelector('[name="selected_category"]');
    var url = base + '/price-quote/' + this.oppId + (cat && cat.value ? '?category=' + encodeURIComponent(cat.value) : '');
    restJson(url).then(function (res) {
      box.innerHTML = '';
      if (!res.ok || !res.body.lines) {
        box.appendChild(textNode('p', 'Price estimate unavailable right now. You will see the exact total before paying.', 'lnkup-muted'));
        return;
      }
      box.appendChild(textNode('p', 'Estimated cost if approved', 'lnkup-apply-wizard__quote-title'));
      var dl = document.createElement('dl');
      dl.className = 'lnkup-apply-wizard__quote-lines';
      res.body.lines.forEach(function (line) {
        dl.appendChild(textNode('dt', line.label));
        dl.appendChild(textNode('dd', money(line.amount)));
      });
      dl.appendChild(textNode('dt', 'Total', 'is-total'));
      dl.appendChild(textNode('dd', money(res.body.total), 'is-total'));
      box.appendChild(dl);
      if (res.body.note) {
        box.appendChild(textNode('p', res.body.note, 'lnkup-muted'));
      }
    });
  };

  Wizard.prototype.renderReview = function () {
    var dl = this.form.querySelector('[data-wizard-review]');
    if (!dl) {
      return;
    }
    var form = this.form;
    dl.innerHTML = '';
    var add = function (label, value) {
      if (value === '' || value === null || value === undefined) {
        return;
      }
      dl.appendChild(textNode('dt', label));
      dl.appendChild(textNode('dd', String(value)));
    };
    var labelFor = function (el) {
      var row = el.closest('.lnkup-form-row');
      var lab = row ? row.querySelector('label') : null;
      return lab ? lab.textContent.replace(/\*|\(optional\)/g, '').trim() : el.name;
    };
    var cat = form.querySelector('[name="selected_category"]');
    add('Category', cat ? cat.value : '');
    Array.prototype.forEach.call(form.querySelectorAll('[name^="overrides["]'), function (el) {
      if (el.value !== (el.getAttribute('data-profile-value') || '')) {
        add(labelFor(el) + ' (this application)', el.value);
      }
    });
    Array.prototype.forEach.call(form.querySelectorAll('[name^="answers["]'), function (el) {
      if (el.type === 'checkbox') {
        add(labelFor(el), el.checked ? 'Yes' : 'No');
      } else if (el.tagName === 'SELECT') {
        add(labelFor(el), el.value ? el.options[el.selectedIndex].text : '');
      } else {
        add(labelFor(el), el.value);
      }
    });
    var msg = form.querySelector('[name="application_message"]');
    add('Message to organizer', msg ? msg.value : '');
    var setup = form.querySelector('[name="setup_notes"]');
    add('Setup notes', setup ? setup.value : '');
    var docs = form.querySelectorAll('[name="document_ids[]"]:checked');
    if (docs.length) {
      add('Documents shared', Array.prototype.map.call(docs, function (el) {
        return el.parentNode.textContent.trim();
      }).join(', '));
    }
    var quote = form.querySelector('.lnkup-apply-wizard__quote-lines .is-total + dd, dd.is-total');
    add('Estimated total if approved', quote ? quote.textContent : '');
  };

  /** "Also update my profile" fields go to the main profile before submit. */
  Wizard.prototype.updateProfile = function (payload) {
    var changes = {};
    Object.keys(payload._updateProfile || {}).forEach(function (key) {
      if (payload._updateProfile[key] && payload.profile_overrides[key] !== undefined) {
        changes[key] = payload.profile_overrides[key];
      }
    });
    if (!Object.keys(changes).length || !ajax.vendorProfileRestUrl) {
      return Promise.resolve(true);
    }
    return restJson(ajax.vendorProfileRestUrl, { method: 'POST', body: changes }).then(function (res) {
      return res.ok;
    });
  };

  Wizard.prototype.submit = function () {
    var self = this;
    var btn = this.form.querySelector('[data-wizard-submit]');
    if (this.submitting) {
      return;
    }
    this.clearErrors();
    for (var i = 0; i < this.steps.length; i++) {
      if (!this.validateStep(this.steps[i])) {
        this.showStep(this.steps[i], false);
        this.validateStep(this.steps[i]);
        return;
      }
    }
    var terms = this.form.querySelector('[name="accepted_terms"]');
    if (terms && !terms.checked) {
      this.setError('Please confirm you understand payment is only required if accepted.', '[data-wizard-error]');
      terms.focus();
      return;
    }
    this.submitting = true;
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    var payload = this.collect();
    var finish = function () {
      self.submitting = false;
      if (btn) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      }
    };
    this.dirty = true;
    this.save(false).then(function (saved) {
      if (!saved) {
        self.setError('We could not save your application. Check your connection and try again.', '[data-wizard-error]');
        return null;
      }
      return self.updateProfile(payload).then(function (profileOk) {
        if (!profileOk) {
          self.setError('Your profile was not updated, but your application will use the details you entered.', '[data-wizard-step-error]');
        }
        return restJson(base + '/drafts/' + self.oppId + '/submit', {
          method: 'POST',
          headers: { 'Idempotency-Key': self.submitKey },
          body: {
            accepted_terms: true,
            accept_current_price: self.acceptPrice,
            share_consent: !!(self.form.querySelector('[name="share_consent"]') || {}).checked,
            share_document_ids: Array.prototype.map.call(self.form.querySelectorAll('[name="share_document_ids[]"]:checked'), function (el) {
              return parseInt(el.value, 10);
            })
          }
        });
      });
    }).then(function (res) {
      if (!res) {
        return;
      }
      if (res.ok && res.body.success) {
        self.onSuccess(res.body);
        return;
      }
      var body = res.body || {};
      var data = body.data || {};
      if (body.code === 'lnkup_price_changed') {
        self.acceptPrice = true;
        self.loadQuote();
        self.setError(body.message + ' Press Submit again to apply at the new price.', '[data-wizard-error]');
        return;
      }
      if (body.code === 'duplicate') {
        self.onSuccess({ message: body.message });
        return;
      }
      if ((body.code === 'lnkup_answers_required' || body.code === 'lnkup_invalid_answer') && data.fields) {
        self.showStep(self.steps.indexOf('questions') !== -1 ? 'questions' : self.step, false);
        data.fields.forEach(function (qid) {
          var row = self.form.querySelector('[data-question-id="' + qid + '"]');
          var err = row ? row.querySelector('[data-field-error]') : null;
          if (err) {
            err.textContent = 'This answer is required.';
            err.hidden = false;
          }
        });
      }
      self.setError(body.message || 'Unable to submit application.', '[data-wizard-error]');
      if (body.code === 'lnkup_answers_required') {
        self.setError(body.message, '[data-wizard-step-error]');
      }
    }).finally(finish);
  };

  Wizard.prototype.onSuccess = function (body) {
    this.dirty = false;
    window.clearTimeout(this.timer);
    var modalBody = this.form.closest('.lnkup-apply-profile') || this.form.parentNode;
    var success = modalBody ? modalBody.querySelector('#lnkup-apply-success') : null;
    Array.prototype.forEach.call(modalBody.querySelectorAll('.lnkup-apply-wizard, .lnkup-apply-wizard__resume, .lnkup-apply-booths, .lnkup-apply-warnings'), function (el) {
      el.hidden = true;
    });
    if (success) {
      success.hidden = false;
      var heading = success.querySelector('p');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus();
      }
    }
    document.dispatchEvent(new CustomEvent('lnkup:application-submitted', { detail: { opportunityId: this.oppId, applicationId: body.application_id || 0 } }));
  };

  function init(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('form.lnkup-apply-wizard:not([data-wizard-ready])'), function (form) {
      form.setAttribute('data-wizard-ready', '1');
      form._lnkupWizard = new Wizard(form);
    });
  }

  /** Vendor dashboard: withdraw an unpaid application. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-withdraw-application]') : null;
    if (!btn) {
      return;
    }
    e.preventDefault();
    if (!window.confirm('Withdraw this application? The organizer will be notified and you can apply again later.')) {
      return;
    }
    var card = btn.closest('article') || btn.parentNode;
    var status = card ? card.querySelector('[data-withdraw-status]') : null;
    btn.disabled = true;
    restJson(btn.getAttribute('data-endpoint'), { method: 'POST', body: {} }).then(function (res) {
      if (status) {
        status.hidden = false;
        status.textContent = res.ok ? 'Application withdrawn.' : ((res.body && res.body.message) || 'Could not withdraw. Try again.');
      }
      if (res.ok) {
        btn.remove();
        var badge = card ? card.querySelector('.lnkup-status') : null;
        if (badge) {
          badge.textContent = res.body.status_label || 'Withdrawn';
          badge.className = 'lnkup-status lnkup-status--cancelled';
        }
      } else {
        btn.disabled = false;
      }
    });
  });

  document.addEventListener('DOMContentLoaded', function () {
    init(document);
  });
  var modalBody = document.getElementById('lnkup-apply-modal-body');
  var observeTarget = modalBody || document.body;
  if (window.MutationObserver && observeTarget) {
    new MutationObserver(function () {
      init(observeTarget);
    }).observe(observeTarget, { childList: true, subtree: true });
  }
  init(document);

  window.LNKupApplyWizard = { init: init, restJson: restJson, requestKey: requestKey, money: money };
})();
