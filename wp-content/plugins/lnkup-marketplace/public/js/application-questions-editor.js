/**
 * Organizer editor for per-slot application questions (lnkup/v1/opportunities/{id}/application-questions).
 */
(function () {
  'use strict';

  var ajax = window.lnkupAjax || {};
  var root = (ajax.applicationsRestUrl || '').replace(/\/applications\/?$/, '');
  var helpers = window.LNKupApplyWizard || {};
  var TYPES = [
    ['text', 'Short answer'],
    ['textarea', 'Long answer'],
    ['select', 'Multiple choice'],
    ['checkbox', 'Yes / no'],
    ['file', 'Document']
  ];

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'className') {
        node.className = attrs[key];
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function questionRow(q) {
    var row = el('li', { className: 'lnkup-qedit__row', 'data-question-row': '' });
    row.dataset.id = q.id || '';
    var label = el('input', { type: 'text', maxlength: '200', placeholder: 'Question', 'aria-label': 'Question', 'data-q-label': '' });
    label.value = q.label || '';
    var type = el('select', { 'aria-label': 'Answer type', 'data-q-type': '' });
    TYPES.forEach(function (t) {
      var opt = el('option', { value: t[0] }, t[1]);
      if ((q.type || 'text') === t[0]) {
        opt.selected = true;
      }
      type.appendChild(opt);
    });
    var reqWrap = el('label', { className: 'lnkup-checkbox' });
    var req = el('input', { type: 'checkbox', 'data-q-required': '' });
    req.checked = !!q.required;
    reqWrap.appendChild(req);
    reqWrap.appendChild(el('span', {}, 'Required'));
    var options = el('textarea', { rows: '2', placeholder: 'One choice per line', 'aria-label': 'Choices', 'data-q-options': '' });
    options.value = (q.options || []).join('\n');
    options.hidden = (q.type || 'text') !== 'select';
    type.addEventListener('change', function () {
      options.hidden = type.value !== 'select';
    });
    var remove = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost' }, 'Remove');
    remove.addEventListener('click', function () {
      row.remove();
    });
    [label, type, reqWrap, options, remove].forEach(function (node) {
      row.appendChild(node);
    });
    return row;
  }

  function collect(panel) {
    return Array.prototype.map.call(panel.querySelectorAll('[data-question-row]'), function (row) {
      return {
        id: row.dataset.id || '',
        label: row.querySelector('[data-q-label]').value.trim(),
        type: row.querySelector('[data-q-type]').value,
        required: row.querySelector('[data-q-required]').checked,
        options: row.querySelector('[data-q-options]').value.split('\n').map(function (s) {
          return s.trim();
        }).filter(Boolean)
      };
    }).filter(function (q) {
      return q.label !== '';
    });
  }

  function openEditor(btn) {
    var oppId = btn.getAttribute('data-edit-app-questions');
    var host = btn.closest('[data-slot-row]') || btn.parentNode;
    var existing = host.querySelector('[data-qedit-panel]');
    if (existing) {
      existing.remove();
      btn.setAttribute('aria-expanded', 'false');
      return;
    }
    btn.setAttribute('aria-expanded', 'true');
    var panel = el('div', { className: 'lnkup-qedit lnkup-card', 'data-qedit-panel': '', 'data-testid': 'lnkup-qedit-panel' });
    panel.appendChild(el('p', { className: 'lnkup-muted' }, 'Loading questions…'));
    host.appendChild(panel);

    helpers.restJson(root + '/opportunities/' + oppId + '/application-questions').then(function (res) {
      panel.innerHTML = '';
      if (!res.ok) {
        panel.appendChild(el('p', { className: 'lnkup-alert lnkup-alert--error' }, (res.body && res.body.message) || 'We could not load questions. Try again.'));
        return;
      }
      panel.appendChild(el('h4', {}, 'Application questions'));
      panel.appendChild(el('p', { className: 'lnkup-muted' }, 'Vendors answer these when they apply to this slot. Their profile details are filled in automatically, so only ask what is specific to this event.'));
      var list = el('ol', { className: 'lnkup-qedit__list' });
      (res.body.questions || []).forEach(function (q) {
        list.appendChild(questionRow(q));
      });
      var empty = el('p', { className: 'lnkup-muted' }, 'No questions yet. Vendors can apply with just their profile.');
      empty.hidden = list.children.length > 0;
      var add = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--outline' }, 'Add question');
      add.addEventListener('click', function () {
        empty.hidden = true;
        list.appendChild(questionRow({}));
        list.lastChild.querySelector('[data-q-label]').focus();
      });
      var gateWrap = el('label', { className: 'lnkup-checkbox' });
      var gate = el('input', { type: 'checkbox' });
      gate.checked = !!res.body.documents_required_to_apply;
      gateWrap.appendChild(gate);
      gateWrap.appendChild(el('span', {}, 'Require the listed documents before vendors can submit'));
      var status = el('p', { role: 'status', 'aria-live': 'polite', className: 'lnkup-muted' });
      var save = el('button', { type: 'button', className: 'lnkup-btn lnkup-btn--sm lnkup-btn--primary', 'data-testid': 'lnkup-qedit-save' }, 'Save questions');
      save.addEventListener('click', function () {
        save.disabled = true;
        status.textContent = 'Saving…';
        helpers.restJson(root + '/opportunities/' + oppId + '/application-questions', {
          method: 'PUT',
          body: { questions: collect(panel), documents_required_to_apply: gate.checked }
        }).then(function (saveRes) {
          save.disabled = false;
          if (saveRes.ok) {
            status.textContent = 'Saved.';
            list.innerHTML = '';
            (saveRes.body.questions || []).forEach(function (q) {
              list.appendChild(questionRow(q));
            });
            empty.hidden = list.children.length > 0;
          } else {
            status.textContent = (saveRes.body && saveRes.body.message) || 'Could not save. Try again.';
          }
        });
      });
      [list, empty, add, gateWrap, save, status].forEach(function (node) {
        panel.appendChild(node);
      });
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-edit-app-questions]') : null;
    if (!btn || !root || !helpers.restJson) {
      return;
    }
    e.preventDefault();
    openEditor(btn);
  });
})();
