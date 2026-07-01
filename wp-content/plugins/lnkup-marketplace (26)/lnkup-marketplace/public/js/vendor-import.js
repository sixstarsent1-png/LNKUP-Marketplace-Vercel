(function () {
  var ajax = window.lnkupAjax || {};
  var cfg = window.lnkupVendorImport || {};
  var base = ajax.crmRestUrl || '';
  var nonce = ajax.restNonce || '';

  var state = {
    step: 1,
    importId: 0,
    headers: [],
    sampleRows: [],
    mapping: {},
    preview: null,
    running: false,
    lists: [],
    inviteOptions: null
  };

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function toast(message, type) {
    if (window.lnkupShowToast) {
      window.lnkupShowToast(message, type || 'success');
    }
  }

  function importFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') >= 0) {
          return r.json().then(function (body) {
            if (!r.ok) {
              var err = new Error((body && body.message) || 'Request failed');
              err.body = body;
              throw err;
            }
            return body;
          });
        }
        if (!r.ok) {
          throw new Error('Request failed');
        }
        return r.text();
      });
  }

  function wizardEl() {
    return document.getElementById('lnkup-vendor-import-wizard');
  }

  function setStep(step) {
    state.step = step;
    var w = wizardEl();
    if (!w) {
      return;
    }
    w.querySelectorAll('[data-import-step]').forEach(function (pane) {
      var n = parseInt(pane.getAttribute('data-import-step'), 10);
      var active = n === step;
      pane.hidden = !active;
      pane.classList.toggle('is-active', active);
    });
    w.querySelectorAll('[data-import-step-indicator]').forEach(function (el) {
      var n = parseInt(el.getAttribute('data-import-step-indicator'), 10);
      el.classList.toggle('is-active', n === step);
      el.classList.toggle('is-done', n < step);
    });
    var backBtn = document.getElementById('lnkup-import-back');
    var nextBtn = document.getElementById('lnkup-import-next');
    if (backBtn) {
      backBtn.hidden = step <= 1 || step >= 4;
    }
    if (nextBtn) {
      nextBtn.hidden = step >= 4;
      nextBtn.disabled = step === 1 && !state.importId;
      nextBtn.textContent = step === 3 ? 'Run import' : 'Continue';
    }
  }

  function openWizard() {
    var w = wizardEl();
    if (!w) {
      return;
    }
    state.importId = 0;
    state.headers = [];
    state.mapping = {};
    state.preview = null;
    state.running = false;
    var status = document.getElementById('lnkup-import-upload-status');
    if (status) {
      status.hidden = true;
      status.textContent = '';
    }
    var fileInput = document.getElementById('lnkup-import-file');
    if (fileInput) {
      fileInput.value = '';
    }
    w.hidden = false;
    w.setAttribute('aria-hidden', 'false');
    document.body.classList.add('lnkup-import-open');
    setStep(1);
    loadListsAndEvents();
  }

  function closeWizard() {
    var w = wizardEl();
    if (!w) {
      return;
    }
    w.hidden = true;
    w.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('lnkup-import-open');
  }

  function loadListsAndEvents() {
    if (!base) {
      return;
    }
    importFetch('/lists').then(function (res) {
      state.lists = (res && res.items) || [];
      var sel = document.getElementById('lnkup-import-list-select');
      if (!sel) {
        return;
      }
      var html = '<option value="">None</option>';
      state.lists.forEach(function (list) {
        html += '<option value="' + list.id + '">' + escapeHtml(list.name) + '</option>';
      });
      sel.innerHTML = html;
    }).catch(function () {});

    importFetch('/events/invite-options').then(function (data) {
      state.inviteOptions = data;
      var eventSel = document.getElementById('lnkup-import-event');
      var oppSel = document.getElementById('lnkup-import-opportunity');
      if (!eventSel || !oppSel) {
        return;
      }
      var html = '<option value="">None</option>';
      (data.items || []).forEach(function (ev) {
        html += '<option value="' + ev.event_id + '">' + escapeHtml(ev.event_title) + '</option>';
      });
      eventSel.innerHTML = html;
      oppSel.innerHTML = '<option value="">None</option>';
      eventSel.onchange = function () {
        var evId = eventSel.value;
        var oppHtml = '<option value="">None</option>';
        (data.items || []).forEach(function (ev) {
          if (String(ev.event_id) === String(evId)) {
            (ev.opportunities || []).forEach(function (opp) {
              oppHtml += '<option value="' + opp.opportunity_id + '">' + escapeHtml(opp.title) + '</option>';
            });
          }
        });
        oppSel.innerHTML = oppHtml;
      };
    }).catch(function () {});
  }

  function handleFile(file) {
    if (!file || !base) {
      return;
    }
    if (cfg.maxBytes && file.size > cfg.maxBytes) {
      toast('File is too large. Maximum size is ' + Math.round(cfg.maxBytes / 1024 / 1024) + ' MB.', 'error');
      return;
    }
    if (!/\.csv$/i.test(file.name)) {
      toast('Only CSV files are supported.', 'error');
      return;
    }

    var status = document.getElementById('lnkup-import-upload-status');
    if (status) {
      status.hidden = false;
      status.textContent = 'Uploading…';
      status.className = 'lnkup-vendor-import__status is-loading';
    }

    var fd = new FormData();
    fd.append('file', file);

    importFetch('/import/upload', { method: 'POST', body: fd }).then(function (res) {
      state.importId = res.import_id;
      state.headers = res.headers || [];
      state.sampleRows = res.sample_rows || [];
      state.mapping = res.suggested_mapping || {};
      if (res.field_labels) {
        cfg.fieldLabels = res.field_labels;
      }
      if (status) {
        status.textContent = res.row_count + ' rows ready to map.';
        status.className = 'lnkup-vendor-import__status is-success';
      }
      renderMapping();
      setStep(1);
      var nextBtn = document.getElementById('lnkup-import-next');
      if (nextBtn) {
        nextBtn.disabled = false;
      }
      toast('CSV uploaded', 'success');
    }).catch(function (err) {
      if (status) {
        status.textContent = (err && err.message) || 'Upload failed';
        status.className = 'lnkup-vendor-import__status is-error';
      }
      toast((err && err.message) || 'Upload failed', 'error');
    });
  }

  function renderMapping() {
    var wrap = document.getElementById('lnkup-import-mapping');
    if (!wrap) {
      return;
    }
    var labels = cfg.fieldLabels || {};
    var fields = Object.keys(labels);
    if (!fields.length) {
      fields = ['business_name', 'contact_name', 'email', 'phone', 'vendor_category', 'city', 'state', 'notes', 'tags'];
    }

    var headerOpts = '<option value="ignore">Ignore</option>';
    state.headers.forEach(function (h, i) {
      headerOpts += '<option value="' + i + '">' + escapeHtml(h) + '</option>';
    });

    var html = '<div class="lnkup-vendor-import__mapping-grid">';
    fields.forEach(function (field) {
      var selected = state.mapping[field] != null ? String(state.mapping[field]) : 'ignore';
      html += '<label class="lnkup-vendor-import__map-row"><span>' + escapeHtml(labels[field] || field) + '</span>';
      html += '<select data-map-field="' + escapeHtml(field) + '">' + headerOpts + '</select></label>';
    });
    html += '</div>';

    if (state.sampleRows.length) {
      html += '<div class="lnkup-vendor-import__sample"><h3>Sample rows</h3><div class="lnkup-vendor-import__sample-scroll"><table><thead><tr>';
      state.headers.forEach(function (h) {
        html += '<th>' + escapeHtml(h) + '</th>';
      });
      html += '</tr></thead><tbody>';
      state.sampleRows.forEach(function (row) {
        html += '<tr>';
        (row || []).forEach(function (cell) {
          html += '<td>' + escapeHtml(cell) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div></div>';
    }

    wrap.innerHTML = html;
    wrap.querySelectorAll('select[data-map-field]').forEach(function (sel) {
      var field = sel.getAttribute('data-map-field');
      if (state.mapping[field] != null) {
        sel.value = String(state.mapping[field]);
      }
      sel.addEventListener('change', function () {
        var val = sel.value;
        if (val === 'ignore') {
          delete state.mapping[field];
        } else {
          state.mapping[field] = parseInt(val, 10);
        }
      });
    });
  }

  function collectMapping() {
    var mapping = {};
    var wrap = document.getElementById('lnkup-import-mapping');
    if (!wrap) {
      return mapping;
    }
    wrap.querySelectorAll('select[data-map-field]').forEach(function (sel) {
      var field = sel.getAttribute('data-map-field');
      if (sel.value !== 'ignore') {
        mapping[field] = parseInt(sel.value, 10);
      }
    });
    return mapping;
  }

  function renderPreviewSummary(data) {
    var wrap = document.getElementById('lnkup-import-summary');
    if (!wrap) {
      return;
    }
    var cards = [
      { label: 'Total rows', value: data.total },
      { label: 'Ready', value: data.ready },
      { label: 'Duplicates', value: data.duplicates },
      { label: 'Invalid', value: data.invalid },
      { label: 'Missing email', value: data.missing_email },
      { label: 'Existing users', value: data.existing_users },
      { label: 'Prospects', value: data.prospects }
    ];
    var html = '';
    cards.forEach(function (c) {
      html += '<div class="lnkup-vendor-import__summary-card"><span class="lnkup-vendor-import__summary-value">' + escapeHtml(String(c.value)) + '</span><span class="lnkup-vendor-import__summary-label">' + escapeHtml(c.label) + '</span></div>';
    });
    wrap.innerHTML = html;
  }

  function collectOptions() {
    var tagsRaw = (document.getElementById('lnkup-import-tags') || {}).value || '';
    var tags = tagsRaw.split(/[,;]/).map(function (t) { return t.trim(); }).filter(Boolean);
    return {
      duplicate_mode: (document.getElementById('lnkup-import-duplicate-mode') || {}).value || 'skip',
      list_id: parseInt((document.getElementById('lnkup-import-list-select') || {}).value, 10) || 0,
      new_list_name: ((document.getElementById('lnkup-import-new-list') || {}).value || '').trim(),
      tags: tags,
      event_id: parseInt((document.getElementById('lnkup-import-event') || {}).value, 10) || 0,
      opportunity_id: parseInt((document.getElementById('lnkup-import-opportunity') || {}).value, 10) || 0,
      send_invites: !!(document.getElementById('lnkup-import-send-invites') || {}).checked
    };
  }

  function runPreview() {
    var mapping = collectMapping();
    state.mapping = mapping;
    return importFetch('/import/preview', {
      method: 'POST',
      body: { import_id: state.importId, mapping: mapping, options: collectOptions() }
    }).then(function (data) {
      state.preview = data;
      renderPreviewSummary(data);
      return data;
    });
  }

  function runImport() {
    if (state.running) {
      return Promise.reject(new Error('Import in progress'));
    }
    var options = collectOptions();
    if (options.send_invites) {
      var confirmed = (document.getElementById('lnkup-import-confirm-invites') || {}).checked;
      if (!confirmed) {
        toast('Confirm invite sending before running import.', 'error');
        return Promise.reject(new Error('confirm_required'));
      }
      if (!cfg.allowInvite) {
        toast('Invites during import are disabled by the platform.', 'error');
        return Promise.reject(new Error('invites_disabled'));
      }
    }

    state.running = true;
    var nextBtn = document.getElementById('lnkup-import-next');
    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.textContent = 'Importing…';
    }

    return importFetch('/import/run', {
      method: 'POST',
      body: {
        import_id: state.importId,
        mapping: state.mapping,
        options: options,
        confirm_invites: options.send_invites
      }
    }).then(function (data) {
      state.running = false;
      renderResults(data);
      setStep(4);
      if (window.lnkupCrmLoadDirectory) {
        window.lnkupCrmLoadDirectory();
      }
      if (window.lnkupCrmLoadLists) {
        window.lnkupCrmLoadLists();
      }
      toast('Import complete', 'success');
      return data;
    }).catch(function (err) {
      state.running = false;
      if (nextBtn) {
        nextBtn.disabled = false;
        nextBtn.textContent = 'Run import';
      }
      toast((err && err.message) || 'Import failed', 'error');
      throw err;
    });
  }

  function renderResults(data) {
    var wrap = document.getElementById('lnkup-import-results');
    if (!wrap || !data || !data.summary) {
      return;
    }
    var s = data.summary;
    var html = '<div class="lnkup-vendor-import__results-grid">';
    html += resultCard('Imported', s.imported);
    html += resultCard('Updated', s.updated);
    html += resultCard('Skipped (duplicate)', s.skipped_duplicate);
    html += resultCard('Skipped (invalid)', s.skipped_invalid);
    html += resultCard('Existing users linked', s.existing_user_linked);
    html += resultCard('Invited', s.invited);
    html += resultCard('Invite failed', s.invite_failed);
    html += '</div>';
    if (data.campaign_id) {
      html += '<p class="lnkup-muted">Outreach campaign #' + escapeHtml(String(data.campaign_id)) + ' queued.</p>';
    }
    if (s.invite_error) {
      html += '<p class="lnkup-alert lnkup-alert--error">' + escapeHtml(s.invite_error) + '</p>';
    }
    wrap.innerHTML = html;
  }

  function resultCard(label, value) {
    return '<div class="lnkup-vendor-import__summary-card"><span class="lnkup-vendor-import__summary-value">' + escapeHtml(String(value || 0)) + '</span><span class="lnkup-vendor-import__summary-label">' + escapeHtml(label) + '</span></div>';
  }

  function bindEvents() {
    document.addEventListener('click', function (e) {
      var openBtn = e.target.closest('#lnkup-import-vendors-btn, [data-testid="lnkup-import-vendors-btn"]');
      if (openBtn) {
        e.preventDefault();
        openWizard();
      }
      if (e.target.closest('[data-import-close]') && !e.target.closest('#lnkup-import-view-imported')) {
        closeWizard();
      }
    });

    var viewImported = document.getElementById('lnkup-import-view-imported');
    if (viewImported) {
      viewImported.addEventListener('click', function () {
        closeWizard();
        document.querySelectorAll('[data-crm-scope]').forEach(function (b) {
          b.classList.toggle('is-active', b.getAttribute('data-crm-scope') === 'imported');
        });
        if (window.lnkupCrmSetScope) {
          window.lnkupCrmSetScope('imported');
        } else {
          var hashLink = document.querySelector('a[href="#vendors"]');
          if (hashLink) {
            hashLink.click();
          }
        }
        if (window.lnkupCrmLoadDirectory) {
          window.lnkupCrmLoadDirectory();
        }
      });
    }

    var templateLink = document.querySelector('[data-testid="lnkup-import-template-link"]');
    if (templateLink) {
      templateLink.addEventListener('click', function (e) {
        e.preventDefault();
        importFetch('/import/template').then(function (csv) {
          var blob = new Blob([csv], { type: 'text/csv' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = 'lnkup-vendor-import-template.csv';
          a.click();
          URL.revokeObjectURL(url);
        }).catch(function () {
          toast('Could not download template', 'error');
        });
      });
    }

    var fileInput = document.getElementById('lnkup-import-file');
    var dropzone = document.getElementById('lnkup-import-dropzone');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
          handleFile(fileInput.files[0]);
        }
      });
    }
    if (dropzone) {
      dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
      });
      dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('is-dragover');
      });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
          handleFile(e.dataTransfer.files[0]);
        }
      });
    }

    var sendInvites = document.getElementById('lnkup-import-send-invites');
    var confirmWrap = document.getElementById('lnkup-import-invite-confirm-wrap');
    if (sendInvites && confirmWrap) {
      sendInvites.addEventListener('change', function () {
        confirmWrap.hidden = !sendInvites.checked;
      });
    }

    var backBtn = document.getElementById('lnkup-import-back');
    if (backBtn) {
      backBtn.addEventListener('click', function () {
        if (state.step > 1) {
          setStep(state.step - 1);
        }
      });
    }

    var nextBtn = document.getElementById('lnkup-import-next');
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (state.step === 1) {
          if (!state.importId) {
            return;
          }
          renderMapping();
          setStep(2);
          return;
        }
        if (state.step === 2) {
          state.mapping = collectMapping();
          nextBtn.disabled = true;
          runPreview().then(function () {
            setStep(3);
          }).catch(function () {}).finally(function () {
            nextBtn.disabled = false;
          });
          return;
        }
        if (state.step === 3) {
          runImport();
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindEvents);
  } else {
    bindEvents();
  }
})();
