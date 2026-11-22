/**
 * Multi-stop tour / series application: pick stops, share business details once,
 * answer stop-specific questions, submit with per-stop results and safe retry.
 * WordPress creates one independent application per stop.
 */
(function () {
  'use strict';

  var helpers = window.LNKupApplyWizard;
  if (!helpers) {
    return;
  }
  var restJson = helpers.restJson;
  var money = helpers.money;
  var SAVE_DELAY = 1500;

  var BLOCK_LABELS = {
    duplicate: 'Already applied',
    full: 'Full',
    deadline: 'Deadline passed',
    closed: 'Not accepting',
    invite_only: 'Invite only',
    no_profile: 'Create your profile first'
  };
  var RESULT_LABELS = {
    submitted: 'Submitted',
    already_submitted: 'Already submitted',
    already_applied: 'You already applied to this stop',
    closed: 'No longer accepting applications',
    full: 'Booth is full',
    ineligible: 'Not eligible',
    needs_input: 'Needs more information',
    error: 'Could not submit'
  };

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'className') {
        node.className = attrs[key];
      } else if (key === 'hidden') {
        node.hidden = !!attrs[key];
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    if (text !== undefined && text !== null) {
      node.textContent = text;
    }
    return node;
  }

  function dateLabel(iso) {
    if (!iso) {
      return 'Date TBA';
    }
    var d = new Date(iso + 'T12:00:00');
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function TourApply(container) {
    this.box = container;
    this.tourId = parseInt(container.getAttribute('data-tour-apply'), 10);
    this.base = (container.getAttribute('data-tours-rest-url') || '').replace(/\/$/, '') + '/' + this.tourId;
    this.groupId = null;
    this.revision = null;
    this.submitKey = helpers.requestKey();
    this.timer = null;
    this.opps = {};
  }

  TourApply.prototype.load = function () {
    var self = this;
    this.box.innerHTML = '';
    this.box.appendChild(el('p', { className: 'lnkup-muted', role: 'status' }, 'Loading tour stops…'));
    restJson(this.base + '/apply-context').then(function (res) {
      self.box.innerHTML = '';
      if (!res.ok) {
        self.box.appendChild(el('p', { className: 'lnkup-alert lnkup-alert--error', role: 'alert' }, (res.body && res.body.message) || 'We could not load this tour. Try again.'));
        var retry = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm' }, 'Try again');
        retry.addEventListener('click', function () {
          self.load();
        });
        self.box.appendChild(retry);
        return;
      }
      self.render(res.body);
    });
  };

  TourApply.prototype.render = function (data) {
    var self = this;
    var draft = data.draft || null;
    var saved = (draft && draft.payload) || {};
    this.documents = data.documents || [];
    this.groupId = (draft && draft.group_id) || helpers.requestKey();
    this.revision = draft ? draft.revision : null;
    var selected = {};
    (saved.selections || []).forEach(function (s) {
      selected[s.opportunity_id] = true;
    });
    var savedStops = saved.per_stop || {};
    var shared = saved.shared || {};

    var form = el('form', { className: 'lnkup-form lnkup-tour-apply__form', novalidate: '', 'data-testid': 'lnkup-tour-apply-form' });
    this.form = form;
    form.appendChild(el('p', { className: 'lnkup-apply-wizard__save', role: 'status', 'aria-live': 'polite', 'data-wizard-save-status': '' }));
    if (draft) {
      form.appendChild(el('p', { className: 'lnkup-alert lnkup-alert--info' }, 'Continue your application — your saved choices are filled in.'));
    }

    var stopsFs = el('fieldset', { className: 'lnkup-apply-wizard__attach' });
    stopsFs.appendChild(el('legend', {}, '1. Choose stops'));
    var list = el('ul', { className: 'lnkup-tour-apply__stops' });
    var anyOpen = false;
    (data.tour.stops || []).forEach(function (stop) {
      var li = el('li', { className: 'lnkup-tour-apply__stop', 'data-testid': 'lnkup-tour-apply-stop' });
      var head = el('div', { className: 'lnkup-tour-apply__stop-head' });
      head.appendChild(el('strong', {}, [stop.city, stop.state].filter(Boolean).join(', ') || stop.title));
      head.appendChild(el('span', { className: 'lnkup-muted' }, dateLabel(stop.date)));
      li.appendChild(head);
      var slots = el('div', { className: 'lnkup-tour-apply__slots' });
      var openSlots = 0;
      (stop.opportunities || []).forEach(function (opp) {
        self.opps[opp.opportunity_id] = { opp: opp, stop: stop };
        var label = el('label', { className: 'lnkup-checkbox' });
        var input = el('input', { type: 'radio', name: 'stop_' + stop.event_id, value: String(opp.opportunity_id), 'data-event-id': String(stop.event_id) });
        var total = opp.quote && typeof opp.quote.total === 'number' ? money(opp.quote.total) : money(opp.vendor_fee);
        var text = opp.title + ' · ' + total + ' est.';
        if (!opp.can_apply) {
          input.disabled = true;
          text += ' — ' + (BLOCK_LABELS[opp.block_reason] || 'Unavailable');
        } else {
          openSlots++;
          anyOpen = true;
        }
        if (selected[opp.opportunity_id] && opp.can_apply) {
          input.checked = true;
          li.classList.add('is-selected');
        }
        label.appendChild(input);
        label.appendChild(el('span', {}, text));
        slots.appendChild(label);
      });
      if (openSlots > 0) {
        var none = el('label', { className: 'lnkup-checkbox' });
        var skip = el('input', { type: 'radio', name: 'stop_' + stop.event_id, value: '' });
        if (!li.classList.contains('is-selected')) {
          skip.checked = true;
        }
        none.appendChild(skip);
        none.appendChild(el('span', { className: 'lnkup-muted' }, 'Skip this stop'));
        slots.appendChild(none);
      } else if (!(stop.opportunities || []).length) {
        slots.appendChild(el('p', { className: 'lnkup-muted' }, 'No vendor booths listed yet.'));
      }
      li.appendChild(slots);
      list.appendChild(li);
    });
    stopsFs.appendChild(list);
    form.appendChild(stopsFs);
    if (!anyOpen) {
      form.appendChild(el('p', { className: 'lnkup-alert lnkup-alert--info' }, 'No stops on this tour are accepting applications from you right now.'));
    }

    var sharedFs = el('fieldset', { className: 'lnkup-apply-wizard__attach' });
    sharedFs.appendChild(el('legend', {}, '2. Your business (shared by every stop)'));
    if (data.profile) {
      sharedFs.appendChild(el('p', {}, data.profile.business_name || ''));
      if (data.profile.products) {
        sharedFs.appendChild(el('p', { className: 'lnkup-muted' }, data.profile.products));
      }
    }
    var msgId = 'lnkup-tour-msg-' + this.tourId;
    sharedFs.appendChild(el('label', { for: msgId }, 'Message to the organizer (optional)'));
    var msg = el('textarea', { id: msgId, name: 'application_message', rows: '3', maxlength: '4000' });
    msg.value = shared.application_message || '';
    sharedFs.appendChild(msg);
    var setupId = 'lnkup-tour-setup-' + this.tourId;
    sharedFs.appendChild(el('label', { for: setupId }, 'Setup notes (optional)'));
    var setup = el('textarea', { id: setupId, name: 'setup_notes', rows: '2', maxlength: '2000' });
    setup.value = shared.setup_notes || '';
    sharedFs.appendChild(setup);
    form.appendChild(sharedFs);

    this.perStopFs = el('fieldset', { className: 'lnkup-apply-wizard__attach', 'data-testid': 'lnkup-tour-apply-per-stop' });
    form.appendChild(this.perStopFs);
    this.savedStops = savedStops;

    this.reviewBox = el('div', { className: 'lnkup-card lnkup-apply-wizard__quote', 'aria-live': 'polite' });
    form.appendChild(this.reviewBox);

    var termsLabel = el('label', { className: 'lnkup-checkbox' });
    termsLabel.appendChild(el('input', { type: 'checkbox', name: 'accepted_terms', value: '1' }));
    termsLabel.appendChild(el('span', {}, 'I understand I only pay for stops the organizer approves.'));
    form.appendChild(termsLabel);
    this.errorEl = el('p', { className: 'lnkup-alert lnkup-alert--error', role: 'alert', hidden: true });
    form.appendChild(this.errorEl);
    this.submitBtn = el('button', { type: 'submit', className: 'lnkup-btn lnkup-btn--primary', 'data-testid': 'lnkup-tour-apply-submit' }, 'Submit applications');
    form.appendChild(this.submitBtn);
    this.resultsBox = el('div', { 'aria-live': 'polite', 'data-testid': 'lnkup-tour-apply-results' });
    form.appendChild(this.resultsBox);

    form.addEventListener('change', function (e) {
      if (e.target.type === 'radio') {
        var stopLi = e.target.closest('.lnkup-tour-apply__stop');
        if (stopLi) {
          stopLi.classList.toggle('is-selected', !!e.target.value);
        }
        self.captureStops();
        self.renderPerStop();
      }
      self.refreshReview();
      self.queueSave();
    });
    form.addEventListener('input', function () {
      self.queueSave();
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      self.submit();
    });

    this.box.appendChild(form);
    this.renderPerStop();
    this.refreshReview();
  };

  TourApply.prototype.selections = function () {
    var out = [];
    Array.prototype.forEach.call(this.form.querySelectorAll('input[type="radio"]:checked'), function (input) {
      if (input.value) {
        out.push({ event_id: parseInt(input.getAttribute('data-event-id'), 10), opportunity_id: parseInt(input.value, 10) });
      }
    });
    return out;
  };

  /** Keep typed per-stop answers when stops are toggled. */
  TourApply.prototype.captureStops = function () {
    var stops = this.collectPerStop();
    var self = this;
    Object.keys(stops).forEach(function (id) {
      self.savedStops[id] = stops[id];
    });
  };

  TourApply.prototype.renderPerStop = function () {
    var self = this;
    var fs = this.perStopFs;
    fs.innerHTML = '';
    var needs = this.selections().filter(function (s) {
      var info = self.opps[s.opportunity_id];
      return info && ((info.opp.questions || []).length || (info.opp.categories || []).length > 1);
    });
    if (!needs.length) {
      fs.hidden = true;
      return;
    }
    fs.hidden = false;
    fs.appendChild(el('legend', {}, '3. Stop-specific details'));
    needs.forEach(function (s) {
      var info = self.opps[s.opportunity_id];
      var saved = self.savedStops[s.opportunity_id] || {};
      var group = el('div', { className: 'lnkup-card lnkup-apply-wizard__quote', 'data-per-stop': String(s.opportunity_id) });
      group.appendChild(el('p', { className: 'lnkup-apply-wizard__quote-title' }, [info.stop.city, dateLabel(info.stop.date), info.opp.title].filter(Boolean).join(' · ')));
      if ((info.opp.categories || []).length > 1) {
        var catId = 'lnkup-tour-cat-' + s.opportunity_id;
        group.appendChild(el('label', { for: catId }, 'Category'));
        var sel = el('select', { id: catId, 'data-stop-category': '' });
        info.opp.categories.forEach(function (c) {
          var o = el('option', { value: c }, c);
          if ((saved.selected_category || info.opp.default_category) === c) {
            o.selected = true;
          }
          sel.appendChild(o);
        });
        group.appendChild(sel);
      }
      (info.opp.questions || []).forEach(function (q) {
        var id = 'lnkup-tour-q-' + s.opportunity_id + '-' + q.id;
        var value = (saved.answers || {})[q.id];
        var wrap = el('div', { className: 'lnkup-form-row', 'data-question-id': q.id });
        var control;
        if (q.type === 'checkbox') {
          var lab = el('label', { className: 'lnkup-checkbox' });
          control = el('input', { id: id, type: 'checkbox', 'data-answer': q.id });
          control.checked = !!value;
          lab.appendChild(control);
          lab.appendChild(el('span', {}, q.label + (q.required ? ' *' : '')));
          wrap.appendChild(lab);
        } else {
          wrap.appendChild(el('label', { for: id }, q.label + (q.required ? ' *' : ' (optional)')));
          if (q.type === 'select') {
            control = el('select', { id: id, 'data-answer': q.id });
            control.appendChild(el('option', { value: '' }, 'Choose…'));
            (q.options || []).forEach(function (opt) {
              var o = el('option', { value: opt }, opt);
              if (value === opt) {
                o.selected = true;
              }
              control.appendChild(o);
            });
          } else if (q.type === 'textarea') {
            control = el('textarea', { id: id, rows: '3', maxlength: '4000', 'data-answer': q.id });
            control.value = value || '';
          } else if (q.type === 'file') {
            control = el('select', { id: id, 'data-answer': q.id });
            control.appendChild(el('option', { value: '' }, self.documents.length ? 'Choose one of your documents…' : 'Upload a document in your dashboard first'));
            self.documents.forEach(function (doc) {
              var o = el('option', { value: String(doc.id) }, doc.label);
              if (String(value) === String(doc.id)) {
                o.selected = true;
              }
              control.appendChild(o);
            });
          } else {
            control = el('input', { id: id, type: 'text', maxlength: '500', 'data-answer': q.id });
            control.value = value || '';
          }
          wrap.appendChild(control);
        }
        if (q.required) {
          control.required = true;
        }
        if (q.help) {
          wrap.appendChild(el('p', { className: 'lnkup-muted lnkup-form-help' }, q.help));
        }
        group.appendChild(wrap);
      });
      fs.appendChild(group);
    });
  };

  TourApply.prototype.collectPerStop = function () {
    var out = {};
    Array.prototype.forEach.call(this.form.querySelectorAll('[data-per-stop]'), function (group) {
      var answers = {};
      Array.prototype.forEach.call(group.querySelectorAll('[data-answer]'), function (c) {
        answers[c.getAttribute('data-answer')] = c.type === 'checkbox' ? c.checked : c.value;
      });
      var cat = group.querySelector('[data-stop-category]');
      out[group.getAttribute('data-per-stop')] = { answers: answers, selected_category: cat ? cat.value : '' };
    });
    var self = this;
    this.selections().forEach(function (s) {
      var info = self.opps[s.opportunity_id];
      if (!out[s.opportunity_id] && info) {
        out[s.opportunity_id] = { answers: {}, selected_category: info.opp.default_category || '' };
      }
    });
    return out;
  };

  TourApply.prototype.payload = function () {
    return {
      group_id: this.groupId,
      revision: this.revision,
      client: window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 'mobile_web' : 'web',
      selections: this.selections(),
      shared: {
        application_message: this.form.querySelector('[name="application_message"]').value,
        setup_notes: this.form.querySelector('[name="setup_notes"]').value
      },
      per_stop: this.collectPerStop()
    };
  };

  TourApply.prototype.refreshReview = function () {
    var self = this;
    var box = this.reviewBox;
    box.innerHTML = '';
    var sel = this.selections();
    if (!sel.length) {
      box.appendChild(el('p', { className: 'lnkup-muted' }, 'Choose at least one stop to see your estimated cost.'));
      return;
    }
    box.appendChild(el('p', { className: 'lnkup-apply-wizard__quote-title' }, sel.length + (sel.length === 1 ? ' stop selected' : ' stops selected')));
    var dl = el('dl', { className: 'lnkup-apply-wizard__quote-lines' });
    var sum = 0;
    sel.forEach(function (s) {
      var info = self.opps[s.opportunity_id];
      var total = info && info.opp.quote ? Number(info.opp.quote.total) || 0 : 0;
      sum += total;
      dl.appendChild(el('dt', {}, [info.stop.city, dateLabel(info.stop.date)].filter(Boolean).join(' · ')));
      dl.appendChild(el('dd', {}, money(total)));
    });
    dl.appendChild(el('dt', { className: 'is-total' }, 'If every stop approves you'));
    dl.appendChild(el('dd', { className: 'is-total' }, money(sum)));
    box.appendChild(dl);
    box.appendChild(el('p', { className: 'lnkup-muted' }, 'Estimates. Each stop is reviewed and paid separately — you only pay for stops that approve you, and see the exact amount before each payment.'));
  };

  TourApply.prototype.setStatus = function (text, state) {
    var s = this.form.querySelector('[data-wizard-save-status]');
    if (s) {
      s.textContent = text;
      s.setAttribute('data-state', state || '');
    }
  };

  TourApply.prototype.queueSave = function () {
    var self = this;
    this.setStatus('Unsaved changes', 'dirty');
    window.clearTimeout(this.timer);
    this.timer = window.setTimeout(function () {
      self.save();
    }, SAVE_DELAY);
  };

  TourApply.prototype.save = function () {
    var self = this;
    window.clearTimeout(this.timer);
    this.setStatus('Saving…', 'saving');
    return restJson(this.base + '/apply-draft', { method: 'PUT', body: this.payload() }).then(function (res) {
      if (res.ok && res.body.draft) {
        self.revision = res.body.draft.revision;
        self.setStatus('Saved', 'saved');
        return true;
      }
      if (res.status === 409 && res.body.data && res.body.data.draft) {
        self.revision = res.body.data.draft.revision;
        self.setStatus('Updated on another device — reloaded.', 'saved');
        self.load();
        return false;
      }
      self.setStatus('Save failed — your latest changes are not saved yet.', 'failed');
      return false;
    });
  };

  TourApply.prototype.showError = function (text) {
    this.errorEl.textContent = text || '';
    this.errorEl.hidden = !text;
  };

  TourApply.prototype.submit = function () {
    var self = this;
    if (this.submitting) {
      return;
    }
    this.showError('');
    if (!this.selections().length) {
      this.showError('Choose at least one stop.');
      return;
    }
    var invalid = this.form.querySelector('[data-per-stop] :invalid');
    if (invalid) {
      this.showError('Please complete the required questions for each stop.');
      invalid.focus();
      return;
    }
    var terms = this.form.querySelector('[name="accepted_terms"]');
    if (!terms.checked) {
      this.showError('Please confirm you understand you only pay for approved stops.');
      terms.focus();
      return;
    }
    this.submitting = true;
    this.submitBtn.disabled = true;
    this.submitBtn.setAttribute('aria-busy', 'true');
    var body = this.payload();
    body.accepted_terms = true;
    restJson(this.base + '/apply', { method: 'POST', headers: { 'Idempotency-Key': this.submitKey }, body: body }).then(function (res) {
      self.submitting = false;
      self.submitBtn.disabled = false;
      self.submitBtn.removeAttribute('aria-busy');
      if (!res.ok) {
        self.showError((res.body && res.body.message) || 'We could not submit your applications. Nothing was submitted twice — try again.');
        return;
      }
      self.renderResults(res.body);
    });
  };

  TourApply.prototype.renderResults = function (data) {
    var self = this;
    var box = this.resultsBox;
    box.innerHTML = '';
    var summary = data.summary || {};
    box.appendChild(el('h4', {}, summary.all_succeeded ? 'Applications submitted' : 'Some stops need attention'));
    var ul = el('ul', { className: 'lnkup-tour-apply__results' });
    (data.results || []).forEach(function (r) {
      var ok = r.result === 'submitted' || r.result === 'already_submitted';
      var li = el('li', { className: 'lnkup-tour-apply__result ' + (ok ? 'is-ok' : 'is-failed'), 'data-testid': 'lnkup-tour-apply-result', 'data-result': r.result });
      li.appendChild(el('span', {}, [r.city, dateLabel(r.date)].filter(Boolean).join(' · ') || r.event_name));
      li.appendChild(el('span', {}, (RESULT_LABELS[r.result] || r.result) + (!ok && r.message ? ' — ' + r.message : '')));
      ul.appendChild(li);
    });
    box.appendChild(ul);
    if (summary.all_succeeded) {
      box.appendChild(el('p', { className: 'lnkup-muted' }, 'Each organizer reviews every stop separately. Track them under Requests in your dashboard.'));
      this.submitBtn.hidden = true;
    } else if ((data.results || []).some(function (r) {
      return r.result === 'needs_input' || r.result === 'error';
    })) {
      this.submitBtn.textContent = 'Retry stops that need attention';
      box.appendChild(el('p', { className: 'lnkup-muted' }, 'Fix the stops marked above and retry. Stops already submitted will not be submitted again.'));
    }
    var heading = box.querySelector('h4');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.focus();
    }
    self.setStatus('', '');
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-tour-apply-open]') : null;
    if (!btn) {
      return;
    }
    var box = document.getElementById(btn.getAttribute('aria-controls'));
    if (!box) {
      return;
    }
    var open = box.hidden;
    box.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && !box._lnkupTour) {
      box._lnkupTour = new TourApply(box);
      box._lnkupTour.load();
    }
  });
})();
